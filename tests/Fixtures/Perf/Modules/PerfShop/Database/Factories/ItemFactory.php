<?php

declare(strict_types=1);

namespace App\Modules\PerfShop\Database\Factories;

use Illuminate\Support\Facades\DB;

/** A factory: the tests' setup reads a parent to point at. Not the module's code. */
class ItemFactory
{
    public function definition(): array
    {
        return ['owner_id' => DB::table('perf_owners')->value('id'), 'name' => 'factory item', 'status' => 1];
    }
}
