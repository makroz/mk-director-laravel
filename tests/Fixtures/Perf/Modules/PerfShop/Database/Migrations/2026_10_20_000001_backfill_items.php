<?php

declare(strict_types=1);

use App\Modules\PerfShop\Services\ItemReport;
use Illuminate\Support\Facades\DB;

/** A data migration: `RefreshDatabase` runs it before the tests. Setup, not the module's code. */
return new class
{
    public function up(): void
    {
        DB::table('perf_items')->where('status', 2)->first();
    }

    /** A migration that calls the module's service: the service's line is the module's code. */
    public function viaService(): void
    {
        (new ItemReport)->byStatus(1);
    }
};
