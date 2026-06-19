<?php

declare(strict_types=1);

namespace Mk\Director\Strategies;

use Illuminate\Database\Eloquent\Builder;
use Mk\Director\Contracts\SearchStrategyInterface;

/**
 * Exact Search Strategy - Búsqueda exacta = term
 */
class ExactSearchStrategy implements SearchStrategyInterface
{
    public function apply(Builder $query, string $column, string $value): Builder
    {
        return $query->where($column, '=', $value);
    }

    public function applyOr(Builder $query, string $column, string $value): Builder
    {
        return $query->orWhere($column, '=', $value);
    }
}
