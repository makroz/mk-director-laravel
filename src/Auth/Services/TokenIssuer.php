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
