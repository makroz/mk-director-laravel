<?php

declare(strict_types=1);

namespace Mk\Director\Perf\Plans;

/**
 * Reads the plan a query RAN with on the scratch database (the query is executed: the numbers are measured, not
 * estimated) and normalizes it, so both engines answer the same questions:
 *
 *  - `fullScan`: some table was read whole (PostgreSQL `Seq Scan`, MariaDB `access_type: ALL`);
 *  - `index`: the first index the plan used, or null;
 *  - `indexes`: every index the plan used, as `table.index` (the run's unused indexes are the others);
 *  - `rowsRead`: rows the scans read, the ones a filter then discarded included, times their loops;
 *  - `sort` / `temp`: an explicit sort, and a sort or table spilled to disk / a temporary table;
 *  - `raw`: the engine's own JSON, untouched.
 *
 * Only SELECTs: both engines execute what they analyze.
 */
interface PlanReader
{
    /** @return array{fullScan: bool, index: ?string, indexes: list<string>, rowsRead: int, sort: bool, temp: bool, raw: mixed} */
    public function read(string $connection, string $sql, array $bindings): array;
}
