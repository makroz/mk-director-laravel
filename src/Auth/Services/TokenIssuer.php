<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * TokenIssuer — emite access + refresh tokens Sanctum.
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.4.
 *
 * API:
 *  - issueAccessToken(user, abilities): emite solo el access token.
 *  - issueRefreshToken(user): emite solo el refresh token.
 *  - rotateRefreshToken(refreshToken, expectedScope): valida + rota refresh (R-PKG-014).
 *
 * - Access token: TTL corto (15 min por defecto), lleva `auth_scope` del
 *   usuario en su lista de abilities (JSON serializable) más las
 *   abilities explícitas que se quieran sumar.
 * - Refresh token: TTL largo (7 días por defecto), ability `refresh`.
 *   🔴 Sólo sirve para `/auth/refresh`: `mk.auth` lo rechaza como Bearer, y
 *   `rotateRefreshToken()` rechaza cualquier token sin la ability `refresh`.
 *   Si no, el refresh es una sesión de 7 días y el access se encadena para
 *   siempre (medido en el piloto NetPizza).
 * - Sesión: `issueTokenPair()` liga el access a su refresh con la ability
 *   `refresh_token_id:{id}`, para que logout revoque los dos.
 *
 * Configuración de TTLs (vía `mk_director.auth.ttl.*`):
 *  - `access_seconds`  → default 15 * 60
 *  - `refresh_seconds` → default 7 * 24 * 60 * 60
 *
 * Configuración de rotación (vía `mk_director.auth.refresh.*`):
 *  - `rotate_on_refresh` → default false. Si true, el refresh_token se
 *    invalida después de cada uso (más seguro, recomendado para B2B).
 *
 * Los TTLs también se pueden pasar por constructor (útil para tests).
 */
class TokenIssuer
{
    /**
     * Ability "virtual" que lleva el auth_scope del usuario.
     */
    public const SCOPE_ABILITY_PREFIX = 'auth_scope:';

    /**
     * Ability dedicada al refresh token.
     */
    public const REFRESH_ABILITY = 'refresh';

    /**
     * Ability del access token que apunta al refresh token de su sesión
     * (`refresh_token_id:42`). La lee `AuthUser::safeLogoutCurrentToken()`.
     */
    public const REFRESH_LINK_PREFIX = 'refresh_token_id:';

    public function __construct(
        private readonly ?int $accessTtlSeconds = null,
        private readonly ?int $refreshTtlSeconds = null,
    ) {}

    /**
     * Emite un access token (TTL corto) con `auth_scope` + abilities explícitas.
     *
     * @param  array<int,string>  $abilities  abilities extra a sumar
     */
    public function issueAccessToken(Authenticatable $user, array $abilities = []): NewAccessToken
    {
        $payloadAbilities = $this->buildAccessAbilities($user, $abilities);

        return $user->createToken(
            name: 'access',
            abilities: $payloadAbilities,
            expiresAt: now()->addSeconds($this->accessTtl()),
        );
    }

    /**
     * Emite una sesión: refresh token + access token ligado a él.
     *
     * @return array{access: NewAccessToken, refresh: string}
     */
    public function issueTokenPair(Authenticatable $user): array
    {
        $refresh = $this->issueRefreshToken($user);

        return [
            'access' => $this->issueAccessToken($user, [self::refreshLinkAbility($refresh)]),
            'refresh' => $refresh,
        ];
    }

    /**
     * Emite un refresh token (TTL largo) con ability `refresh`.
     * Devuelve el `plainTextToken` (string) listo para entregar al cliente.
     */
    public function issueRefreshToken(Authenticatable $user): string
    {
        $token = $user->createToken(
            name: 'refresh',
            abilities: [self::REFRESH_ABILITY, $this->scopeAbilityFor($user)],
            expiresAt: now()->addSeconds($this->refreshTtl()),
        );

        return $token->plainTextToken;
    }

