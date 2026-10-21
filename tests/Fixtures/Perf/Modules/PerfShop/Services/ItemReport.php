<?php

declare(strict_types=1);

namespace App\Modules\PerfShop\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Hand-written code of the measured module: what the capture must point at, file and line. */
class ItemReport
{
    private bool $warm = false;

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

    /** One query per owner passed in: no query of the call reads the rows the loop walks. */
    public function namesOf(array $ownerIds): array
    {
        $names = [];
        foreach ($ownerIds as $id) {
            $names[] = DB::table('perf_owners')->where('id', $id)->value('name'); // capture: perPassed
        }

        return $names;
    }

    /** The same shape as `perOwner`'s, from another line, once: the repeat is not this line's. */
    public function firstOwner(): ?string
    {
        return DB::table('perf_owners')->where('id', 1)->value('name'); // capture: firstOwner
    }

    /** One read of the same value per item: a per-row read even with the same bindings each time. */
    public function sameOwnerPerItem(): array
    {
        $ids = [];
        foreach (DB::table('perf_items')->orderBy('id')->limit(3)->get() as $item) {
            $ids[] = DB::table('perf_owners')->where('name', 'owner 1')->value('id'); // capture: samePerRow
        }

        return $ids;
    }

    /** Three settings-like reads from three lines through one helper, after one read of the items: no loop. */
    public function threeOwners(): array
    {
        DB::table('perf_items')->orderBy('id')->value('id');

        return [
            $this->ownerNamed('owner 1'),
            $this->ownerNamed('owner 2'),
            $this->ownerNamed('owner 3'),
        ];
    }

    private function ownerNamed(string $name): ?int
    {
        return DB::table('perf_owners')->where('name', $name)->value('id'); // capture: ownerNamed
    }

    /** A command's body: its first run warms what the next runs reuse. */
    public function warmOwner(): ?string
    {
        if (! $this->warm) {
            $this->warm = true;
            DB::table('perf_items')->orderBy('id')->value('name');
        }

        return DB::table('perf_owners')->where('id', 2)->value('name'); // capture: warmOwner
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

    /** A boolean binding: PostgreSQL has no `boolean = integer`, so it must come back a bool. */
    public function active(): array
    {
        return DB::table('perf_items')->where('active', true)->get()->all(); // capture: active
    }

    /** A row lock: replayed without it (the plan runs in a read-only transaction). */
    public function lockOne(): ?object
    {
        return DB::table('perf_items')->where('id', 1)->lockForUpdate()->first(); // capture: lock
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
