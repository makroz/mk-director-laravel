<?php

declare(strict_types=1);

namespace Mk\Director\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Interface MkPluginInterface
 * 
 * Defines the contract for all MK-Director plugins.
 */
interface MkPluginInterface
{
    /**
     * Initial boot logic for the plugin.
     */
    public function boot(): void;

    /**
     * Define the requirements for this plugin (fields, config keys, etc).
     * Useful for health-checks and documentation.
     */
    public function getRequirements(): array;

    /**
     * Hook before a query is executed (e.g., in index/show).
     */
    public function beforeQuery(Builder $query, Request $request): void;

    /**
     * Hook before a model is stored/updated.
     * Mode: 'create' or 'update'
     */
    public function beforeSave(Request $request, array &$data, string $mode): void;

    /**
     * Hook after a model is stored/updated.
     * Mode: 'create' or 'update'
     */
    public function afterSave($model, Request $request, string $mode): void;

    /**
     * Hook before a model is deleted.
     */
    public function beforeDelete($model, Request $request): void;

    /**
     * Hook after a model is deleted.
     */
    public function afterDelete($model, Request $request): void;

    /**
     * Hook before a response is returned to the user.
     */
    public function afterResponse(&$responseData): void;
}
