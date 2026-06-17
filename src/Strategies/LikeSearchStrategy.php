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
        return $query->where($column, 'like', "%{$value}%");
    }

    public function applyOr(Builder $query, string $column, string $value): Builder
    {
        return $query->orWhere($column, 'like', "%{$value}%");
    }
}
