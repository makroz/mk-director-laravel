<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Http\Controllers;

use App\Modules\PerfSlope\Models\Line;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mk\Director\Controllers\SmartController;

/** The N+1: one query per listed row for its owner's name. */
class PerRowController extends SmartController
{
    protected array $mkConfig = [
        'model' => Line::class,
        'features' => ['sorting' => false, 'authorize_with_policy' => false, 'auto_cache' => false],
    ];

    public function afterList(Request $request, array $items, ?int $total): array
    {
        foreach ($items as $item) {
            $item->setAttribute('owner_name', DB::table('perf_owners')->where('id', $item->owner_id)->value('name'));
        }

        return $items;
    }
}
