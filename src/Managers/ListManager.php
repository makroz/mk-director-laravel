<?php

declare(strict_types=1);

namespace Mk\Director\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * ListManager - Manejo de paginación, filtros y sorting
 * 
 * Encapsula toda la lógica de list management para reutilizar en cualquier controller.
 */
class ListManager
{
    public static function apply(Request $request, Model $model, array $searchable = [], array $allowedIncludes = [], array $allowedWithCount = [], array $features = []): Builder
    {
        $useRemember = $features['remember_state'] ?? config('mk_director.features.remember_state', false);
        if ($useRemember) {
            self::restoreState($request, $model);
        }

        $query = $model->newQuery();

        // 1. Columns selection
        $query = self::applyColumns($request, $query, $model);

        // 2. Joins (if enabled globally - this is internal not frontend param)
        if (config('mk_director.features.dynamic_joins', false)) {
            $query = self::applyJoins($request, $query, $features['allowed_joins'] ?? []);
        }

        // 3. Includes (Dynamic Eager Loading)
        $useIncludes = $features['dynamic_includes'] ?? config('mk_director.features.dynamic_includes', true);
        if ($useIncludes) {
            $query = self::applyIncludes($request, $query, $allowedIncludes, $allowedWithCount);
        }

        // 4. Filters
        $useFilters = $features['filters'] ?? config('mk_director.features.filters', true);
        if ($useFilters) {
            $query = self::applyFilters($request, $query, $features['allowed_filters'] ?? []);
        }

        // 5. Sorting
        $useSorting = $features['sorting'] ?? config('mk_director.features.sorting', true);
        if ($useSorting) {
            $query = self::applySorting($request, $query, $model);
        } else {
            $query->orderBy('id', 'desc');
        }

        // 6. Search
        $useSearch = $features['search'] ?? config('mk_director.features.search', true);
        if ($useSearch) {
            $query = self::applySearch($request, $query, $searchable);
        }

        return $query;
    }

    /**
     * Paginate the query results
     */
    public static function paginate(Builder $query, Request $request): LengthAwarePaginator
    {
        $page = (int) $request->query('page', 1);
        $perPage = self::getPerPage($request);

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Apply column selection
     */
    protected static function applyColumns(Request $request, Builder $query, Model $model): Builder
    {
        $cols = $request->query('cols');
        
        if (!$cols) {
            return $query;
        }

        $columns = explode(',', $cols);
        
        // Validar que las columnas existan en el modelo
        $fillable = $model->getFillable();
        $validColumns = array_filter($columns, function($col) use ($model, $fillable) {
            return in_array($col, $fillable) || $col === 'id';
        });

        if (!empty($validColumns)) {
            return $query->select($validColumns);
        }

        return $query;
    }

    /**
     * Apply dynamic joins
     */
    protected static function applyJoins(Request $request, Builder $query, array $allowedJoins = []): Builder
    {
        $joins = $request->query('joins');

        if (empty($joins) || empty($allowedJoins)) {
            return $query;
        }

        $joinParts = explode('|', $joins);
        $table = $query->getModel()->getTable();

        foreach ($joinParts as $join) {
            $relation = trim($join);

            if (!in_array($relation, $allowedJoins, true)) {
                continue;
            }

            // Simplified: assumes hasOne/belongsTo relationship
            // Real implementation would inspect relationships
            $foreignKey = $table . '.' . Str::singular($relation) . '_id';
            $query->leftJoin($relation, $foreignKey, '=', $relation . '.id');
        }

        return $query;
    }

    protected static function restoreState(Request $request, Model $model): void
    {
        $storageKey = 'mk_list_state_' . $model->getTable();
        $state = [];
        $user = null;

        if ($request->hasSession()) {
            $state = $request->session()->get($storageKey, []);
        } elseif ($user = $request->user()) {
            $storageKey .= '_u' . $user->id;
            $state = \Illuminate\Support\Facades\Cache::get($storageKey, []);
        } else {
            return;
        }

        $modified = false;

        // 1. Search
        if ($request->has('q') || $request->has('search')) {
            $q = $request->query('q', $request->query('search'));
            if ($q === '' || $q === null) {
                unset($state['q'], $state['search']);
                $modified = true;
            } else {
                $state['q'] = $q;
                $modified = true;
            }
        } elseif (isset($state['q'])) {
            $request->query->set('q', $state['q']);
        }

        // 2. Filters
        if ($request->has('filter') || $request->has('filters')) {
            $filters = $request->query('filter', $request->query('filters'));
            if (empty($filters) || $filters === '' || $filters === 'null') {
                unset($state['filter'], $state['filters']);
                $modified = true;
            } else {
                $state['filter'] = $filters;
                $modified = true;
            }
        } elseif (isset($state['filter'])) {
            $request->query->set('filter', $state['filter']);
        }

        // 3. Sorting
        if ($request->has('sort')) {
            $sort = $request->query('sort');
            if ($sort === '' || $sort === null) {
                unset($state['sort']);
                $modified = true;
            } else {
                $state['sort'] = $sort;
                $modified = true;
            }
        } elseif (isset($state['sort'])) {
            $request->query->set('sort', $state['sort']);
        }

        if ($modified) {
            if ($request->hasSession()) {
                $request->session()->put($storageKey, $state);
            } elseif ($user) {
                \Illuminate\Support\Facades\Cache::put($storageKey, $state, 86400);
            }
        }
    }

    public static function applyIncludes(Request $request, Builder $query, array $allowedIncludes = [], array $allowedWithCount = []): Builder
    {
        $include = $request->query('include');
        if (!empty($include) && !empty($allowedIncludes)) {
            $includes = is_array($include) ? $include : explode(',', $include);
            // Trim and intersect to sanitize
            $includes = array_map('trim', $includes);
            $validIncludes = array_intersect($includes, $allowedIncludes);
            if (!empty($validIncludes)) {
                $query->with($validIncludes);
            }
        }

        $withCount = $request->query('with_count', $request->query('withCount'));
        if (!empty($withCount) && !empty($allowedWithCount)) {
            $counts = is_array($withCount) ? $withCount : explode(',', $withCount);
            // Trim and intersect to sanitize
            $counts = array_map('trim', $counts);
            $validCounts = array_intersect($counts, $allowedWithCount);
            if (!empty($validCounts)) {
                $query->withCount($validCounts);
            }
        }

        return $query;
    }

    public static function applyFilters(Request $request, Builder $query, array $allowedFilters = []): Builder
    {
        $filters = $request->query('filter', $request->query('filters'));

        if (empty($filters) || !is_array($filters) || empty($allowedFilters)) {
            return $query;
        }

        foreach ($filters as $field => $value) {
            if ($value === '' || $value === null) {
                continue;
            }

            if (!in_array($field, $allowedFilters, true)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $op => $val) {
                    self::applyFilterOperator($query, $field, $op, $val);
                }
            } else {
                $query->where($field, '=', $value);
            }
        }

        return $query;
    }