    /**
     * Compone la lista final de abilities del access token.
     * `auth_scope` siempre presente, deduplicado. La ability `refresh` se
     * descarta: un access token nunca puede pasar por refresh token.
     *
     * @param  array<int,string>  $abilities
     * @return array<int,string>
     */
    public function buildAccessAbilities(Authenticatable $user, array $abilities): array
    {
        $scopeAbility = $this->scopeAbilityFor($user);

        $abilities = array_filter($abilities, static fn ($ability) => $ability !== self::REFRESH_ABILITY);

        return array_values(array_unique(array_merge([$scopeAbility], $abilities)));
    }

    /**
     * Convierte `auth_scope` del user en una ability (`auth_scope:admin`).
     * Si el user no tiene scope, devuelve `auth_scope:unknown` (señal
     * de mal creación; el login debe rechazarse en otra capa).
     */
    public function scopeAbilityFor(Authenticatable $user): string
    {
        $scope = null;

        // is_callable (no method_exists) para soportar Mockery y duck-typed users.
        if (is_callable([$user, 'getAuthScope'])) {
            $scope = $user->getAuthScope();
        }

        if (! is_string($scope) || $scope === '') {
            $scope = 'unknown';
        }

        return self::SCOPE_ABILITY_PREFIX.$scope;
    }

