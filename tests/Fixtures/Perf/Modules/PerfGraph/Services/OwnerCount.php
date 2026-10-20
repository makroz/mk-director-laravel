<?php

declare(strict_types=1);

namespace App\Modules\PerfGraph\Services;

use Illuminate\Support\Facades\DB;

/** Hand-written code of ANOTHER module: captured, but outside the module measured. */
class OwnerCount
{
    public function total(): int
    {
        return DB::table('perf_owners')->count(); // capture: outside
    }
}
