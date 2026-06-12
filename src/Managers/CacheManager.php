<?php

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
     */
    public static function flush(array $tags): void
    {
        $store = config('mk_director.cache.store');
        $cache = $store ? Cache::store($store) : Cache::store();

        if (self::storeSupportsTags($cache)) {
            $cache->tags($tags)->flush();
        } else {
            // For environments without Redis (like local), fallback to full clear
            // if configured to be strict, otherwise we only clear what we know if possible.
            // On production, it's highly recommended to use Redis for MkDirector.
            $cache->clear();
        }
    }

    /**
     * Checks if the active driver supports tag flushing
     */
    protected static function storeSupportsTags($cache): bool
    {
        return method_exists($cache->getStore(), 'tags');
    }
}
