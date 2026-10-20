<?php

declare(strict_types=1);

namespace App\Modules\PerfScreens\Http\Controllers;

use App\Modules\PerfScreens\Models\Product;
use Mk\Director\Controllers\SmartController;

/** Two filters (both sortable: they are fillable), one searchable column, and a detail route. */
class ProductController extends SmartController
{
    protected array $mkConfig = [
        'model' => Product::class,
        'searchable' => ['name'],
        'features' => [
            'allowed_filters' => ['status', 'owner_id'],
            'authorize_with_policy' => false,
            'auto_cache' => false,
        ],
    ];
}