    /**
     * Extrae el scope a partir de un array de abilities.
     * Usado por AuthScopeResolver y por tests.
     *
     * **R-PKG-046 F9-B06 fix — soporta {key: bool} de Sanctum 4.x**:
     * Sanctum 4.x guarda abilities como objeto JSON `{key: bool}` en
     * `personal_access_tokens.abilities`. El formato pre-fix esperaba
     * flat array de strings:
     *
     *   ['refresh', 'auth_scope:admin', '*']  ← pre-fix OK
     *   ['refresh' => true, 'auth_scope:admin' => true]  ← Sanctum 4.x
     *
     * Con `{key: bool}`, `foreach` lee los **values** (true/false), no las
     * **keys**. El scope nunca se extraía con el formato nuevo.
     *
     * Post-fix: normalizar a un flat list de keys (si string) + values (si string)
     * antes de iterar. Production sigue funcionando porque `issueAccessToken`
     * pine array plano de strings. Solo se rompe el path de testing directo con
     * `createToken(['auth_scope:admin' => true])` — pine manual para tests.
     *
     * @param  array<int|string,mixed>  $abilities  Acepta flat array o assoc map.
     */
    public static function extractScopeFromAbilities(array $abilities): ?string
    {
        foreach (self::flattenAbilities($abilities) as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::SCOPE_ABILITY_PREFIX)) {
                $value = substr($ability, strlen(self::SCOPE_ABILITY_PREFIX));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * ¿Es un refresh token? Por la ability `refresh` O por el nombre
     * `refresh`: cualquiera de las dos marcas alcanza para que `mk.auth` lo
     * rechace como Bearer.
     */
    public static function isRefreshToken(PersonalAccessToken $token): bool
    {
        return $token->name === 'refresh'
            || in_array(self::REFRESH_ABILITY, self::flattenAbilities($token->abilities ?? []), true);
    }

    /**
     * Id del refresh token ligado a un access token, o null si el access se
     * emitió sin sesión (tokens anteriores a `issueTokenPair()`).
     *
     * @param  array<int|string,mixed>  $abilities
     */
    public static function linkedRefreshTokenId(array $abilities): ?int
    {
        foreach (self::flattenAbilities($abilities) as $ability) {
            if (str_starts_with($ability, self::REFRESH_LINK_PREFIX)) {
                $id = substr($ability, strlen(self::REFRESH_LINK_PREFIX));

                return ctype_digit($id) ? (int) $id : null;
            }
        }

        return null;
    }

    /** `refresh_token_id:{id}` a partir del `<id>|<plaintext>` del refresh. */
    private static function refreshLinkAbility(string $refreshPlainText): string
    {
        return self::REFRESH_LINK_PREFIX.explode('|', $refreshPlainText, 2)[0];
    }

    /**
     * R-PKG-046 F9-B06 — normaliza `{key: bool}` (Sanctum 4.x) y la lista
     * plana de strings a una lista plana de strings.
     *
     * @param  array<int|string,mixed>  $abilities
     * @return array<int,string>
     */
    private static function flattenAbilities(array $abilities): array
    {
        $flat = [];
        foreach ($abilities as $key => $value) {
            // Si key es string (assoc array / Sanctum 4.x), tomar la key.
            if (is_string($key)) {
                $flat[] = $key;

                continue;
            }

            // Si key es int (flat array), tomar el value.
            if (is_string($value)) {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    /**
     * Valida un refresh token y emite uno nuevo access token (con refresh opcional).
     *
     * R-PKG-014 BUG-07 fix: implementación completa con Sanctum v4 `id|plaintext` parsing.
     * R-PKG-018 BUG-NEW-26 fix: hash comparison usa `hash_equals(hash('sha256', ...), ...)`
     *                            (Sanctum v4.3.2 hashea tokens con SHA256, NO bcrypt).
     *
     * Pipeline:
     *   1. Parsear `<id>|<plaintext>` (RefreshTokenParser).
     *   2. Buscar `personal_access_tokens` por `id`.
     *   3. Hash comparar `plaintext` contra `token` (Sanctum v4.3.2 usa SHA256).
     *      Ver `vendor/laravel/sanctum/src/HasApiTokens.php:66` y
     *      `PersonalAccessToken.php:61,67`.
     *   3b. Validar que ES un refresh token (ability `refresh`): un access
     *       token no refresca.
     *   4. Validar que el token no expiró.
     *   5. Validar que el scope del token coincide con `$expectedScope` (defense-in-depth).
     *   6. Cargar el `tokenable` (user).
     *   6b. Re-chequear `AccountStatus`: si la cuenta ya no autentica, revocar
     *       este refresh token y rechazar (`ERR_ACCOUNT_DISABLED`).
     *   7. Si `mk_director.auth.refresh.rotate_on_refresh` es true, invalidar el viejo
     *      refresh token y emitir uno nuevo. Si no, mantener el viejo.
     *   8. Emitir nuevo access token ligado al refresh vigente.
     *
     * @param  string  $refreshToken  El `<id>|<plaintext>` recibido del cliente.
     * @param  string  $expectedScope  Scope que el AuthController declara para esta ruta
     *                                 (e.g. `admin`). Previene escalación de scope vía refresh.
     * @return array{access_token: string, refresh_token: string, user_id: string}
     *
     * @throws InvalidRefreshTokenException Si el token es malformado, no existe, no es
     *                                      refresh, expiró, el scope no coincide o la
     *                                      cuenta ya no puede autenticarse.
     */
    public function rotateRefreshToken(string $refreshToken, string $expectedScope): array
    {
        $parser = new RefreshTokenParser;
        [$tokenId, $plaintext] = $parser->parse($refreshToken);

        $tokenModel = PersonalAccessToken::query()->find($tokenId);
        if (! $tokenModel) {
            throw InvalidRefreshTokenException::notFound();
        }

        // Sanctum v4.3.2 hashea los tokens con **SHA256** (NO bcrypt),
        // verificado en:
        //   - vendor/laravel/sanctum/src/HasApiTokens.php:66
        //     `'token' => hash('sha256', $plainTextToken)`
        //   - vendor/laravel/sanctum/src/PersonalAccessToken.php:61,67
        //     usa `hash('sha256', ...)` y `hash_equals(..., hash('sha256', ...))`
        //
        // La columna `personal_access_tokens.token` guarda 64 chars hex (SHA256),
        // no 60 chars como un hash bcrypt.
        //
        // R-PKG-018 BUG-NEW-26 fix (causa raíz): el código previo usaba
        // `Hash::check()` (bcrypt) lo cual SIEMPRE lanzaba
        // `RuntimeException: This password does not use the Bcrypt algorithm`
        // porque el hash guardado es SHA256. El catch de R-PKG-017 BUG-NEW-23
        // mitigaba el 500 → 401, pero el refresh NUNCA funcionaba (incluso
        // con token recién emitido y válido).
        //
        // La fix correcta es `hash_equals` con SHA256, timing-safe y
        // consistente con la implementación interna de Sanctum v4.
        //
        // Defense-in-depth: el try/catch de R-PKG-017 queda en caso de que
        // Sanctum rote a otro algoritmo (bcrypt→argon2→sha512) en el futuro.
        // Hoy es unreachable con Sanctum v4.3.x, pero es un safety net barato.
        try {
            $hashMatches = hash_equals(
                $tokenModel->token,
                hash('sha256', $plaintext),
            );
        } catch (\RuntimeException $e) {
            // Algoritmo desconocido (futuro) → tratar como mismatch.
            // NO relanzar el RuntimeException — eso filtraría 500 al cliente.
            throw InvalidRefreshTokenException::hashMismatch();
        }

        if (! $hashMatches) {
            throw InvalidRefreshTokenException::hashMismatch();
        }

        if (! in_array(self::REFRESH_ABILITY, self::flattenAbilities($tokenModel->abilities ?? []), true)) {
            throw InvalidRefreshTokenException::notARefreshToken();
        }

        // Validar expiración.
        if ($tokenModel->expires_at !== null && $tokenModel->expires_at->isPast()) {
            throw InvalidRefreshTokenException::expired();
        }

        // Validar scope (defense-in-depth: el scope del token debe coincidir con el esperado).
        $tokenScope = self::extractScopeFromAbilities($tokenModel->abilities ?? []);
        if ($tokenScope !== $expectedScope) {
            throw InvalidRefreshTokenException::scopeMismatch($expectedScope, $tokenScope ?? 'null');
        }

        // Cargar el user asociado al token.
        $user = $tokenModel->tokenable;
        if (! $user) {
            throw InvalidRefreshTokenException::notFound();
        }

        if (! AccountStatus::allowsAuthentication($user)) {
            $tokenModel->delete();

            throw InvalidRefreshTokenException::accountDisabled();
        }

        // Decidir rotación del refresh token.
        $rotateOnRefresh = (bool) $this->readConfigInt(
            'mk_director.auth.refresh.rotate_on_refresh',
            0,
        );

        if ($rotateOnRefresh) {
            // Rotar: borrar el viejo, emitir uno nuevo.
            $tokenModel->delete();
            $newRefreshPlaintext = $this->issueRefreshToken($user);
        } else {
            // Mantener el viejo (BC default).
            $newRefreshPlaintext = $refreshToken;
        }

        // Emitir nuevo access token, ligado al refresh vigente (logout revoca los dos).
        $newAccess = $this->issueAccessToken($user, [self::refreshLinkAbility($newRefreshPlaintext)]);

        return [
            'access_token' => $newAccess->plainTextToken,
            'refresh_token' => $newRefreshPlaintext,
            'user_id' => (string) $user->getAuthIdentifier(),
        ];
    }

    private function accessTtl(): int
    {
        if ($this->accessTtlSeconds !== null) {
            return $this->accessTtlSeconds;
        }

        return $this->readConfigInt('mk_director.auth.ttl.access_seconds', 15 * 60);
    }

    private function refreshTtl(): int
    {
        if ($this->refreshTtlSeconds !== null) {
            return $this->refreshTtlSeconds;
        }

        return $this->readConfigInt('mk_director.auth.ttl.refresh_seconds', 7 * 24 * 60 * 60);
    }

    private function readConfigInt(string $key, int $default): int
    {
        if (class_exists(Config::class) && function_exists('app') && $this->containerHasConfig()) {
            $value = Config::get($key);
            if (is_int($value)) {
                return $value;
            }
        }

        return $default;
    }

    private function containerHasConfig(): bool
    {
        try {
            return function_exists('app') && app()->bound('config');
        } catch (\Throwable) {
            return false;
        }
    }
}
