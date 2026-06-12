<?php

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Mk\Director\Models\BaseModelBuilder;
use Illuminate\Support\Facades\Auth;

abstract class Model extends EloquentModel
{
    use HasFactory;

    /**
     * The API Resource class used for automatic transformation.
     * @var string|null
     */
    public $apiResource = null;

    /**
     * Override to use the MK-Director Custom Builder.
     */
    public function newEloquentBuilder($query)
    {
        return new BaseModelBuilder($query);
    }

    /**
     * Helper to get columns for the model, useful for parameterized lists.
     */
    public function getMkColumns(array $except = [], array $add = []): array
    {
        $columns = $this->getFillable();
        $columns = array_diff($columns, $except);
        return array_merge($columns, $add, ['id']);
    }

    /**
     * Standard implementation for Activity Logging if the feature is enabled.
     */
    public function getActivitylogOptions(): mixed
    {
        // This will be implemented if the Spatie Activitylog plugin is active
        return null;
    }
}
