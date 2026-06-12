<?php

namespace Mk\Director\Contracts;

use Illuminate\Database\Eloquent\Builder;

interface SearchManagerInterface
{
    /**
     * Apply search to the query
     */
    public function search(Builder $query, string $search, array $searchBy): Builder;

    /**
     * Set search strategy
     */
    public function setStrategy(SearchStrategyInterface $strategy): self;
}
