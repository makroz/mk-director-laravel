<?php

namespace Mk\Director\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

interface ListManagerInterface
{
    /**
     * Apply list management (pagination, filters, sorting, search)
     */
    public function apply(Request $request, Model $model): \Illuminate\Database\Eloquent\Builder;

    /**
     * Paginate the query results
     */
    public function paginate(\Illuminate\Database\Eloquent\Builder $query, Request $request): LengthAwarePaginator;

    /**
     * Apply filters to the query
     */
    public function applyFilters(\Illuminate\Database\Eloquent\Builder $query, array $filters): \Illuminate\Database\Eloquent\Builder;

    /**
     * Apply sorting to the query
     */
    public function applySorting(\Illuminate\Database\Eloquent\Builder $query, ?string $sortBy, ?string $orderBy): \Illuminate\Database\Eloquent\Builder;
}
