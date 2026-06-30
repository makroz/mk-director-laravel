<?php

declare(strict_types=1);

namespace Mk\Director\Managers;

use Illuminate\Database\Eloquent\Builder;
use Mk\Director\Contracts\SearchManagerInterface;
use Mk\Director\Contracts\SearchStrategyInterface;
use Mk\Director\Strategies\LikeSearchStrategy;

class SearchManager implements SearchManagerInterface
{
    protected ?SearchStrategyInterface $strategy = null;

    /**
     * Set search strategy
     */
    public function setStrategy(SearchStrategyInterface $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    /**
     * Get search strategy
     */
    public function getStrategy(): ?SearchStrategyInterface
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
            fn ($term) => $term !== ''
        );
    }

    /**
     * Apply search to the query
     */
    public function search(Builder $query, string $search, array $searchBy): Builder
    {
        if (! $this->strategy) {
            $this->strategy = new LikeSearchStrategy;
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

    // R-PKG-034 BUG-NEW-35: removed `searchStatic()` + helpers estáticos
    // (`getJoinType`, `getBusqueda`, `isEndGroup`, `resetGroupEnd`,
    // `isStartNestedGroup`, `handleNestedGroup`, `applySearchCriteria`) +
    // constante `REPEATED_COMAS`. Era código legacy de v0.x (pre-CRUDSmart)
    // con naming español (`$busquedas`) y parsing de comma-delimited strings
    // via `strtok()`-equivalente. Confirmado muerto por `grep` cross-package
    // (0 call-sites). Si algún consumer externo dependía de este método,
    // el error será catchado en su test suite con un mensaje accionable
    // (`Call to undefined method Mk\Director\Managers\SearchManager::searchStatic()`).
    // Migración: reescribir a la API moderna `search($query, $search, $searchBy)`
    // + strategy pattern via `setStrategy()`. Ver DEVELOPER_GUIDE §"Search API".
}