    protected static function applyFilterOperator(Builder $query, string $field, string $op, $val) 
    {
        $operators = [
            'eq' => '=', 'neq' => '!=',
            'gt' => '>', 'gte' => '>=',
            'lt' => '<', 'lte' => '<=',
            'like' => 'like',
            'in' => 'in', 'not_in' => 'not in'
        ];

        $sqlOp = $operators[$op] ?? '=';

        if ($sqlOp === 'in') {
            $query->whereIn($field, explode(',', $val));
        } elseif ($sqlOp === 'not in') {
            $query->whereNotIn($field, explode(',', $val));
        } elseif ($sqlOp === 'like') {
            $query->where($field, 'like', "%{$val}%");
        } else {
            $query->where($field, $sqlOp, $val);
        }
    }

    /**
     * Apply sorting
     */
    public static function applySorting(Request $request, Builder $query, Model $model): Builder
    {
        $sort = $request->query('sort', $request->query('sortBy'));
        $dir = $request->query('dir', $request->query('orderBy', 'desc'));
        
        if (!$sort) {
            return $query->orderBy('id', 'desc');
        }

        $sortFields = is_array($sort) ? $sort : explode(',', $sort);
        $fillable = array_merge($model->getFillable(), ['id', 'created_at', 'updated_at']);
        
        foreach ($sortFields as $field) {
            $field = trim($field);
            $currentDir = $dir;
            
            // Allow '-field' notation for desc
            if (str_starts_with($field, '-')) {
                $field = substr($field, 1);
                $currentDir = 'desc';
            }
            
            if (in_array($field, $fillable)) {
                $currentDir = strtolower($currentDir) === 'asc' ? 'asc' : 'desc';
                $query->orderBy($field, $currentDir);
            }
        }

        // Fallback si no hay órdenes válidos
        if (empty($query->getQuery()->orders)) {
             $query->orderBy('id', 'desc');
        }

        return $query;
    }

    protected static function applySearch(Request $request, Builder $query, array $searchable): Builder
    {
        $search = $request->query('q', $request->query('search'));
        
        if (empty($search) || empty($searchable)) {
            return $query;
        }

        $minChars = config('mk_director.search.min_chars', 3);
        if (strlen($search) < $minChars) {
            return $query;
        }

        $query->where(function (Builder $q) use ($searchable, $search) {
            foreach ($searchable as $index => $field) {
                $isFirst = $index === 0;

                if (str_contains($field, '.')) {
                    $parts = explode('.', $field);
                    $relationColumn = array_pop($parts);
                    $relationName = implode('.', $parts);

                    $clause = function ($relQuery) use ($relationColumn, $search) {
                        $relQuery->where($relationColumn, 'like', "%{$search}%");
                    };

                    if ($isFirst) {
                        $q->whereHas($relationName, $clause);
                    } else {
                        $q->orWhereHas($relationName, $clause);
                    }
                } else {
                    if ($isFirst) {
                        $q->where($field, 'like', "%{$search}%");
                    } else {
                        $q->orWhere($field, 'like', "%{$search}%");
                    }
                }
            }
        });

        return $query;
    }

    /**
     * Get perPage with validation
     */
    public static function getPerPage(Request $request): int
    {
        $default = config('mk_director.list.default_per_page', 15);
        $max = config('mk_director.list.max_per_page', 100);
        
        $perPage = $request->query('per_page', $request->query('perPage', $request->query('limit', $default)));
        
        // Validar rango
        $perPage = max(1, min((int) $perPage, $max));
        
        return $perPage;
    }

    /**
     * Get total from paginator for extraData
     */
    public static function getExtraData(LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
            'hasMorePages' => $paginator->hasMorePages(),
        ];
    }
}
