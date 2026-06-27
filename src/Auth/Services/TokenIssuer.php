<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

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

    public function __construct(
        private readonly ?int $accessTtlSeconds = null,
        private readonly ?int $refreshTtlSeconds = null,
    ) {
    }

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
     * `auth_scope` siempre presente, deduplicado.
     *
     * @param  array<int,string>  $abilities
     * @return array<int,string>
     */
    public function buildAccessAbilities(Authenticatable $user, array $abilities): array
    {
        $scopeAbility = $this->scopeAbilityFor($user);

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

        return self::SCOPE_ABILITY_PREFIX . $scope;
    }

    /**
     * Extrae el scope a partir de un array de abilities.
     * Usado por AuthScopeResolver y por tests.
     *
     * @param  array<int,mixed>  $abilities
     */
    public static function extractScopeFromAbilities(array $abilities): ?string
    {
        foreach ($abilities as $ability) {
            if (is_string($ability) && str_starts_with($ability, self::SCOPE_ABILITY_PREFIX)) {
                $value = substr($ability, strlen(self::SCOPE_ABILITY_PREFIX));
                return $value !== '' ? $value : null;
            }
        }

        return null;
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
     *   4. Validar que el token no expiró.
     *   5. Validar que el scope del token coincide con `$expectedScope` (defense-in-depth).
     *   6. Cargar el `tokenable` (user).
     *   7. Emitir nuevo access token con abilities del user.
     *   8. Si `mk_director.auth.refresh.rotate_on_refresh` es true, invalidar el viejo
     *      refresh token y emitir uno nuevo. Si no, mantener el viejo.
     *
     * @param  string  $refreshToken  El `<id>|<plaintext>` recibido del cliente.
     * @param  string  $expectedScope  Scope que el AuthController declara para esta ruta
     *                                  (e.g. `admin`). Previene escalación de scope vía refresh.
     * @return array{access_token: string, refresh_token: string, user_id: string}
     *
     * @throws InvalidRefreshTokenException Si el token es malformado, no existe, expiró,
     *                                      o el scope no coincide.
     */
    public function rotateRefreshToken(string $refreshToken, string $expectedScope): array
    {
        $parser = new RefreshTokenParser();
        [$tokenId, $plaintext] = $parser->parse($refreshToken);

        $tokenModel = \Laravel\Sanctum\PersonalAccessToken::query()->find($tokenId);
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

        // Emitir nuevo access token.
        $newAccess = $this->issueAccessToken($user);

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
        if (class_exists(\Illuminate\Support\Facades\Config::class) && function_exists('app') && $this->containerHasConfig()) {
            $value = \Illuminate\Support\Facades\Config::get($key);
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
