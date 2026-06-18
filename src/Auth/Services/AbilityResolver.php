<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * AbilityResolver — central authority for "can this user do X?".
 *
 * Spec: MK-LAR-1.0.5 + audit-2026-06-17-R4-001.
 *
 * Why this service exists:
 *  - The original HasAbilities::canMk executed DB queries on every call,
 *    leading to N+1 patterns in policy checks and middleware stacks
 *    (audit R4-001).
 *  - It also had no integration with Sanctum's `tokenCan()`, so abilities
 *    encoded into the access token were ignored even when they should
 *    short-circuit the DB query (R4-001 + R2-002).
 *
 * What this service does:
 *  1. If the user has a currentAccessToken() with a `can($ability)` method
 *     (Sanctum-style), and that returns true → grant immediately. No DB.
 *  2. Otherwise, look up the resolved ability names (DB query path) and
 *     cache them under a per-user key.
 *  3. Subsequent calls within the TTL hit the cache.
 *  4. {@see invalidate()} is called by HasAbilities::assignRole/revokeRole
 *     /giveAbilityTo to clear the cache when the underlying grants change.
 *
 * The DB lookup is intentionally delegated to a callable (`$loader`)
 * instead of the resolver knowing about HasAbilities directly — that
 * keeps the resolver framework-agnostic and unit-testable.
 */
class AbilityResolver
{
    /**
     * Default TTL (seconds) for the per-user ability cache.
     */
    public const DEFAULT_TTL = 300;

    /**
     * @param  callable(Authenticatable): array<int, string>  $loader
     *         Resolves the user's ability names from the DB. Injected by
     *         the service provider so this class stays framework-agnostic.
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly int $ttl = self::DEFAULT_TTL,
        private $loader = null,
    ) {
    }

    /**
     * Set the loader callable. Called by the service provider because the
     * loader depends on the live HasAbilities trait which is not visible
     * until AuthServiceProvider boots.
     */
    public function setLoader(callable $loader): void
    {
        $this->loader = $loader;
    }

    /**
     * Can `$user` perform `$ability`?
     *
     * Resolution order:
     *   1. Sanctum-style tokenCan() short-circuit (if currentAccessToken()->can() exists).
     *   2. Cache hit.
     *   3. Loader (DB query) + cache write.
     */
    public function can(Authenticatable $user, string $ability): bool
    {
        // 1) Sanctum short-circuit: a token that grants the ability directly
        //    wins over anything the user has via roles/direct-grants.
        $token = method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if ($token !== null && method_exists($token, 'can')) {
            if ((bool) $token->can($ability) === true) {
                return true;
            }
        }

        // 2) Cache hit.
        $key = self::cacheKey($user);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            return $this->matchAbility($cached, $ability);
        }

        // 3) Loader (DB query).
        $names = $this->loadFromSource($user);

        // Persist for subsequent calls. We swallow exceptions so a broken
        // cache backend does not block authz checks — fail open at this
        // layer, the next call will retry.
        try {
            $this->cache->put($key, $names, $this->ttl);
        } catch (\Throwable) {
            // ignore
        }

        return $this->matchAbility($names, $ability);
    }

    /**
     * Drop the cached ability set for `$user`. Called by
     * HasAbilities::{assignRole,revokeRole,giveAbilityTo,revokeAbilityTo}.
     */
    public function invalidate(Authenticatable $user): void
    {
        $this->cache->forget(self::cacheKey($user));
    }

    /**
     * Cache key for the user's resolved ability set.
     *
     * Public so the test suite and HasAbilities can compute the same key
     * for invalidation hooks.
     */
    public static function cacheKey(Authenticatable $user): string
    {
        $type = method_exists($user, 'getAuthIdentifierName')
            ? $user->getAuthIdentifierName()
            : 'id';

        $id = method_exists($user, 'getAuthIdentifier')
            ? (string) $user->getAuthIdentifier()
            : (string) ($user->getKey() ?? 'unknown');

        return 'mk_abilities:' . $type . ':' . $id;
    }

    /**
     * @param  array<int, string>  $names
     */
    private function matchAbility(array $names, string $ability): bool
    {
        $collection = collect($names);

        if ($collection->contains('*')) {
            return true;
        }

        if ($collection->contains($ability)) {
            return true;
        }

        $segments = explode('.', $ability, 2);
        $resource = $segments[0] ?? null;

        if ($resource !== null && $resource !== '' && $collection->contains($resource . '.*')) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function loadFromSource(Authenticatable $user): array
    {
        if (is_callable($this->loader)) {
            $result = ($this->loader)($user);
            return is_array($result) ? array_values(array_unique($result)) : [];
        }

        // Fallback for tests / sandbox that do not register a loader:
        // try the HasAbilities-style direct + roles path. We swallow any
        // errors (e.g. missing tables in unit context) and return empty.
        $names = [];

        if (method_exists($user, 'directAbilities')) {
            try {
                $names = array_merge(
                    $names,
                    (array) $user->directAbilities()->pluck('abilities.name')->all(),
                );
            } catch (\Throwable) {
                // ability_user table not published — feature off.
            }
        }

        if (method_exists($user, 'roles')) {
            try {
                $roleIds = $user->roles()->pluck('roles.id')->all();
                if (! empty($roleIds) && class_exists(\Mk\Director\Auth\Models\Ability::class)) {
                    $roleNames = \Mk\Director\Auth\Models\Ability::query()
                        ->whereIn('id', function ($q) use ($roleIds) {
                            $q->select('ability_id')
                                ->from('ability_role')
                                ->whereIn('role_id', $roleIds);
                        })
                        ->pluck('name')
                        ->all();
                    $names = array_merge($names, $roleNames);
                }
            } catch (\Throwable) {
                // roles/ability_role tables not published.
            }
        }

        return array_values(array_unique($names));
    }
}