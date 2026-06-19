<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class BaseModelBuilder extends Builder
{
    /**
     * Cache results of the query using tags if supported.
     */
    public function cacheGet($time = 3600)
    {
        $cacheKey = $this->generateCacheKey();
        $table = $this->getModel()->getTable();

        if (config('mk_director.features.auto_cache', false)) {
            return Cache::tags([$table . '_all'])->remember($cacheKey, $time, function () {
                return $this->get();
            });
        }

        return $this->get();
    }

    /**
     * Cache single result of the query.
     */
    public function cacheFirst($time = 3600)
    {
        $cacheKey = $this->generateCacheKey() . '_first';
        $table = $this->getModel()->getTable();

        if (config('mk_director.features.auto_cache', false)) {
            return Cache::tags([$table . '_all'])->remember($cacheKey, $time, function () {
                return $this->first();
            });
        }

        return $this->first();
    }

    /**
     * Generate a unique cache key for the current query.
     */
    protected function generateCacheKey(): string
    {
        $sql = $this->toSql();
        $bindings = serialize($this->getBindings());
        return 'mk_cache_' . md5($sql . $bindings);
    }
}
