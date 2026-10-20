<?php

declare(strict_types=1);

namespace Mk\Director\Perf\Plans;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mk\Director\Perf\QueryRecorder;

/**
 * `ANALYZE FORMAT=JSON`: MariaDB runs the statement and reports what each table access really read (`r_rows` per
 * loop, `r_loops`). It EXECUTES the statement, so only a SELECT gets here.
 *
 * `access_type: ALL` is a full scan. `index` (every entry of one index, e.g. a `count(*)` with no `where`) is not
 * marked as one: it is the same work as a PostgreSQL `Index Only Scan` of the whole index, and `rowsRead` shows it.
 */
final class MariaDbPlanReader implements PlanReader
{
    public function read(string $connection, string $sql, array $bindings): array
    {
        if (! QueryRecorder::isSelect($sql)) {
            throw new InvalidArgumentException('only a SELECT is analyzed: the engine executes it.');
        }
        $db = DB::connection($connection);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new InvalidArgumentException("the connection {$connection} is not MariaDB.");
        }
        $raw = $db->getQueryGrammar()->substituteBindingsIntoRawSql($sql, QueryRecorder::bindable($db, $bindings));
        $row = (array) $db->selectOne('analyze format=json '.$raw);
        $json = json_decode((string) reset($row), true, flags: JSON_THROW_ON_ERROR);
        $out = ['fullScan' => false, 'index' => null, 'indexes' => [], 'rowsRead' => 0, 'sort' => false, 'temp' => false];
        $this->walk($json, $out);
        $out['indexes'] = array_values(array_unique($out['indexes']));

        return [...$out, 'raw' => $json];
    }

    private function walk(mixed $node, array &$out): void
    {
        if (! is_array($node)) {
            return;
        }
        if (isset($node['table']['access_type'])) {
            $t = $node['table'];
            if ($t['access_type'] === 'ALL') {
                $out['fullScan'] = true;
            } elseif (isset($t['key'])) {
                $out['index'] ??= $t['key'];
                $out['indexes'][] = "{$t['table_name']}.{$t['key']}";
            }
            // `r_rows` is per loop; a `const` table is read while planning and reports only the estimate.
            $out['rowsRead'] += (int) round(($t['r_rows'] ?? $t['rows'] ?? 0) * ($t['r_loops'] ?? 1));
        }
        if (array_key_exists('filesort', $node) || ($node['using_filesort'] ?? false) === true) {
            $out['sort'] = true;
        }
        if (array_key_exists('temporary_table', $node) || ($node['using_temporary_table'] ?? false) === true) {
            $out['temp'] = true;
        }
        foreach ($node as $child) {
            $this->walk($child, $out);
        }
    }
}
