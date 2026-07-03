<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Mk\Director\Managers\CacheManager;
use Mk\Director\Utils\Logger;

trait CacheTrait
{
    /**
     * Flush all cache associated with this model's table.
     */
    public function cacheFlush()
    {
        $table = $this->getTable();
        CacheManager::flush([$table]);
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
            return CacheManager::remember($table . '_' . $id, [$table], $time, function () use ($id) {
                return static::find($id);
            });
        }

        return static::find($id);
    }
}
