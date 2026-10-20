<?php

declare(strict_types=1);

namespace App\Modules\PerfShop\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Hand-written code of the measured module: what the capture must point at, file and line. */
class ItemReport
{
    /** No index leads with `status`: a full scan on the volume. */
    public function byStatus(int $status): array
    {
        return DB::table('perf_items')->where('status', $status)->get()->all(); // capture: byStatus
    }

    /** One query per item: the same shape again and again from one line. */
    public function ownerNames(): array
    {
        $names = [];
        foreach (DB::table('perf_items')->orderBy('id')->limit(3)->get() as $item) {
            $names[] = DB::table('perf_owners')->where('id', $item->owner_id)->value('name'); // capture: perOwner
        }

        return $names;
    }

    public function rename(int $id, string $name): void
    {
        DB::table('perf_items')->where('id', $id)->update(['name' => $name]);
    }

    /** The same SQL as the count screen's: measured there, not again as code. */
    public function total(): int
    {
        return DB::table('perf_items')->count(); // capture: total
    }

    /** A lookup of the engine's catalog (`pg_attribute`, `information_schema.columns`): not the app's data. */
    public function columns(): array
    {
        return Schema::getColumns('perf_items');
    }

    /** What the connection reports after running `$sql`: a statement not every engine runs (MariaDB has no `with … update`). */
    public function announce(string $sql): void
    {
        event(new QueryExecuted($sql, [], 0.1, DB::connection())); // capture: announce
    }
}
