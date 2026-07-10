<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Mk\Director\Auth\Attributes\Ability;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\InvalidRefreshTokenException;
use Mk\Director\Auth\Services\TokenIssuer;
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
 *   chequeaba via `Schema::hasColumn`. Post-D4 se prefiere `ScopeStatus` enum.
 *   `userHasValidStatus()` fallback a `true` si ni enum ni column existen
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
     *   7. TokenIssuer::issueAccessToken + issueRefreshToken.
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

        // Eager-load relaciones (R-PKG-014 BUG-06 fix).
        if (method_exists($user, 'loadMissing')) {
            $user->loadMissing(['roles', 'directAbilities']);
        }

        $tokenIssuer = $this->tokenIssuer();
        $accessToken = $tokenIssuer->issueAccessToken($user);
        $refreshToken = $tokenIssuer->issueRefreshToken($user);

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

        return $this->sendResponse([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'token_type' => 'Bearer',
            'expires_in' => $tokens['expires_in'],
            $scope => $userPayload,
        ], 'Login exitoso');
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

            return $this->sendError($e->getMessage(), [], 401, 'ERR_UNAUTHENTICATED');
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
     * Revoca SOLO el access token actual (`safeLogoutCurrentToken()` con
     * null-safety, R-PKG-027 PKG-NEW-08 + R-PKG-014 BUG-01).
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

            if ($revokeOthers && method_exists($user, 'tokens')) {
                $currentToken = method_exists($user, 'currentAccessToken')
                    ? $user->currentAccessToken()
                    : null;
                $user->tokens()->where('id', '!=', $currentToken?->id)->delete();
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
    //  Helpers privados
    // ============================================================

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
     * Valida que el user tenga status válido para autenticarse.
     *
     * Pre-R-PKG-047 D4: chequea `is_active` boolean vía `Schema::hasColumn`.
     * Post-R-PKG-047 D4: chequea `ScopeStatus` enum (Active/Inactive/Suspended/
     * Pending). Si `$user` tiene `ScopeStatus` castable, usa `canAuthenticate()`.
     *
     * Defense-in-depth: si no hay enum ni column, retorna true (BC para
     * scopes que aún no migraron).
     */
    protected function userHasValidStatus(Authenticatable $user): bool
    {
        // Post-D4: ScopeStatus enum (R-PKG-047). Si el modelo pinea el cast,
        // `$user->status` retorna ScopeStatus enum con `canAuthenticate()`.
        if (isset($user->status) && is_object($user->status) && method_exists($user->status, 'canAuthenticate')) {
            return (bool) $user->status->canAuthenticate();
        }

        // Pre-D4 BC: `is_active` boolean column.
        if (method_exists($user, 'getTable') && Schema::hasColumn((string) $user->getTable(), 'is_active')) {
            $isActive = $user->getAttribute('is_active');
            if ($isActive === false || $isActive === 0 || $isActive === '0') {
                return false;
            }
            // null o true = permitido (compat con datos preexistentes).
            return true;
        }

        // Default: sin columna ni enum, asumir permitido.
        return true;
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
