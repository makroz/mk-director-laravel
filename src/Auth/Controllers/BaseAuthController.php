<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Mk\Director\Auth\Attributes\Ability;
use Mk\Director\Auth\Enums\TwoFactorPolicy;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Auth\Services\AccountStatus;
use Mk\Director\Auth\Services\EmailOtpService;
use Mk\Director\Auth\Services\InvalidRefreshTokenException;
use Mk\Director\Auth\Services\OtpVerifyResult;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Auth\Services\TotpService;
use Mk\Director\Controllers\BaseController;

/**
 * BaseAuthController — controller abstract canónico para TODOS los scopes auth
 * de mk-director (Admin, Member, Custom, etc.).
 *
 * ============================================================
 *  Patrón R-PKG-038 + R-PKG-047 D1
 * ============================================================
 *
 * Antes de R-PKG-047, cada scope scaffoldeado por `mk:make:auth-user` emitía
 * un `AuthController` de ~500 LOC con copy-paste del 90% del código (login,
 * refresh, logout, me, forgot, reset, etc.). Bugs se fijaban en cada scope por
 * separado y el scaffolder pineaba placeholders condicionales (`{{rbacAudit*}}`,
 * `{{verifyEmailMethods}}`) que creaban N variantes distintas del mismo controller.
 *
 * R-PKG-047 colapsa este código duplicado en UNA clase abstract canónica
 * (este archivo). Cada scope scaffoldeado ahora extiende BaseAuthController y
 * solo override los 4 abstract methods + opcionalmente hooks — total ~30 LOC.
 *
 * Decisiones arquitectónicas pineadas en R-PKG-047 D1..D8:
 *   - D1: BaseAuthController canónico abstract (este archivo) + AuthController
 *         scaffoldeado per-scope = @deprecated thin wrapper (~30 LOC).
 *   - D2: Scaffolder 6 flags → 3 (login-field obligatorio + profile-fields +
 *         --verify-email opt-in). CRUD/RBAC/Status default ON.
 *   - D3: Auto-wire FileStoragePlugin via suffix :file en --profile-fields.
 *   - D4: ScopeStatus enum reemplaza is_active boolean (Active/Inactive/
 *         Suspended/Pending).
 *   - D5: login-field pineado en 9 artifacts (LoginRequest, MeRequest,
 *         StoreRequest, UpdateRequest, Resource, Data, FilterData, Migration).
 *   - D6: 30 tests source-parsing + e2e SQLite in-memory (per HALLAZGO-NEW-03).
 *   - D7: Triple code review (auto judgment-day + self-review + Mario sign-off).
 *   - D8: R-G-032 sync 16+ locations (código en paquete, docs+skills+CHANGELOG
 *         en monorepo+agency cross-stack).
 *
 * ============================================================
 *  Contrato para subclases (lo que cada scope override)
 * ============================================================
 *
 * # Abstract methods (4) — pineá en la subclase:
 *
 *   protected function authModelClass(): string;       // FQCN del modelo del scope
 *   protected function authScope(): string;            // 'admin', 'member', 'tenant', etc.
 *   protected function loginField(): string;           // 'email', 'ci', 'phone', etc.
 *   protected function passwordResetTable(): string;   // 'admin_password_reset_tokens'
 *
 * # Hook methods (5) — opcionalmente override en la subclase:
 *
 *   protected function beforeLogin(Request $r, array $c): ?array
 *       Modifica las credentials antes del lookup. Si retorna `null`, rechaza el login.
 *       Default: retorna `$credentials` sin modificar.
 *
 *   protected function afterLogin(Request $r, Authenticatable $u, array $t): void
 *       Hook post-emisión de tokens (e.g. emitir audit event, analytics, etc.).
 *       Default: no-op.
 *
 *   protected function customizeLoginValidationRules(): array
 *       Reglas adicionales para el endpoint /login (e.g. `['ci' => 'regex:/^[0-9]+$/']`).
 *       Default: [].
 *
 *   protected function customizeMePayload(Authenticatable $u): array
 *       Campos adicionales a mergear dentro de `data` del /me response.
 *       Default: [].
 *
 *   protected function shouldSendResetNotification(Authenticatable $u, string $token): bool
 *       Gate pre-emisión de email de reset (test mode lo desactiva típicamente).
 *       Default: true.
 *
 * ============================================================
 *  Compat / BC notes
 * ============================================================
 *
 * - **Envelope canónico R-PKG-024**: respuestas siguen
 *   `{success, message, data, debugMsg}` vía `BaseController::sendResponse()`.
 *
 * - **Errores canónicos R-PKG-044**: errores siguen envelope single-level con
 *   `__extraData.code` (e.g. `ERR_UNAUTHENTICATED`, `ERR_VALIDATION`) vía
 *   `BaseController::sendError()`.
 *
 * - **Sanctum v4 token parsing** (R-PKG-014 BUG-07 + R-PKG-018 BUG-NEW-26):
 *   refresh tokens tienen formato `<id>|<plaintext>`; TokenIssuer hashea solo
 *   el plaintext con SHA256 (NO bcrypt). `rotateRefreshToken()` valida scope.
 *
 * - **Anti scope-mismatch** (R-PKG-046 F9-B11): el middleware `mk.auth:{scope}`
 *   ya valida el scope; este controller como defense-in-depth re-chequea
 *   `$user->getAuthScope() === $this->authScope()` para no emitir tokens a
 *   user de otro scope.
 *
 * - **Capacidades (R-PKG-007 + R-PKG-045 + R-PKG-046 FASE18-C)**: cada método
 *   público declara `#[Ability('{scope}.auth.{action}')]`. El command
 *   `mk:discover-abilities` los recolecta y pre-bumpear `--force` post-merge
 *   los pineá en `config/mk_director.abilities`. Las rutas per-endpoint pinean
 *   `mk.ability:{scope}.auth.{action}` per-route (HALLAZGO-NEW-FASE18-C fix).
 *
 * - **Per-route middleware** (R-PKG-046 F9-B10): el constructor queda vacío.
 *   Las rutas pinean `mk.auth:{scope}` + `mk.ability:{scope}.auth.{action}`
 *   per-endpoint (ver D2 R-PKG-047 + HALLAZGO-NEW-FASE18-C). No pinear
 *   `mk.auth:{scope}` en el constructor para evitar doble-auth cuando el
 *   controller es invocado desde un managed-routes provider.
 *
 * - **`is_active` deprecated** (R-PKG-047 D4): pre-D4 `is_active` boolean se
 *   se chequeaba via `Schema::hasColumn`. Post-D4 se prefiere `ScopeStatus` enum.
 *   `userHasValidStatus()` delega en `AccountStatus` (la misma regla que
 *   aplican `mk.auth` y el refresh); sin enum ni `is_active`, deja pasar
 *   (BC para scopes que aún no migraron).
 *
 * - **No BC bridge for AuthController scaffoldeado**: el scaffolder emite
 *   thin wrappers (~30 LOC) por scope nuevo. Consumers pre-R-PKG-047 con
 *   AuthControllers ~500 LOC siguen funcionando sin cambios (los métodos
 *   heredan comportamiento idéntico). Solo NO pinean hooks nuevos.
 */
abstract class BaseAuthController extends BaseController
{
    // ============================================================
    //  4 Abstract methods — cada subclase DEBE implementar
    // ============================================================

    /**
     * FQCN del modelo del scope (e.g. `App\Modules\Admin\Models\Admin`).
     *
     * Constraint: la clase DEBE extender `Mk\Director\Auth\Models\AuthUser`
     * para que `getAuthScope()`, `getLoginField()`, `safeLogoutCurrentToken()`
     * estén disponibles.
     */
    abstract protected function authModelClass(): string;

    /**
     * Scope string para auth + middleware (e.g. 'admin', 'member', 'tenant').
     *
     * Usado por:
     *   - Capability check `#[Ability('{scope}.auth.{action}')]`.
     *   - Defense-in-depth scope validation en login/refresh/forgot/reset.
     *   - Anti scope-mismatch lookup (`$user->getAuthScope() === $scope`).
     *   - TokenIssuer::rotateRefreshToken() (segundo param `$expectedScope`).
     */
    abstract protected function authScope(): string;

