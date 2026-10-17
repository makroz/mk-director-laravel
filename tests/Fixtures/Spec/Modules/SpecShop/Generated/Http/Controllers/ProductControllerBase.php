<?php

declare(strict_types=1);

namespace App\Modules\SpecShop\Generated\Http\Controllers;

use App\Modules\SpecShop\Models\Product;
use Mk\Director\Controllers\SmartController;

abstract class ProductControllerBase extends SmartController
{
    protected array $mkConfig = [
        'model' => Product::class,
        'searchable' => ['name'],
        'features' => ['allowed_filters' => ['status'], 'pagination_type' => 'length_aware'],
    ];
}
