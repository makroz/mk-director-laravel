<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Http\Controllers;

use App\Modules\PerfSlope\Models\Entry;
use Mk\Director\Controllers\SmartController;

/** A list whose hand Resource lazy-loads a relation per row. */
class EntryController extends SmartController
{
    protected array $mkConfig = [
        'model' => Entry::class,
        'features' => ['sorting' => false, 'authorize_with_policy' => false, 'auto_cache' => false],
    ];
}
