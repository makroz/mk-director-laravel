<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Http\Controllers;

use App\Modules\PerfSlope\Models\Line;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mk\Director\Controllers\SmartController;

/** The fixed twin of PerRowController: the owners' names in one query for the whole page. */
class BatchedController extends SmartController
{
    protected array $mkConfig = [
        'model' => Line::class,
        'features' => ['sorting' => false, 'authorize_with_policy' => false, 'auto_cache' => false],
    ];

    public function afterList(Request $request, array $items, ?int $total): array
    {
        $names = DB::table('perf_owners')->whereIn('id', array_map(static fn ($i) => $i->owner_id, $items))->pluck('name', 'id');
        foreach ($items as $item) {
            $item->setAttribute('owner_name', $names[$item->owner_id] ?? null);
        }

        return $items;
    }
}
