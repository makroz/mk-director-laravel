<?php

namespace Mk\Director\Traits;

use Illuminate\Support\Facades\Cache;
use Mk\Director\Utils\Logger;

trait CacheTrait
{
    /**
     * Flush all cache associated with this model's table.
     */
    public function cacheFlush()
    {
        $table = $this->getTable();
        Cache::tags([$table . '_all'])->flush();
        Logger::log("MK-Director: Cache flushed manually for table [{$table}].");
    }

    /**
     * Get a specific record by ID with caching.
     */
    public static function cacheFind($id, $time = 3600)
    {
        $model = new static;
        $table = $model->getTable();

        if (config('mk_director.features.auto_cache', false)) {
            return Cache::tags([$table . '_all'])->remember($table . '_' . $id, $time, function () use ($id) {
                return static::find($id);
            });
        }

        return static::find($id);
    }
}
