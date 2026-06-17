<?php

declare(strict_types=1);

namespace Mk\Director\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Interface for search strategies (Strategy Pattern)
 */
interface SearchStrategyInterface
{
    /**
     * Apply the search strategy to a column
     */
    public function apply(Builder $query, string $column, string $value): Builder;
}
