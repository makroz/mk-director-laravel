<?php

declare(strict_types=1);

namespace Mk\Director\Strategies;

use Illuminate\Database\Eloquent\Builder;
use Mk\Director\Contracts\SearchStrategyInterface;

/**
 * Like Search Strategy - Búsqueda con LIKE %term%
 */
class LikeSearchStrategy implements SearchStrategyInterface
{
    public function apply(Builder $query, string $column, string $value): Builder
    {
        // R2-014: escape LIKE wildcards so the value is treated as a
        // literal string. Without this, a search for '50%' would match
        // every row that contains '50' because '%' is the SQL LIKE
        // wildcard.
        $escaped = addcslashes($value, '\\%_');
        return $query->where($column, 'like', "%{$escaped}%");
    }

    public function applyOr(Builder $query, string $column, string $value): Builder
    {
        $escaped = addcslashes($value, '\\%_');
        return $query->orWhere($column, 'like', "%{$escaped}%");
    }
}