    /**
     * Campo usado para login (e.g. 'email', 'ci', 'phone', 'documento').
     *
     * Default expectation: subclases con `email` se validan con `email` rule;
     * subclases con otros campos se validan con `string` rule (consumer puede
     * override via LoginRequest generado o vía `customizeLoginValidationRules`).
     */
    abstract protected function loginField(): string;

    /**
     * Tabla donde se persisten los tokens de password reset del scope
     * (e.g. 'admin_password_reset_tokens', 'member_password_reset_tokens').
     *
     * Convention: `{moduleNameLower}_password_reset_tokens`. La migration
     * scaffoldeada crea esta tabla con columnas `{loginField}` (UNIQUE),
     * `token` (bcrypt-hash), `created_at`. El scope la referencia vía
     * DB facade — `Schema::hasTable()` runtime check pineable.
     */
    abstract protected function passwordResetTable(): string;

    // ============================================================
    //  5 Hook methods — opcionalmente override en subclase
    // ============================================================

    /**
     * Modifica credentials antes del lookup en login().
     *
     * Casos de uso típicos:
     *   - Trim + lowercase del loginField (`trim(strtolower($c['email']))`)
     *   - Resolver username alias (`$c['username'] = $c['login'] ?? null`)
     *   - Aplicar normalización custom (e.g. E.164 para phone)
     *
     * Retorna el array `$credentials` modificado (las keys DEBEN matchear
     * el `loginField()`). Si retorna `null`, se rechaza el login con
     * 422 sin emitir audit event (failure pre-lookup).
     */
    protected function beforeLogin(Request $request, array $credentials): ?array
    {
        return $credentials;
    }

    /**
     * Hook post-emisión de tokens en login() exitoso.
     *
     * `$tokens` shape: `['access_token' => string, 'refresh_token' => string,
     *                       'user_id' => string, 'expires_in' => int]`.
     *
     * NO toca el response (ya enviado). Útil para:
     *   - Emitir audit event custom (`AuthEvent::dispatch(...)`)
     *   - Invalidate session cache pre-existente
     *   - Notificar al consumer downstream (websocket, analytics)
     */
    protected function afterLogin(Request $request, Authenticatable $user, array $tokens): void
    {
        // no-op default
    }

    /**
     * Validation rules adicionales para /login. Se mergean con el rule
     * base del loginField (string o email según tipo).
     *
     * Ejemplo override en subclase:
     *   protected function customizeLoginValidationRules(): array {
     *       return ['ci' => ['regex:/^[0-9]{6,10}$/']];  // Bolivia CI: 6-10 dígitos
     *   }
     */
    protected function customizeLoginValidationRules(): array
    {
        return [];
    }

    /**
     * Campos adicionales a mergear dentro de `data` del response /me.
     *
     * El scaffold default retorna:
     *   data: {
     *     id, name, loginField, profileFields...,  // BaseAuthController::me() default
     *     abilities: [...],                        // getEffectiveAbilities() (F7-B02)
     *     ...customizeMePayload($user),            // hook
     *   }
     *
     * Ejemplo override en subclase:
     *   protected function customizeMePayload(Authenticatable $user): array {
     *       return ['tenant_id' => $user->tenant_id ?? null];
     *   }
     */
    protected function customizeMePayload(Authenticatable $user): array
    {
        return [];
    }

    /**
     * Gate pre-emisión de email de reset en forgotPassword(). Default true.
     *
     * Útil para tests: el consumer puede override para return false e
     * inspeccionar el token generado sin enviar email real.
     *
     * El token se persiste SIEMPRE en `passwordResetTable()` antes de
     * llamar este hook — el hook controla SOLO si se emite el evento
     * `auth.password_reset.requested` que el listener de email consume.
     */
    protected function shouldSendResetNotification(Authenticatable $user, string $token): bool
    {
        return true;
    }

    // ============================================================
    //  10 Métodos públicos — los endpoints reales
    // ============================================================

    /**
     * POST /api/{scope}/auth/login
     *
     * Body: `{loginField: <value>, password: <string>}`.
     *
     * Auth: público (sin middleware mk.auth).
     * Ability: `mk.ability:{scope}.auth.login` (per-route, HALLAZGO-NEW-FASE18-C).
     *
     * Pipeline:
     *   1. Validate loginField + password (con `customizeLoginValidationRules`).
     *   2. `beforeLogin()` hook — modificar credentials / abort.
     *   3. User lookup por `where(loginField, value).first()`.
     *   4. Defense-in-depth: `getAuthScope() === authScope()`.
     *   5. `userHasValidStatus()` check (enum ScopeStatus o `is_active` legacy).
     *   6. `Hash::check(password, user->password)`.
     *   7. TokenIssuer::issueTokenPair (access ligado a su refresh, para que logout revoque ambos).
     *   8. `afterLogin()` hook.
     *   9. Return envelope canónico con tokens + user payload (incl. abilities, F7-B02).
     */
    #[Ability('{scope}.auth.login', 'Iniciar sesión en el scope {scope}')]
    public function login(Request $request): JsonResponse
    {
        $loginField = $this->loginField();
        $scope = $this->authScope();

        $baseRules = [
            $loginField => $loginField === 'email'
                ? ['required', 'email', 'max:255']
                : ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];

        $credentials = $request->validate(array_merge($baseRules, $this->customizeLoginValidationRules()));

        $credentials = $this->beforeLogin($request, $credentials);
        if ($credentials === null) {
            return $this->sendError('Credenciales inválidas.', [$loginField => []], 422, 'ERR_VALIDATION');
        }

        $modelClass = $this->authModelClass();

        /** @var Authenticatable|null $user */
        $user = $modelClass::query()
            ->where($loginField, $credentials[$loginField])
            ->first();

        // Defense-in-depth: user existe, scope coincide, status válido, password OK.
        if (! $user
            || $user->getAuthScope() !== $scope
            || ! $this->userHasValidStatus($user)
            || ! Hash::check($credentials['password'], (string) $user->getAuthPassword())
        ) {
            $this->dispatchAuthEventSafe('auth.login.failed', [
                'scope' => $scope,
                'login_field_value' => $credentials[$loginField] ?? null,
                'ip' => $request->ip(),
            ]);

            return $this->sendError(
                'Credenciales inválidas.',
                [$loginField => ['Credenciales inválidas.']],
                422,
                'ERR_VALIDATION',
            );
        }

        // 🔴 EL ÚNICO PUNTO entre «las credenciales son buenas» y «se emiten
        // tokens». Antes no existía: cualquier segunda prueba de identidad había
        // que cablearla reescribiendo `login()` entero en cada scope, y un scope
        // que se olvidara de hacerlo no daba ninguna señal.
        //
        // Devuelve null cuando el scope no pide segundo factor (la política
        // default, `off`): el login sigue exactamente como antes.
        if ($gate = $this->twoFactorGate($request, $user)) {
            return $gate;
        }

        return $this->issueSessionResponse($request, $user);
    }

    /**
     * Emite la sesión (par de tokens) y arma el sobre del login.
     *
     * Vive aparte porque hay DOS caminos que terminan en la misma sesión: el
     * login directo y el `two-factor/challenge`. Duplicar esto dejaría al
     * segundo sin `afterLogin()`, sin el evento de auditoría o con otra forma de
     * respuesta — y un front que funciona sin 2FA y se rompe con 2FA prendido no
     * apunta a ninguna de las dos.
     *
     * @param  array<string, mixed>  $extraPayload  campos extra dentro de `data`
     * @param  array<string, mixed>  $extraData  campos para `__extraData`
     */
    protected function issueSessionResponse(
        Request $request,
        Authenticatable $user,
        string $message = 'Login exitoso',
        array $extraPayload = [],
        array $extraData = [],
    ): JsonResponse {
        $scope = $this->authScope();

        // Eager-load relaciones (R-PKG-014 BUG-06 fix).
        if (method_exists($user, 'loadMissing')) {
            $user->loadMissing(['roles', 'directAbilities']);
        }

        ['access' => $accessToken, 'refresh' => $refreshToken] = $this->tokenIssuer()->issueTokenPair($user);

        $expiresIn = (int) config('mk_director.auth.ttl.access_seconds', 15 * 60);

        $tokens = [
            'access_token' => $accessToken->plainTextToken,
            'refresh_token' => $refreshToken,
            'user_id' => (string) $user->getAuthIdentifier(),
            'expires_in' => $expiresIn,
        ];

        $this->dispatchAuthEventSafe('auth.login.success', [
            'scope' => $scope,
            'user_id' => $tokens['user_id'],
            'ip' => $request->ip(),
        ]);

        $this->afterLogin($request, $user, $tokens);

        // PKG-NEW-15 fix (RETO fase 10b 2026-06-28): envelope canónico con
        // Resource scaffoldeado via autoTransform(). F7-B02 pinea abilities
        // flat top-level para parity con useMkAuth().hasAbility().
        $userPayload = $user->toArray();
        $userPayload['abilities'] = method_exists($user, 'getEffectiveAbilities')
            ? $user->getEffectiveAbilities()
            : [];

        return $this->sendResponse(array_merge([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            $scope => $userPayload,
        ], $extraPayload), $message, 200, $extraData);
    }

