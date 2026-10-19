<?php

declare(strict_types=1);

namespace App\Modules\PerfShop\Http\Controllers;

use App\Modules\PerfShop\Models\Item;
use Mk\Director\Controllers\SmartController;

class ItemController extends SmartController
{
    protected array $mkConfig = [
        'model' => Item::class,
        'features' => ['authorize_with_policy' => false, 'auto_cache' => false],
    ];
}
