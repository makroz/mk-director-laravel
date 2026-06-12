<?php

namespace Mk\Director\Managers;

class SearchManager implements \Mk\Director\Contracts\SearchManagerInterface
{
    const REPEATED_COMAS = ',,,,,,';

    protected ?\Mk\Director\Contracts\SearchStrategyInterface $strategy = null;

    /**
     * Set search strategy
     */
    public function setStrategy(\Mk\Director\Contracts\SearchStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * Get search strategy
     */
    public function getStrategy(): ?\Mk\Director\Contracts\SearchStrategyInterface
    {
        return $this->strategy;
    }

    /**
     * Parse comma-separated search terms
     */
    public function parse(string $search): array
    {
        if (empty($search)) {
            return [];
        }
        
        return array_filter(
            array_map('trim', explode(',', $search)),
            fn($term) => $term !== ''
        );
    }

    /**
     * Apply search to the query
     */
    public function search(\Illuminate\Database\Eloquent\Builder $query, string $search, array $searchBy): \Illuminate\Database\Eloquent\Builder
    {
        if (!$this->strategy) {
            $this->strategy = new \Mk\Director\Strategies\LikeSearchStrategy();
        }

        $terms = $this->parse($search);

        if (empty($terms) || empty($searchBy)) {
            return $query;
        }

        return $query->where(function ($q) use ($terms, $searchBy) {
            foreach ($terms as $term) {
                foreach ($searchBy as $index => $column) {
                    if ($index === 0) {
                        $this->strategy->apply($q, $column, $term);
                    } else {
                        $this->strategy->applyOr($q, $column, $term);
                    }
                }
            }
        });
    }

    /**
     * Parse and apply search criteria to a query builder (legacy static method).
     */
    public static function searchStatic($model, &$busquedas, &$inicio, $fin, $joins = '')
    {
        for ($i = $inicio; $i < $fin; $i++) {
            $join = self::getJoinType($busquedas, $i);
            $busqueda = self::getBusqueda($busquedas, $i);
            
            if (self::isEndGroup($busqueda)) {
                self::resetGroupEnd($busquedas, $i);
                $inicio = $i + 1;
                return $model;
            }
            
            if (self::isStartNestedGroup($busqueda, $i, $inicio)) {
                $model = self::handleNestedGroup($model, $busquedas, $i, $fin, $joins, $join);
                $inicio = $i;
                continue;
            }
            
            if ($i >= $fin || empty($busqueda[0])) {
                continue;
            }
            
            $model = self::applySearchCriteria($model, $busqueda, $join, $joins);
        }
        $inicio = $i;
        return $model;
    }

    private static function getJoinType($busquedas, $i)
    {
        return $i > 0 ? explode(',', $busquedas[$i - 1] . self::REPEATED_COMAS)[3] : '';
    }

    private static function getBusqueda(&$busquedas, $i)
    {
        return explode(',', $busquedas[$i] . self::REPEATED_COMAS);
    }

    private static function isEndGroup($busqueda)
    {
        return isset($busqueda[4]) && $busqueda[4] == ')*';
    }

    private static function resetGroupEnd(&$busquedas, $i)
    {
        $nBusqueda = explode(',', $busquedas[$i] . self::REPEATED_COMAS);
        $nBusqueda[4] = '';
        $busquedas[$i] = join(',', $nBusqueda);
    }

    private static function isStartNestedGroup($busqueda, $i, $inicio)
    {
        return (isset($busqueda[4]) && (($busqueda[4] == '((' && $i > $inicio) || ($busqueda[4] == '(' && $i >= $inicio)));
    }

    private static function handleNestedGroup($model, &$busquedas, &$i, $fin, $joins, $join)
    {
        $nBusqueda = explode(',', $busquedas[$i] . self::REPEATED_COMAS);
        $nBusqueda[4] = '(';
        $busquedas[$i] = join(',', $nBusqueda);
        $callback = function ($query) use (&$busquedas, &$i, $fin, $joins) {
            return self::searchStatic($query, $busquedas, $i, $fin, $joins);
        };
        return ($join == '' || $join == 'a') ? $model->where($callback) : $model->orWhere($callback);
    }

    private static function applySearchCriteria($model, $busqueda, $join, $joins)
    {
        $column = $busqueda[0];
        $operator = $busqueda[1];
        $value = $busqueda[2] ?? '';

        if (!empty($joins) && !str_contains($column, '.')) {
            $column = $model->getModel()->getTable() . '.' . $column;
        }

        if ($operator == 'l' || $operator == 'like') {
            $operator = 'like';
            $value = "%$value%";
        }

        return ($join == 'o') ? $model->orWhere($column, $operator, $value) : $model->where($column, $operator, $value);
    }
}