    /**
     * POST /api/{scope}/auth/refresh
     *
     * Body: `{refresh_token: <id|plaintext>}`.
     *
     * Auth: público (sin middleware mk.auth).
     * Ability: `mk.ability:{scope}.auth.refresh` (per-route).
     *
     * Sanctum v4 parsing + SHA256 hash (R-PKG-014 BUG-07 + R-PKG-018 BUG-NEW-26).
     * Anti scope-escalation: TokenIssuer::rotateRefreshToken($token, $scope).
     *
     * 401 `ERR_UNAUTHENTICATED` si el token no es un refresh token (un access
     * token no refresca) o es inválido; 401 `ERR_ACCOUNT_DISABLED` si la
     * cuenta ya no puede autenticarse (el refresh token queda revocado).
     */
    #[Ability('{scope}.auth.refresh', 'Rotar refresh token en el scope {scope}')]
    public function refresh(Request $request): JsonResponse
    {
        $scope = $this->authScope();

        $request->validate([
            'refresh_token' => ['required', 'string'],
        ]);

        $refreshToken = (string) $request->input('refresh_token');

        try {
            $result = $this->tokenIssuer()->rotateRefreshToken($refreshToken, $scope);
        } catch (InvalidRefreshTokenException $e) {
            // R-PKG-018 BUG-NEW-27 fix: capturar la excepción específica del
            // paquete ANTES del catch genérico para devolver el mensaje
            // detallado en vez del genérico.
            $this->dispatchAuthEventSafe('auth.refresh.failed', [
                'scope' => $scope,
                'ip' => $request->ip(),
                'reason' => $e->getMessage(),
            ]);

            return $this->sendError($e->getMessage(), [], 401, $e->errorCode);
        }

        $this->dispatchAuthEventSafe('auth.refresh.success', [
            'scope' => $scope,
            'user_id' => $result['user_id'] ?? null,
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse([
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => (int) config('mk_director.auth.ttl.access_seconds', 15 * 60),
        ], 'Refresh exitoso');
    }

    /**
     * GET /api/{scope}/auth/me
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.me` (per-route).
     *
     * F7-B02: pinea `abilities` flat top-level para parity cross-stack.
     * F2 (R-PKG-014 BUG-06): eager-load `roles` + `directAbilities` antes
     * del sendResponse para que `autoTransform()` los serialice correctamente.
     */
    #[Ability('{scope}.auth.me', 'Ver perfil autenticado en el scope {scope}')]
    public function me(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        if (method_exists($user, 'loadMissing')) {
            $user->loadMissing(['roles', 'directAbilities']);
        }

        $payload = $user->toArray();
        $payload['abilities'] = method_exists($user, 'getEffectiveAbilities')
            ? $user->getEffectiveAbilities()
            : [];

        // Estado del segundo factor, con los nombres que consume el front. El
        // secreto y los códigos de recuperación NUNCA salen: están en `$hidden`
        // del modelo base, así que `toArray()` ya no los trae, y acá se agregan
        // sólo estas dos claves. Un scope sin las columnas informa `false`/`null`,
        // que es la verdad.
        $payload['two_factor_enabled'] = $this->userHasConfirmedTwoFactor($user);
        $payload['two_factor_confirmed_at'] = $this->twoFactorConfirmedAtIso($user);

        $customized = $this->customizeMePayload($user);
        if (is_array($customized) && $customized !== []) {
            $payload = array_merge($payload, $customized);
        }

        return $this->sendResponse($payload);
    }

    /**
     * POST /api/{scope}/auth/logout
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.logout` (per-route).
     *
     * Revoca el access token actual y el refresh token de ESA sesión
     * (`safeLogoutCurrentToken()` con null-safety, R-PKG-027 PKG-NEW-08 +
     * R-PKG-014 BUG-01). Las otras sesiones del user siguen vivas.
     */
    #[Ability('{scope}.auth.logout', 'Cerrar sesión actual en el scope {scope}')]
    public function logout(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $revoked = method_exists($user, 'safeLogoutCurrentToken')
            ? $user->safeLogoutCurrentToken()
            : false;

        $this->dispatchAuthEventSafe('auth.logout', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'token_revoked' => $revoked,
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Sesión cerrada.');
    }

    /**
     * POST /api/{scope}/auth/logout-all
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.logout-all` (per-route).
     *
     * Revoca TODOS los tokens del user via `$user->tokens()->delete()`.
     * Defense-in-depth post password reset + uso multi-device.
     */
    #[Ability('{scope}.auth.logout-all', 'Cerrar todas las sesiones del usuario en {scope}')]
    public function logoutAll(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        // `tokens()` viene del trait HasApiTokens (Sanctum).
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        $this->dispatchAuthEventSafe('auth.logout_all', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Todas las sesiones cerradas.');
    }

    /**
     * POST /api/{scope}/auth/password/forgot
     *
     * Auth: público.
     * Ability: `mk.ability:{scope}.auth.password-forgot` (per-route).
     *
     * Anti-enumeración: si el user no existe o no es válido, retorna OK 200
     * sin hacer nada (R-PKG-027 PKG-NEW-05).
     *
     * Pipeline:
     *   1. Validate loginField.
     *   2. Lookup user.
     *   3. Validar status (ScopeStatus o `is_active` legacy).
     *   4. Generar token (Sanctum v4 formato: random 64-char string).
     *   5. Persistir en `passwordResetTable()` con `updateOrInsert`.
     *   6. Emitir evento `auth.password_reset.requested` (consumer-side
     *      listener registra el canal de notificación: email, SMS, etc.).
     */
    #[Ability('{scope}.auth.password-forgot', 'Solicitar reset de contraseña en {scope}')]
    public function forgotPassword(Request $request): JsonResponse
    {
        $loginField = $this->loginField();
        $scope = $this->authScope();
        $table = $this->passwordResetTable();

        $credentials = $request->validate([
            $loginField => $loginField === 'email'
                ? ['required', 'email', 'max:255']
                : ['required', 'string', 'max:255'],
        ]);

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()
            ->where($loginField, $credentials[$loginField])
            ->first();

        // Anti-enumeración: si no existe o no es válido, retornar OK sin hacer nada.
        if (! $user
            || $user->getAuthScope() !== $scope
            || ! $this->userHasValidStatus($user)
        ) {
            return $this->sendResponse(
                null,
                "Si el {$loginField} existe, recibirás un enlace para reset.",
            );
        }

        // Genera token (Sanctum v4 formato: random 64-char string).
        $token = bin2hex(random_bytes(32));

        if (Schema::hasTable($table)) {
            DB::table($table)->updateOrInsert(
                [$loginField => $credentials[$loginField]],
                [
                    'token' => Hash::make($token),
                    'created_at' => now(),
                ],
            );
        }

        if ($this->shouldSendResetNotification($user, $token)) {
            $this->dispatchAuthEventSafe('auth.password_reset.requested', [
                'scope' => $scope,
                'user_id' => (string) $user->getAuthIdentifier(),
                'token' => $token,  // plaintext para que el listener lo envíe al canal
                'ip' => $request->ip(),
            ]);
        }

        return $this->sendResponse(
            null,
            "Si el {$loginField} existe, recibirás un enlace para reset.",
        );
    }

    /**
     * POST /api/{scope}/auth/password/reset
     *
     * Auth: público.
     * Ability: `mk.ability:{scope}.auth.password-reset` (per-route).
     *
     * Pipeline:
     *   1. Validate loginField + token + password (confirmed, min 8).
     *   2. Lookup record en `passwordResetTable()`.
     *   3. Validar token (Hash::check) + exp (60 min default).
     *   4. Lookup user + validar status + scope.
     *   5. Transaction: update password + delete all tokens + delete record.
     *   6. Emitir evento `auth.password_reset.success`.
     */
    #[Ability('{scope}.auth.password-reset', 'Aplicar reset de contraseña con token en {scope}')]
    public function resetPassword(Request $request): JsonResponse
    {
        $loginField = $this->loginField();
        $scope = $this->authScope();
        $table = $this->passwordResetTable();
        $tokenTtlSeconds = (int) config('mk_director.auth.password_reset.ttl_seconds', 3600);

        $data = $request->validate([
            $loginField => $loginField === 'email'
                ? ['required', 'email', 'max:255']
                : ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! Schema::hasTable($table)) {
            return $this->sendError('Token inválido o expirado.', [], 422, 'ERR_VALIDATION');
        }

        $record = DB::table($table)
            ->where($loginField, $data[$loginField])
            ->first();

        if (! $record
            || ! Hash::check($data['token'], $record->token)
            || (time() - strtotime((string) $record->created_at)) > $tokenTtlSeconds
        ) {
            return $this->sendError('Token inválido o expirado.', [], 422, 'ERR_VALIDATION');
        }

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()
            ->where($loginField, $data[$loginField])
            ->first();

        if (! $user
            || $user->getAuthScope() !== $scope
            || ! $this->userHasValidStatus($user)
        ) {
            return $this->sendError('Token inválido o expirado.', [], 422, 'ERR_VALIDATION');
        }

        DB::transaction(function () use ($user, $loginField, $data, $table) {
            $user->setAuthPassword((string) $data['password']);

            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }

            DB::table($table)
                ->where($loginField, $data[$loginField])
                ->delete();
        });

        $this->dispatchAuthEventSafe('auth.password_reset.success', [
            'scope' => $scope,
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Contraseña actualizada.');
    }

    /**
     * POST /api/{scope}/auth/password/change
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.password-change` (per-route).
     *
     * Cambia la contraseña del user autenticado verificando la contraseña actual
     * + opcionalmente invalida todos los tokens (config `password_change.revoke_other_sessions`,
     * default true).
     */
    #[Ability('{scope}.auth.password-change', 'Cambiar contraseña del usuario autenticado en {scope}')]
    public function changePassword(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], (string) $user->getAuthPassword())) {
            return $this->sendError(
                'Contraseña actual incorrecta.',
                ['current_password' => ['Contraseña actual incorrecta.']],
                422,
                'ERR_VALIDATION',
            );
        }

        $revokeOthers = (bool) config('mk_director.auth.password_change.revoke_other_sessions', true);

        DB::transaction(function () use ($user, $data, $revokeOthers) {
            $user->setAuthPassword((string) $data['password']);

            if ($revokeOthers) {
                $this->revokeOtherTokens($user);
            }
        });

        $this->dispatchAuthEventSafe('auth.password_changed', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Contraseña actualizada.');
    }

    /**
     * POST /api/{scope}/auth/password/code/request
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.password-code-request` (per-route).
     *
     * Spec: 2026-07-15-profile-edit-password-otp, ADR-2 + ADR-3 (design.md).
     *
     * Solicita un código OTP por email para cambiar la contraseña del user
     * autenticado (alternativa a `password/change`, que pide la contraseña
     * actual). Thin: delega TODA la mecánica de generar/hashear/persistir/
     * throttle a `EmailOtpService` — este método solo orquesta.
     *
     * `identifier` (R1, design.md): se usa `getAuthIdentifier()` (el id del
     * user), NO el valor de `loginField()`. Decisión pineada en esta tarea:
     * el endpoint YA está autenticado (no hace falta un lookup por
     * loginField como en `forgotPassword()`), y el id es estable aunque el
     * email cambie a mitad de flujo (ADR-5 widened profile permite editar
     * email) — evita que un cambio de email invalide un código en vuelo o
     * cause una colisión de identifier entre cuentas.
     *
     * Respuesta genérica (no filtra estado interno): 200 con `expires_at`
     * SIEMPRE que el user esté autenticado y no esté throttled.
     */
    #[Ability('{scope}.auth.password-code-request', 'Solicitar código de cambio de contraseña en {scope}')]
    public function requestPasswordCode(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $scope = $this->authScope();
        $identifier = (string) $user->getAuthIdentifier();

        if ($this->emailOtpService()->isRequestThrottled($scope, 'password_change', $identifier)) {
            return $this->sendError(
                'Demasiadas solicitudes. Esperá antes de volver a intentar.',
                [],
                429,
                'ERR_THROTTLED',
            );
        }

        $result = $this->emailOtpService()->issue($scope, 'password_change', $identifier);

        $this->dispatchAuthEventSafe('auth.password_change_code.requested', [
            'scope' => $scope,
            'user_id' => (string) $user->getAuthIdentifier(),
            'code' => $result->plainCode,  // plaintext — SOLO viaja acá, el listener lo envía por email.
            'expires_at' => $result->expiresAt->toIso8601String(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(
            ['expires_at' => $result->expiresAt->toIso8601String()],
            'Código enviado.',
        );
    }

    /**
     * POST /api/{scope}/auth/password/code/confirm
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.password-code-confirm` (per-route).
     *
     * Spec: 2026-07-15-profile-edit-password-otp, ADR-2 + ADR-3 (design.md).
     *
     * Body: `{code, password, password_confirmation}`. NO pide
     * `current_password` — el PIN reemplaza esa prueba en este flujo.
     * `EmailOtpService::verify()` es el único lugar que decide el veredicto;
     * este método solo mapea el enum a códigos HTTP.
     */
    #[Ability('{scope}.auth.password-code-confirm', 'Confirmar código y cambiar contraseña en {scope}')]
    public function confirmPasswordCode(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $data = $request->validate([
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $scope = $this->authScope();
        $identifier = (string) $user->getAuthIdentifier();

        $verdict = $this->emailOtpService()->verify($scope, 'password_change', $identifier, $data['code']);

        if ($verdict !== OtpVerifyResult::Confirmed) {
            return match ($verdict) {
                OtpVerifyResult::Expired => $this->sendError(
                    'Código expirado.',
                    [],
                    410,
                    'ERR_CODE_EXPIRED',
                ),
                OtpVerifyResult::Locked => $this->sendError(
                    'Demasiados intentos. Solicitá un nuevo código.',
                    [],
                    423,
                    'ERR_CODE_LOCKED',
                ),
                // Invalid | NotFound → mensaje genérico, sin distinguir el motivo
                // (anti-oracle: no filtrar si el código nunca existió, ya expiró
                // por otra vía, o fue consumido).
                default => $this->sendError(
                    'Código inválido.',
                    ['code' => ['Código inválido.']],
                    422,
                    'ERR_VALIDATION',
                ),
            };
        }

        $revokeOthers = (bool) config('mk_director.auth.password_change.revoke_other_sessions', true);

        DB::transaction(function () use ($user, $data, $revokeOthers) {
            $user->setAuthPassword((string) $data['password']);

            if ($revokeOthers) {
                $this->revokeOtherTokens($user);
            }
        });

        $this->dispatchAuthEventSafe('auth.password_changed', [
            'scope' => $scope,
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Contraseña actualizada.');
    }

    /**
     * POST /api/{scope}/auth/password/reset/code/request
     *
     * Auth: público (flujo NO autenticado — "olvidé mi contraseña").
     * Ability: `mk.ability:{scope}.auth.password-reset-code-request` (per-route).
     *
     * Spec: 2026-07-15-profile-edit-password-otp, forgot-vía-OTP.
     *
     * Variante OTP del `forgotPassword()` clásico (que manda un token largo).
     * Reusa `EmailOtpService` con `purpose='password_reset'` para emitir un PIN
     * de 6 dígitos. A diferencia de `requestPasswordCode()` (autenticado, el
     * identifier sale del token), acá el user NO está autenticado: se resuelve
     * por `loginField()` y el `identifier` del OTP es su id estable.
     *
     * [SECURITY] Anti-enumeración: SIEMPRE responde 200 con el MISMO mensaje
     * genérico —exista o no el user, esté o no throttled a nivel servicio— para
     * no filtrar qué cuentas existen. El abuso por IP lo corta el `throttle:`
     * de ruta (que devuelve 429 por IP, sin distinguir cuenta).
     */
    #[Ability('{scope}.auth.password-reset-code-request', 'Solicitar código de reset de contraseña en {scope}')]
    public function requestPasswordResetCode(Request $request): JsonResponse
    {
        $loginField = $this->loginField();
        $scope = $this->authScope();

        $credentials = $request->validate([
            $loginField => $loginField === 'email'
                ? ['required', 'email', 'max:255']
                : ['required', 'string', 'max:255'],
        ]);

        $genericOk = fn (): JsonResponse => $this->sendResponse(
            null,
            "Si el {$loginField} existe, recibirás un código para restablecer tu contraseña.",
        );

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()
            ->where($loginField, $credentials[$loginField])
            ->first();

        if (! $user
            || $user->getAuthScope() !== $scope
            || ! $this->userHasValidStatus($user)
        ) {
            return $genericOk();
        }

        $identifier = (string) $user->getAuthIdentifier();

        // Throttle silencioso: no filtra que la cuenta existe (no 429 acá).
        if ($this->emailOtpService()->isRequestThrottled($scope, 'password_reset', $identifier)) {
            return $genericOk();
        }

        $result = $this->emailOtpService()->issue($scope, 'password_reset', $identifier);

        $this->dispatchAuthEventSafe('auth.password_reset_code.requested', [
            'scope' => $scope,
            'user_id' => $identifier,
            'code' => $result->plainCode,  // plaintext — SOLO viaja acá, el listener lo envía por email.
            'expires_at' => $result->expiresAt->toIso8601String(),
            'ip' => $request->ip(),
        ]);

        return $genericOk();
    }

    /**
     * POST /api/{scope}/auth/password/reset/code/confirm
     *
     * Auth: público (flujo NO autenticado).
     * Ability: `mk.ability:{scope}.auth.password-reset-code-confirm` (per-route).
     *
     * Body: `{<loginField>, code, password, password_confirmation}`. Verifica el
     * PIN vía `EmailOtpService::verify(purpose='password_reset')` y, si es
     * válido, setea el nuevo password y revoca TODOS los tokens del user (es un
     * reset: cerrar sesión en todos lados). `verify()` es la única fuente del
     * veredicto; este método solo mapea a HTTP.
     *
     * [SECURITY] Anti-enumeración + anti-oracle: un user inexistente/ inválido
     * colapsa al MISMO 422 genérico que un código incorrecto — no revela si la
     * cuenta existe ni si tenía un código activo.
     */
    #[Ability('{scope}.auth.password-reset-code-confirm', 'Confirmar código de reset y cambiar contraseña en {scope}')]
    public function confirmPasswordResetCode(Request $request): JsonResponse
    {
        $loginField = $this->loginField();
        $scope = $this->authScope();

        $data = $request->validate([
            $loginField => $loginField === 'email'
                ? ['required', 'email', 'max:255']
                : ['required', 'string', 'max:255'],
            'code' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $invalid = fn (): JsonResponse => $this->sendError(
            'Código inválido.',
            ['code' => ['Código inválido.']],
            422,
            'ERR_VALIDATION',
        );

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()
            ->where($loginField, $data[$loginField])
            ->first();

        if (! $user
            || $user->getAuthScope() !== $scope
            || ! $this->userHasValidStatus($user)
        ) {
            return $invalid();
        }

        $identifier = (string) $user->getAuthIdentifier();
        $verdict = $this->emailOtpService()->verify($scope, 'password_reset', $identifier, $data['code']);

        if ($verdict !== OtpVerifyResult::Confirmed) {
            return match ($verdict) {
                OtpVerifyResult::Expired => $this->sendError(
                    'Código expirado.',
                    [],
                    410,
                    'ERR_CODE_EXPIRED',
                ),
                OtpVerifyResult::Locked => $this->sendError(
                    'Demasiados intentos. Solicitá un nuevo código.',
                    [],
                    423,
                    'ERR_CODE_LOCKED',
                ),
                default => $invalid(),
            };
        }

        DB::transaction(function () use ($user, $data) {
            $user->setAuthPassword((string) $data['password']);

            // Reset = cerrar sesión en TODOS lados (no hay currentAccessToken
            // en el flujo no-autenticado).
            if (method_exists($user, 'tokens')) {
                $user->tokens()->delete();
            }
        });

        $this->dispatchAuthEventSafe('auth.password_reset.success', [
            'scope' => $scope,
            'user_id' => $identifier,
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Contraseña actualizada.');
    }

    /**
     * GET /api/{scope}/auth/email/verify/{id}/{hash}
     *
     * Auth: signed URL (sin middleware mk.auth; verificación via `hasValidSignature()`).
     * Ability: `mk.ability:{scope}.auth.email-verify` (per-route).
     *
     * Solo aplicable cuando `loginField() === 'email'` (MustVerifyEmail interface).
     * Marcar `email_verified_at = now()` y refrescar user.
     */
    #[Ability('{scope}.auth.email-verify', 'Verificar email del usuario en {scope}')]
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        if (! $request->hasValidSignature()) {
            return $this->sendError('Firma inválida.', [], 403, 'ERR_FORBIDDEN');
        }

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()->find($id);

        if (! $user || $user->getAuthScope() !== $this->authScope()) {
            return $this->sendError('Usuario no encontrado.', [], 404, 'ERR_NOT_FOUND');
        }

        if (! method_exists($user, 'getEmailForVerification')) {
            return $this->sendError(
                'Verificación de email no aplicable a este scope (loginField != email).',
                [],
                422,
                'ERR_VALIDATION',
            );
        }

        $expectedHash = sha1((string) $user->getEmailForVerification());
        if (! hash_equals($expectedHash, (string) $hash)) {
            return $this->sendError('Hash inválido.', [], 403, 'ERR_FORBIDDEN');
        }

        if (method_exists($user, 'markEmailAsVerified') && ! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $this->sendResponse(true, 'Email verificado.');
    }

    /**
     * POST /api/{scope}/auth/email/resend
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.email-resend` (per-route).
     *
     * Reenvía el email de verificación. Rate limit aplicado per-route
     * (`throttle:3,1` default).
     */
    #[Ability('{scope}.auth.email-resend', 'Reenviar email de verificación en {scope}')]
    public function resendVerification(Request $request): JsonResponse
    {
        /** @var Authenticatable $user */
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        if (method_exists($user, 'hasVerifiedEmail') && $user->hasVerifiedEmail()) {
            return $this->sendError('El email ya fue verificado.', [], 422, 'ERR_VALIDATION');
        }

        if (! method_exists($user, 'sendEmailVerificationNotification')) {
            return $this->sendError(
                'Reenvío de verificación no aplicable a este scope (loginField != email).',
                [],
                422,
                'ERR_VALIDATION',
            );
        }

        $user->sendEmailVerificationNotification();

        $this->dispatchAuthEventSafe('auth.email_verification_resent', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Email de verificación enviado.');
    }

    // ============================================================
    //  Verificación en dos pasos (TOTP) — DEVELOPER_GUIDE § 3.20
    // ============================================================

    /**
     * `purpose` de la credencial que reemplaza a la sesión mientras falta el
     * segundo factor. El nombre lo reservaba el docblock de `EmailOtpService`
     * desde que se escribió la tabla.
     */
    protected const TWO_FACTOR_CHALLENGE_PURPOSE = 'login_2fa';

    /** `purpose` de la credencial que SÓLO habilita el enrolamiento. */
    protected const TWO_FACTOR_SETUP_PURPOSE = 'login_2fa_setup';

    /**
     * Qué exige este scope como segundo factor. Default `off`: todo scope ya
     * generado sigue logueando igual que antes.
     *
     * Se override en el `AuthController` del scope, como `loginField()` — el
     * scaffolder lo emite con `--two-factor=optional|required`:
     *
     *     protected function twoFactorPolicy(): TwoFactorPolicy
     *     {
     *         return TwoFactorPolicy::Required;
     *     }
     */
    protected function twoFactorPolicy(): TwoFactorPolicy
    {
        return TwoFactorPolicy::default();
    }

    /**
     * POST /api/{scope}/auth/two-factor/challenge
     *
     * Auth: público, con el DESAFÍO que devolvió el login (no es un token: no
     * autentica ninguna ruta y no se puede refrescar).
     * Ability: `mk.ability:{scope}.auth.two-factor-challenge` (per-route).
     *
     * Body: `{challenge, code}` o `{challenge, recovery_code}`.
     * Éxito: EXACTAMENTE el mismo sobre que habría devuelto el login.
     *
     * El desafío sobrevive a un código equivocado —si no, un dígito mal tipeado
     * mandaría a loguearse de nuevo y el tope de intentos no contaría nada— pero
     * cada intento se reserva de forma atómica, así que a los N intentos el
     * desafío queda bloqueado (`423`) y hay que volver a empezar.
     */
    #[Ability('{scope}.auth.two-factor-challenge', 'Completar el segundo factor del login en {scope}')]
    public function confirmTwoFactorChallenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        [$user, $secret] = $this->resolveTwoFactorCredential(
            (string) $data['challenge'],
            self::TWO_FACTOR_CHALLENGE_PURPOSE,
        );

        if ($user === null) {
            return $this->invalidTwoFactorChallenge();
        }

        $identifier = (string) $user->getAuthIdentifier();
        $verdict = $this->emailOtpService()->reserveAttempt(
            $this->authScope(),
            self::TWO_FACTOR_CHALLENGE_PURPOSE,
            $identifier,
            $secret,
        );

        if ($response = $this->twoFactorVerdictResponse($verdict)) {
            return $response;
        }

        $recoveryCode = (string) ($data['recovery_code'] ?? '');
        $usedRecoveryCode = $recoveryCode !== '';

        $accepted = $usedRecoveryCode
            ? $this->consumeTwoFactorRecoveryCode($user, $recoveryCode)
            : $this->acceptTwoFactorCode($user, (string) ($data['code'] ?? ''));

        if (! $accepted) {
            $this->dispatchAuthEventSafe('auth.two_factor.challenge_failed', [
                'scope' => $this->authScope(),
                'user_id' => $identifier,
                'via' => $usedRecoveryCode ? 'recovery_code' : 'totp',
                'ip' => $request->ip(),
            ]);

            return $this->sendError(
                'Código inválido.',
                ['code' => ['Código inválido.']],
                422,
                'ERR_VALIDATION',
            );
        }

        // El desafío se gasta recién acá: una vez que el segundo factor entró.
        $this->emailOtpService()->consume(
            $this->authScope(),
            self::TWO_FACTOR_CHALLENGE_PURPOSE,
            $identifier,
            $secret,
        );

        if ($usedRecoveryCode) {
            $this->dispatchAuthEventSafe('auth.two_factor.recovery_code_used', [
                'scope' => $this->authScope(),
                'user_id' => $identifier,
                'remaining' => count($this->twoFactorRecoveryHashes($user)),
                'ip' => $request->ip(),
            ]);
        }

        return $this->issueSessionResponse($request, $user);
    }

    /**
     * POST /api/{scope}/auth/two-factor/setup/confirm
     *
     * Auth: público, con la credencial de ENROLAMIENTO que devolvió el login
     * cuando la política es `required` y el usuario no tenía segundo factor.
     * Ability: `mk.ability:{scope}.auth.two-factor-setup-confirm` (per-route).
     *
     * Body: `{setup, code}`. Éxito: el sobre del login + `recovery_codes`, que
     * es la ÚNICA vez que esos códigos existen en claro.
     */
    #[Ability('{scope}.auth.two-factor-setup-confirm', 'Confirmar el enrolamiento obligatorio de dos factores en {scope}')]
    public function confirmTwoFactorSetup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setup' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        [$user, $secret] = $this->resolveTwoFactorCredential(
            (string) $data['setup'],
            self::TWO_FACTOR_SETUP_PURPOSE,
        );

        if ($user === null) {
            return $this->invalidTwoFactorChallenge();
        }

        $identifier = (string) $user->getAuthIdentifier();
        $verdict = $this->emailOtpService()->reserveAttempt(
            $this->authScope(),
            self::TWO_FACTOR_SETUP_PURPOSE,
            $identifier,
            $secret,
        );

        if ($response = $this->twoFactorVerdictResponse($verdict)) {
            return $response;
        }

        if (! $this->acceptTwoFactorCode($user, (string) $data['code'])) {
            $this->dispatchAuthEventSafe('auth.two_factor.challenge_failed', [
                'scope' => $this->authScope(),
                'user_id' => $identifier,
                'via' => 'setup',
                'ip' => $request->ip(),
            ]);

            return $this->sendError('Código inválido.', ['code' => ['Código inválido.']], 422, 'ERR_VALIDATION');
        }

        $recoveryCodes = $this->completeTwoFactorEnrollment($request, $user);

        $this->emailOtpService()->consume(
            $this->authScope(),
            self::TWO_FACTOR_SETUP_PURPOSE,
            $identifier,
            $secret,
        );

        return $this->issueSessionResponse(
            $request,
            $user,
            'Verificación en dos pasos activada.',
            ['recovery_codes' => $recoveryCodes],
        );
    }

    /**
     * POST /api/{scope}/auth/two-factor/enable
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.two-factor-enable` (per-route).
     *
     * Body: `{current_password}` cuando la política es `optional` — un token
     * robado no puede enrolar el dispositivo del atacante.
     *
     * Devuelve el secreto y la URI `otpauth://` (el front dibuja el QR). El
     * secreto queda guardado SIN confirmar: hasta el `confirm`, el login no
     * pide nada.
     */
    #[Ability('{scope}.auth.two-factor-enable', 'Iniciar el enrolamiento de dos factores en {scope}')]
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $policy = $this->twoFactorPolicy();

        // Prender un segundo factor que el login va a ignorar es peor que no
        // poder prenderlo: el usuario cree que su cuenta está protegida.
        if ($policy === TwoFactorPolicy::Off) {
            return $this->sendError(
                'Este acceso no usa verificación en dos pasos.',
                [],
                403,
                'ERR_2FA_DISABLED_BY_SCOPE',
            );
        }

        if ($policy->requiresPasswordToEnable()) {
            $data = $request->validate(['current_password' => ['required', 'string']]);

            if (! Hash::check($data['current_password'], (string) $user->getAuthPassword())) {
                return $this->sendError(
                    'Contraseña actual incorrecta.',
                    ['current_password' => ['Contraseña actual incorrecta.']],
                    422,
                    'ERR_VALIDATION',
                );
            }
        }

        if ($this->userHasConfirmedTwoFactor($user)) {
            return $this->sendError(
                'La verificación en dos pasos ya está activa.',
                [],
                422,
                'ERR_2FA_ALREADY_ENABLED',
            );
        }

        $secret = $this->startTwoFactorEnrollment($user);

        return $this->sendResponse(
            $this->twoFactorEnrollmentPayload($user, $secret),
            'Escaneá el código y confirmá con los seis dígitos.',
        );
    }

    /**
     * POST /api/{scope}/auth/two-factor/confirm
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.two-factor-confirm` (per-route).
     *
     * Body: `{code}`. Confirma el secreto pendiente, devuelve los códigos de
     * recuperación (la única vez que se ven) y cierra las OTRAS sesiones.
     */
    #[Ability('{scope}.auth.two-factor-confirm', 'Confirmar el enrolamiento de dos factores en {scope}')]
    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $request->validate(['code' => ['required', 'string']]);

        if ($this->userHasConfirmedTwoFactor($user)) {
            return $this->sendError('La verificación en dos pasos ya está activa.', [], 422, 'ERR_2FA_ALREADY_ENABLED');
        }

        if (! $this->hasPendingTwoFactorSecret($user)) {
            return $this->sendError(
                'No hay un enrolamiento en curso. Empezá por `two-factor/enable`.',
                [],
                422,
                'ERR_2FA_NOT_ENABLED',
            );
        }

        if (! $this->acceptTwoFactorCode($user, (string) $request->input('code'))) {
            return $this->sendError('Código inválido.', ['code' => ['Código inválido.']], 422, 'ERR_VALIDATION');
        }

        $recoveryCodes = $this->completeTwoFactorEnrollment($request, $user);

        return $this->sendResponse(
            ['recovery_codes' => $recoveryCodes],
            'Verificación en dos pasos activada.',
        );
    }

    /**
     * POST /api/{scope}/auth/two-factor/recovery-codes
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.two-factor-recovery-codes` (per-route).
     *
     * Body: `{code}` — un código del autenticador. Sin esa prueba, cualquiera
     * con el token podría pedir una tanda nueva de códigos y llevarse ocho
     * llaves de la cuenta.
     */
    #[Ability('{scope}.auth.two-factor-recovery-codes', 'Regenerar los códigos de recuperación en {scope}')]
    public function regenerateTwoFactorRecoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        $request->validate(['code' => ['required', 'string']]);

        if (! $this->userHasConfirmedTwoFactor($user)) {
            return $this->sendError(
                'La verificación en dos pasos no está activa.',
                [],
                422,
                'ERR_2FA_NOT_ENABLED',
            );
        }

        if (! $this->acceptTwoFactorCode($user, (string) $request->input('code'))) {
            return $this->sendError('Código inválido.', ['code' => ['Código inválido.']], 422, 'ERR_VALIDATION');
        }

        $plain = $this->totpService()->generateRecoveryCodes();
        $user->setAttribute('two_factor_recovery_codes', $this->totpService()->hashRecoveryCodes($plain));
        $user->save();

        return $this->sendResponse(['recovery_codes' => $plain], 'Códigos de recuperación regenerados.');
    }

    /**
     * POST /api/{scope}/auth/two-factor/disable
     *
     * Auth: `mk.auth:{scope}` (per-route).
     * Ability: `mk.ability:{scope}.auth.two-factor-disable` (per-route).
     *
     * Body: `{current_password, code}` — las dos pruebas. Con la política
     * `required` responde 403: el scope lo exige, y sacárselo es decisión de un
     * administrador (`php artisan mk:auth:two-factor-reset`).
     */
    #[Ability('{scope}.auth.two-factor-disable', 'Apagar la verificación en dos pasos en {scope}')]
    public function disableTwoFactor(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof Authenticatable) {
            return $this->sendError('No autenticado.', [], 401, 'ERR_UNAUTHENTICATED');
        }

        if (! $this->twoFactorPolicy()->allowsDisabling()) {
            return $this->sendError(
                'Este acceso exige verificación en dos pasos: no se puede apagar.',
                [],
                403,
                'ERR_2FA_REQUIRED_BY_SCOPE',
            );
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        if (! Hash::check($data['current_password'], (string) $user->getAuthPassword())) {
            return $this->sendError(
                'Contraseña actual incorrecta.',
                ['current_password' => ['Contraseña actual incorrecta.']],
                422,
                'ERR_VALIDATION',
            );
        }

        if (! $this->userHasConfirmedTwoFactor($user)) {
            return $this->sendError('La verificación en dos pasos no está activa.', [], 422, 'ERR_2FA_NOT_ENABLED');
        }

        if (! $this->acceptTwoFactorCode($user, (string) $data['code'])) {
            return $this->sendError('Código inválido.', ['code' => ['Código inválido.']], 422, 'ERR_VALIDATION');
        }

        if (method_exists($user, 'forgetTwoFactor')) {
            $user->forgetTwoFactor();
        }

        $this->revokeOtherTokens($user);

        $this->dispatchAuthEventSafe('auth.two_factor.disabled', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $this->sendResponse(true, 'Verificación en dos pasos desactivada.');
    }

    // ============================================================
    //  Segundo factor — mecánica compartida
    // ============================================================

    /**
     * La decisión del login: `null` = seguí, emití tokens.
     *
     * Las dos ramas devuelven 200 (las credenciales ERAN buenas; falta el
     * segundo paso) con `data.two_factor` diciendo cuál es ese paso y el mismo
     * dato en `__extraData.code`, para que un front pueda ramificar por donde ya
     * ramifica los errores.
     *
     * 🔴 Ninguna de las dos credenciales es un token: no llevan
     * `auth_scope:{scope}`, así que `mk.auth` no las mira, y no tienen la
     * ability `refresh`, así que `/auth/refresh` tampoco. Viven en
     * `verification_codes` con su vencimiento y su tope de intentos.
     */
    protected function twoFactorGate(Request $request, Authenticatable $user): ?JsonResponse
    {
        $policy = $this->twoFactorPolicy();
        $confirmed = $this->userHasConfirmedTwoFactor($user);

        if ($policy->requiresChallenge($confirmed)) {
            $ttl = $this->twoFactorConfigInt('challenge_ttl_seconds', 300);

            return $this->sendResponse([
                'two_factor' => 'challenge',
                'challenge' => $this->issueTwoFactorCredential($user, self::TWO_FACTOR_CHALLENGE_PURPOSE, $ttl),
                'expires_in' => $ttl,
            ], 'Ingresá el código de tu app de autenticación.', 200, ['code' => 'TWO_FACTOR_REQUIRED']);
        }

        if ($policy->requiresEnrollment($confirmed)) {
            $ttl = $this->twoFactorConfigInt('setup_ttl_seconds', 900);
            $secret = $this->startTwoFactorEnrollment($user);

            return $this->sendResponse(array_merge([
                'two_factor' => 'setup',
                'setup' => $this->issueTwoFactorCredential($user, self::TWO_FACTOR_SETUP_PURPOSE, $ttl),
                'expires_in' => $ttl,
            ], $this->twoFactorEnrollmentPayload($user, $secret)),
                'Este acceso exige verificación en dos pasos. Escaneá el código y confirmá.',
                200,
                ['code' => 'TWO_FACTOR_SETUP_REQUIRED'],
            );
        }

        return null;
    }

    /**
     * Emite la credencial de un solo uso y devuelve lo que viaja al cliente:
     * `{identificador}.{secreto}`.
     *
     * El identificador va adelante porque el cliente NO está autenticado: sin
     * él no hay forma de saber de qué fila hablamos. Es el id del usuario, el
     * mismo que el login exitoso devuelve en el sobre — no agrega nada que la
     * respuesta de al lado no diga.
     */
    protected function issueTwoFactorCredential(Authenticatable $user, string $purpose, int $ttlSeconds): string
    {
        $identifier = (string) $user->getAuthIdentifier();
        $secret = bin2hex(random_bytes(32));

        $this->emailOtpService()->issue(
            $this->authScope(),
            $purpose,
            $identifier,
            plainCode: $secret,
            ttlSeconds: $ttlSeconds,
            maxAttempts: $this->twoFactorConfigInt('max_attempts', 5),
        );

        return $identifier.'.'.$secret;
    }

    /**
     * Parte la credencial y resuelve al usuario con las MISMAS puertas que el
     * login: scope correcto y cuenta habilitada. Un usuario bloqueado entre el
     * login y el segundo paso no termina de entrar.
     *
     * @return array{0: Authenticatable|null, 1: string}
     */
    protected function resolveTwoFactorCredential(string $credential, string $purpose): array
    {
        $parts = explode('.', $credential, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return [null, ''];
        }

        [$identifier, $secret] = $parts;

        /** @var Authenticatable|null $user */
        $user = $this->authModelClass()::query()->whereKey($identifier)->first();

        if (! $user
            || $user->getAuthScope() !== $this->authScope()
            || ! $this->userHasValidStatus($user)
        ) {
            return [null, ''];
        }

        return [$user, $secret];
    }

    /**
     * Arranca (o reinicia) el enrolamiento: secreto nuevo SIN confirmar, y la
     * tanda anterior de códigos de recuperación y el último paso aceptado a
     * cero — son de un secreto que ya no existe.
     */
    protected function startTwoFactorEnrollment(Authenticatable $user): string
    {
        $secret = $this->totpService()->generateSecret();

        $user->setAttribute('two_factor_secret', $secret);
        $user->setAttribute('two_factor_recovery_codes', null);
        $user->setAttribute('two_factor_confirmed_at', null);
        $user->setAttribute('two_factor_last_step', null);
        $user->save();

        return $secret;
    }

    /**
     * Cierra el enrolamiento: lo marca confirmado, emite los códigos de
     * recuperación y cierra las OTRAS sesiones (si alguien más estaba adentro
     * con esta cuenta, prender el segundo factor no puede dejarlo adentro).
     *
     * @return array<int, string> los códigos EN CLARO, que se muestran una vez
     */
    protected function completeTwoFactorEnrollment(Request $request, Authenticatable $user): array
    {
        $plain = $this->totpService()->generateRecoveryCodes();

        $user->setAttribute('two_factor_recovery_codes', $this->totpService()->hashRecoveryCodes($plain));
        $user->setAttribute('two_factor_confirmed_at', now());
        $user->save();

        $this->revokeOtherTokens($user);

        $this->dispatchAuthEventSafe('auth.two_factor.enabled', [
            'scope' => $this->authScope(),
            'user_id' => (string) $user->getAuthIdentifier(),
            'ip' => $request->ip(),
        ]);

        return $plain;
    }

    /**
     * Valida el código del autenticador Y sella el paso aceptado.
     *
     * Las dos cosas van juntas a propósito: si el sellado quedara del lado del
     * caller, un endpoint nuevo que se olvidara de hacerlo dejaría el mismo
     * código sirviendo durante toda su ventana.
     */
    protected function acceptTwoFactorCode(Authenticatable $user, string $code): bool
    {
        $secret = $this->twoFactorAttribute($user, 'two_factor_secret');
        if (! is_string($secret) || trim($secret) === '') {
            return false;
        }

        $lastStep = $this->twoFactorAttribute($user, 'two_factor_last_step');
        $step = $this->totpService()->verify(
            $secret,
            trim($code),
            $lastStep === null ? null : (int) $lastStep,
        );

        if ($step === null) {
            return false;
        }

        $user->setAttribute('two_factor_last_step', $step);
        $user->save();

        return true;
    }

    /**
     * Gasta un código de recuperación. La lista se guarda sin el que se usó
     * ANTES de emitir la sesión: si se guardara después, dos requests con el
     * mismo código entrarían las dos.
     */
    protected function consumeTwoFactorRecoveryCode(Authenticatable $user, string $candidate): bool
    {
        $remaining = $this->totpService()->consumeRecoveryCode(
            $this->twoFactorRecoveryHashes($user),
            trim($candidate),
        );

        if ($remaining === null) {
            return false;
        }

        $user->setAttribute('two_factor_recovery_codes', $remaining);
        $user->save();

        return true;
    }

    /**
     * Traduce el veredicto de la credencial a HTTP. `null` = seguí.
     *
     * Mismo mapeo que el PIN de email (§ 3.18): `Invalid` y `NotFound` colapsan
     * al mismo 422 genérico — no se distingue «nunca existió» de «no es el
     * valor correcto».
     */
    protected function twoFactorVerdictResponse(OtpVerifyResult $verdict): ?JsonResponse
    {
        return match ($verdict) {
            OtpVerifyResult::Confirmed => null,
            OtpVerifyResult::Expired => $this->sendError(
                'El desafío expiró. Volvé a iniciar sesión.',
                [],
                410,
                'ERR_CODE_EXPIRED',
            ),
            OtpVerifyResult::Locked => $this->sendError(
                'Demasiados intentos. Volvé a iniciar sesión.',
                [],
                423,
                'ERR_CODE_LOCKED',
            ),
            default => $this->invalidTwoFactorChallenge(),
        };
    }

    /**
     * El 422 genérico del desafío: no dice si el usuario existe, si la
     * credencial venció o si nunca hubo una.
     */
    protected function invalidTwoFactorChallenge(): JsonResponse
    {
        return $this->sendError(
            'Desafío inválido o expirado.',
            ['challenge' => ['Desafío inválido o expirado.']],
            422,
            'ERR_VALIDATION',
        );
    }

    /**
     * El secreto + la URI del QR. El secreto sale en claro a propósito: es lo
     * que el usuario tipea a mano cuando el lector de QR no lee.
     *
     * @return array<string, string>
     */
    protected function twoFactorEnrollmentPayload(Authenticatable $user, string $secret): array
    {
        $label = (string) ($user->getAttribute($this->loginField()) ?? $user->getAuthIdentifier());

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totpService()->otpauthUri($secret, $label, $this->twoFactorIssuer()),
        ];
    }

    /**
     * El nombre con el que la cuenta aparece en la app del usuario. Sin
     * configurar, el nombre de la aplicación: «MK Director» en la pantalla del
     * teléfono no le dice nada a nadie.
     */
    protected function twoFactorIssuer(): ?string
    {
        $issuer = config('mk_director.auth.two_factor.issuer');

        if (is_string($issuer) && trim($issuer) !== '') {
            return $issuer;
        }

        $appName = config('app.name');

        return is_string($appName) && trim($appName) !== '' ? $appName : null;
    }

    /** ¿Hay un secreto emitido y todavía sin confirmar? */
    protected function hasPendingTwoFactorSecret(Authenticatable $user): bool
    {
        $secret = $this->twoFactorAttribute($user, 'two_factor_secret');

        return is_string($secret) && trim($secret) !== '';
    }

    /**
     * Delega en el modelo. Un modelo que no extiende `AuthUser` (o un scope sin
     * las columnas) informa «no»: el 2FA queda apagado, que es el default.
     */
    protected function userHasConfirmedTwoFactor(Authenticatable $user): bool
    {
        return method_exists($user, 'hasConfirmedTwoFactor') && $user->hasConfirmedTwoFactor();
    }

    /** @return array<int, string> */
    protected function twoFactorRecoveryHashes(Authenticatable $user): array
    {
        $hashes = $this->twoFactorAttribute($user, 'two_factor_recovery_codes');

        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    protected function twoFactorConfirmedAtIso(Authenticatable $user): ?string
    {
        $value = $this->twoFactorAttribute($user, 'two_factor_confirmed_at');

        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Lee un atributo del segundo factor tolerando que la columna no exista
     * (scope generado sin `--two-factor`) y que el modelo no sea un Eloquent.
     */
    protected function twoFactorAttribute(Authenticatable $user, string $column): mixed
    {
        return method_exists($user, 'getAttribute') ? $user->getAttribute($column) : null;
    }

    protected function twoFactorConfigInt(string $key, int $default): int
    {
        $value = config("mk_director.auth.two_factor.{$key}", $default);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * Resuelve el servicio TOTP desde el container (singleton,
     * `AuthServiceProvider`). Mismo patrón que `tokenIssuer()`.
     */
    protected function totpService(): TotpService
    {
        return app(TotpService::class);
    }

    // ============================================================
    //  Helpers privados
    // ============================================================

    /**
     * Cierra TODAS las sesiones del usuario menos la que hizo esta request.
     *
     * Estaba escrito igual en `changePassword()` y en `confirmPasswordCode()`;
     * lo necesitan además los tres endpoints que tocan el segundo factor. Un
     * cuarto copiado es un cuarto lugar donde olvidarse del `!= currentToken` y
     * desloguear al que acaba de hacer el cambio.
     */
    protected function revokeOtherTokens(Authenticatable $user): void
    {
        if (! method_exists($user, 'tokens')) {
            return;
        }

        $currentToken = method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        $user->tokens()->where('id', '!=', $currentToken?->id)->delete();
    }

    /**
     * Resolve TokenIssuer desde container (singleton, R-PKG-014 AuthServiceProvider).
     *
     * Subclase puede override para inyectar TokenIssuer custom (e.g. tests).
     */
    protected function tokenIssuer(): TokenIssuer
    {
        return app(TokenIssuer::class);
    }

    /**
     * Resolve EmailOtpService desde container (singleton, AuthServiceProvider).
     *
     * Spec: 2026-07-15-profile-edit-password-otp, ADR-2 (design.md) —
     * mirrors `tokenIssuer()`. Subclase puede override para inyectar un
     * service custom (e.g. tests).
     */
    protected function emailOtpService(): EmailOtpService
    {
        return app(EmailOtpService::class);
    }

    /**
     * ¿Este user puede autenticarse? Delega en {@see AccountStatus}, la regla
     * única que también aplican `mk.auth` y `TokenIssuer::rotateRefreshToken()`.
     *
     * Se conserva como método protegido por BC. Override acá sólo afecta a
     * login/forgot/reset: para cambiar qué estados autentican en TODAS las
     * puertas, override `canAuthenticate()` en el enum del scope.
     */
    protected function userHasValidStatus(Authenticatable $user): bool
    {
        return AccountStatus::allowsAuthentication($user);
    }

    /**
     * Wrapper seguro sobre `AuthEvent::dispatch()` — si la clase no existe
     * en un entorno minimalista (test setup sin package), swallow el error.
     */
    private function dispatchAuthEventSafe(string $eventName, array $payload = []): void
    {
        if (! class_exists(AuthEvent::class)) {
            return;
        }

        try {
            AuthEvent::dispatch($eventName, $payload);
        } catch (\Throwable) {
            // Defense-in-depth: nunca romper login por un audit event faltante.
        }
    }
}
