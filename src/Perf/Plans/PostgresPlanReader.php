<?php

declare(strict_types=1);

namespace Mk\Director\Perf\Plans;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mk\Director\Perf\QueryRecorder;

/**
 * `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` inside `BEGIN READ ONLY … ROLLBACK`: the query runs (ANALYZE), and
 * nothing it could do survives.
 *
 * The bindings are written into the SQL (with the connection's own quoting) instead of bound: EXPLAIN is not a
 * preparable statement, so a `$1` in it has no type to infer.
 */
final class PostgresPlanReader implements PlanReader
{
    private const SCANS = ['Seq Scan', 'Index Scan', 'Index Only Scan', 'Bitmap Heap Scan'];

    public function read(string $connection, string $sql, array $bindings): array
    {
        if (! QueryRecorder::isSelect($sql)) {
            throw new InvalidArgumentException('only a SELECT is analyzed: the engine executes it.');
        }
        $db = DB::connection($connection);
        if ($db->getDriverName() !== 'pgsql') {
            throw new InvalidArgumentException("the connection {$connection} is not PostgreSQL.");
        }
        $raw = $db->getQueryGrammar()->substituteBindingsIntoRawSql($sql, QueryRecorder::bindable($db, $bindings));
        $db->beginTransaction();
        try {
            $db->statement('set transaction read only');
            $row = (array) $db->selectOne('explain (analyze, buffers, format json) '.$raw);
        } finally {
            $db->rollBack();
        }
        $json = json_decode((string) reset($row), true, flags: JSON_THROW_ON_ERROR);
        $out = ['fullScan' => false, 'index' => null, 'indexes' => [], 'rowsRead' => 0, 'sort' => false, 'temp' => false];
        $this->walk($json[0]['Plan'], null, $out);
        $out['indexes'] = array_values(array_unique($out['indexes']));

        return [...$out, 'raw' => $json];
    }

    /** `$relation`: the nearest scan above, for a `Bitmap Index Scan` (it names its index, its parent the table). */
    private function walk(array $node, ?string $relation, array &$out): void
    {
        $type = (string) ($node['Node Type'] ?? '');
        $relation = $node['Relation Name'] ?? $relation;
        if ($type === 'Seq Scan') {
            $out['fullScan'] = true;
        }
        if (isset($node['Index Name'])) {
            $out['index'] ??= $node['Index Name'];
            $out['indexes'][] = "{$relation}.{$node['Index Name']}";
        }
        if (in_array($type, self::SCANS, true)) {
            $read = ($node['Actual Rows'] ?? 0) + ($node['Rows Removed by Filter'] ?? 0) + ($node['Rows Removed by Index Recheck'] ?? 0);
            $out['rowsRead'] += (int) round($read * ($node['Actual Loops'] ?? 1));
        }
        if (in_array($type, ['Sort', 'Incremental Sort'], true)) {
            $out['sort'] = true;
        }
        if (($node['Sort Space Type'] ?? null) === 'Disk' || ($node['Temp Written Blocks'] ?? 0) > 0) {
            $out['temp'] = true;
        }
        foreach ($node['Plans'] ?? [] as $child) {
            $this->walk($child, $relation, $out);
        }
    }
}
