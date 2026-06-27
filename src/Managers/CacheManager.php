<?php

declare(strict_types=1);

namespace Mk\Director\Managers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CacheManager 
{
    /**
     * Caches the output of a callable using determinist URLs.
     */
    public static function remember(string $cacheKey, array $tags, int $ttlSeconds, callable $callback)
    {
        $store = config('mk_director.cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();
        
        $key = 'mk_dir_' . $cacheKey;

        if (self::storeSupportsTags($cache)) {
            return $cache->tags($tags)->remember($key, $ttlSeconds, $callback);
        }

        // Fallback for file/database cache drivers
        $fallbackKey = $tags[0] . '_' . $key;
        return $cache->remember($fallbackKey, $ttlSeconds, $callback);
    }

    /**
     * Invalidate specific Cache Tags
     *
     * R-PKG-024 (rc13): the fallback path when the cache driver does NOT
     * support tags was previously `$cache->clear()` — which wipes the
     * ENTIRE application cache, not just the keys for the requested
     * tags. That's a "nuke" — destructive in production where multiple
     * modules share the same cache store.
     *
     * The new behavior:
     *   1. If the driver supports tags (Redis, Memcached) — call
     *      `$cache->tags($tags)->flush()`. This is the recommended path.
     *   2. If the driver does NOT support tags AND
     *      `mk_director.cache.allow_full_clear` is `false` (rc13 default) —
     *      throw a `RuntimeException` with an actionable message.
     *   3. If the driver does NOT support tags AND the flag is `true` —
     *      call `$cache->clear()` (legacy behavior, useful for dev with
     *      file/database cache).
     *
     * Production MUST use a cache store that supports tags. See
     * `cache.store` config and `mk_director.cache.allow_full_clear` flag.
     */
    public static function flush(array $tags): void
    {
        $store = config('mk_director.cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        if (self::storeSupportsTags($cache)) {
            $cache->tags($tags)->flush();
            return;
        }

        // R-PKG-024 (rc13): the legacy fallback `$cache->clear()` nuke
        // is gated with the new `allow_full_clear` config flag. Default
        // is `false` (safe) — production environments that hit this
        // branch have a misconfigured cache store.
        if (! config('mk_director.cache.allow_full_clear', false)) {
            throw new \RuntimeException(sprintf(
                'CacheManager::flush: cache driver does not support tags and ' .
                'mk_director.cache.allow_full_clear is false. ' .
                'Recommended: configure a cache store that supports tags (Redis, Memcached). ' .
                'Alternatively, set MK_CACHE_ALLOW_FULL_CLEAR=true in dev environments that use ' .
                'file/database cache. Tags: %s',
                implode(', ', $tags)
            ));
        }

        // Legacy dev-only path. Only reachable when `allow_full_clear` is true.
        $cache->clear();
    }

    /**
     * Checks if the active driver supports tag flushing
     */
    protected static function storeSupportsTags($cache): bool
    {
        return method_exists($cache->getStore(), 'tags');
    }
}
