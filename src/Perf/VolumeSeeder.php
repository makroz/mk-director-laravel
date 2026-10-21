<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Generic volume for the scratch database, from the real columns, foreign keys and CHECK constraints (no factories:
 * most modules have none). It covers the module's relation graph:
 *
 *  - the module's tables and the tables its models list as their children (`comments()` → `mk_comments`);
 *  - every table those reference, transitively, by foreign key or as the target of a polymorphic pair;
 *  - in FK order: parents first, so every foreign key and every `*_type` + `*_id` pair holds a real row.
 *
 * Row counts: the module's leaf tables and the children get the volume's rows; every other table a tenth of its
 * largest child's, at least PARENT_ROWS. Values are skewed: a child picks its parent and a pair its target unevenly (a
 * few parents get most rows, one of them most of all), because uniform data hides bad plans; an enum takes every case,
 * the first ones more often. Inserts are chunked. The volume is the same every run (a fixed seed), so a second run
 * compares like with like.
 *
 * Every column is written, as a real table's rows would have it: one with a default too (a status left at its default
 * would be one value in every row), and a nullable one in three rows of four (a step not every row reached:
 * `released_at`, a link not every row has). `deleted_at` stays empty: the rows are live. A nullable link inside a unique
 * index stays empty once its parents are spent. What a CHECK needs is honored ({@see CheckConstraint}). A row the
 * database still refuses is written alone and retried once with new values (a unique collision); a table that keeps
 * refusing is reported, never fatal. {@see row()} writes only what has to be: the perf admin keeps its defaults.
 */
final class VolumeSeeder
{
    /** Rows of each of the module's leaf tables, and of each child table, per volume. */
    public const ROWS = ['small' => 1000, 'medium' => 20000, 'large' => 200000];

    /** The fewest rows a parent gets. */
    private const PARENT_ROWS = 10;

    private const CHUNK = 500;

    /** Rows refused in a row, with none written yet, before a table is given up. */
    private const GIVE_UP = 20;

    /** Rows refused in a row, after some were written, before a table is taken as full (its uniques are spent). */
    private const SATURATED = 200;

    /** Parent rows a foreign key picks from. */
    private const POOL = 5000;

    private string $connection = '';

    /** @var array<string, string> table => why it has no rows */
    private array $skipped = [];

    /** @var array<string, array{written: int, wanted: int, reason: string}> tables with fewer rows than asked */
    private array $short = [];

    /** @var array<string, list<array<string, mixed>>> "table|col,col" => rows of the referenced columns */
    private array $pools = [];

    /** @var array<string, true> parents being filled right now: a cycle stops here */
    private array $filling = [];

    /** @var array<string, array{columns: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>, checks: list<CheckConstraint>}> */
    private array $meta = [];

    /** @var array<string, array<string, string>> table => column => cast */
    private array $casts = [];

    /** @var array<string, list<array{type: string, id: string, targets: array<string, string>, loose: list<string>}>> table => its polymorphic pairs: alias => table, and the aliases no table is known for */
    private array $morphs = [];

    /**
     * `$graph` is the module's relation graph: other models' casts, the tables its models' has-many / morph-many point
     * at, and every polymorphic pair with its targets (alias => table, null when the morph map does not name it).
     *
     * @param  array<string, array<string, string>>  $tables  the module's own tables => their model's casts
     * @param  array{casts?: array<string, array<string, string>>, children?: list<string>, morphs?: list<array{table: string, type: string, id: string, targets: array<string, ?string>}>}  $graph
     * @return array<string, int> table => rows written, parents first
     */
    public function fill(string $connection, array $tables, string $volume, array $graph = []): array
    {
        $rows = self::ROWS[$volume] ?? throw new InvalidArgumentException("unknown volume {$volume}: small, medium or large.");
        $this->connection = $connection;
        $this->skipped = $this->short = $this->pools = $this->filling = $this->meta = $this->morphs = [];
        $this->casts = [...($graph['casts'] ?? []), ...$tables];
        mt_srand(20261019);

        $schema = Schema::connection($connection);
        $exists = array_flip($schema->getTableListing($schema->getCurrentSchemaListing(), false));
        $own = array_values(array_filter(array_keys($tables), static fn (string $t): bool => isset($exists[$t])));
        $children = array_values(array_filter($graph['children'] ?? [], static fn (string $t): bool => isset($exists[$t])));
        $set = $this->closure(array_values(array_unique([...$own, ...$children])), $graph['morphs'] ?? [], $exists);
        $order = $this->order($set);

        // Fixed: the module's tables no other module table points at, and the children. The rest scale from their children.
        $fixed = array_flip([...array_filter($own, fn (string $t): bool => ! array_filter($own, fn (string $c): bool => $c !== $t && in_array($t, $this->parents($c), true))), ...$children]);
        $count = [];
        foreach (array_reverse($order) as $t) {
            $biggest = 0;
            foreach ($set as $c) {
                if ($c !== $t && in_array($t, $this->parents($c), true)) {
                    $biggest = max($biggest, $count[$c] ?? 0);
                }
            }
            $count[$t] = isset($fixed[$t]) ? $rows : max(self::PARENT_ROWS, intdiv($biggest, 10));
        }

        $out = [];
        foreach ($order as $t) {
            $out[$t] = $this->insert($t, $count[$t]);
        }

        return $out;
    }

    /** @return array<string, string> table => why it could not be filled */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /** @return array<string, array{written: int, wanted: int, reason: string}> tables filled only in part */
    public function short(): array
    {
        return $this->short;
    }

    /**
     * One generic row of `$table` with its foreign keys on real rows (inserting parents when needed): what the perf
     * admin is built from.
     *
     * @param  array<string, string>  $casts
     * @return array<string, mixed>
     */
    public function row(string $connection, string $table, int $i, array $casts = []): array
    {
        $this->connection = $connection;
        $this->casts[$table] ??= $casts;

        return $this->rowOf($table, $i, spread: false);
    }

    /**
     * The tables to fill: `$start` plus what they point at, transitively. A polymorphic pair whose targets include a
     * table already in the set points only there (a module's comments hang on the module's records); otherwise every
     * target joins the set, so each `*_id` names a real row of the table its `*_type` names.
     *
     * @param  list<string>  $start
     * @param  list<array{table: string, type: string, id: string, targets: array<string, ?string>}>  $morphs
     * @param  array<string, int>  $exists
     * @return list<string>
     */
    private function closure(array $start, array $morphs, array $exists): array
    {
        $set = $start;
        for ($k = 0; $k < count($set); $k++) {
            $t = $set[$k];
            $parents = array_column($this->meta($t)['foreignKeys'], 'foreign_table');
            foreach ($morphs as $m) {
                if ($m['table'] !== $t || ! isset($this->meta($t)['columns'][$m['id']])) {
                    continue;
                }
                $targets = array_filter($m['targets'], fn (?string $table): bool => $table !== null && isset($exists[$table]) && $this->fits($t, $m['id'], $table));
                $inside = array_filter($targets, static fn (string $table): bool => in_array($table, $set, true));
                $loose = array_keys(array_filter($m['targets'], static fn (?string $table): bool => $table === null));
                $this->morphs[$t][] = ['type' => $m['type'], 'id' => $m['id'], 'targets' => $inside ?: $targets, 'loose' => $loose];
                array_push($parents, ...array_values($inside ?: $targets));
            }
            foreach ($parents as $p) {
                if ($p !== $t && isset($exists[$p]) && ! in_array($p, $set, true)) {
                    $set[] = $p;
                }
            }
        }

        return $set;
    }

    /** A uuid `*_id` cannot hold an integer key. */
    private function fits(string $table, string $idColumn, string $target): bool
    {
        $key = $this->key($target);
        $id = strtolower((string) $this->meta($table)['columns'][$idColumn]['type_name']);
        $keyType = $key === null ? '' : strtolower((string) $this->meta($target)['columns'][$key]['type_name']);

        return ! ($id === 'uuid' && preg_match('/int|serial/', $keyType) === 1);
    }

    /** The table's single-column primary key, or null. */
    private function key(string $table): ?string
    {
        return $this->meta($table)['key'];
    }

    /** @return list<string> the tables `$table` points at: foreign keys and polymorphic targets, itself left out */
    private function parents(string $table): array
    {
        $out = array_column($this->meta($table)['foreignKeys'], 'foreign_table');
        foreach ($this->morphs[$table] ?? [] as $m) {
            array_push($out, ...array_values($m['targets']));
        }

        return array_values(array_diff(array_unique($out), [$table]));
    }

    /** Parents before children; a cycle keeps the given order. @param list<string> $tables @return list<string> */
    private function order(array $tables): array
    {
        $out = [];
        $visit = function (string $t, array $path) use (&$visit, &$out, $tables): void {
            if (in_array($t, $out, true) || in_array($t, $path, true)) {
                return;
            }
            foreach (array_intersect($this->parents($t), $tables) as $parent) {
                $visit($parent, [...$path, $t]);
            }
            $out[] = $t;
        };
        foreach ($tables as $t) {
            $visit($t, []);
        }

        return $out;
    }

    private function insert(string $table, int $count): int
    {
        $written = 0;
        $refused = 0;
        $error = null;
        for ($start = 1; $start <= $count; $start += self::CHUNK) {
            // Rows that leave different columns to their defaults go in separate statements.
            $groups = [];
            foreach (range($start, min($count, $start + self::CHUNK - 1)) as $i) {
                $row = $this->rowOf($table, $i);
                ksort($row);
                $groups[implode(',', array_keys($row))][$i] = $row;
            }
            foreach ($groups as $rows) {
                try {
                    $this->db()->table($table)->insert(array_values($rows));
                    $written += count($rows);
                    $refused = 0;

                    continue;
                } catch (Throwable $e) {
                    $error = $e; // one row breaks the statement: write them one by one
                }
                foreach ($rows as $i => $row) {
                    foreach ([$row, null] as $attempt) {
                        try {
                            $this->db()->table($table)->insert($attempt ?? $this->rowOf($table, $i, true));
                            $written++;
                            $refused = 0;

                            continue 2;
                        } catch (Throwable $e) {
                            $error = $e;
                        }
                    }
                    if (++$refused >= ($written === 0 ? self::GIVE_UP : self::SATURATED)) {
                        break 3;
                    }
                }
            }
        }
        if ($written === 0 && $error !== null) {
            $this->skipped[$table] = self::reason($error);
        } elseif ($written < $count && $error !== null) {
            $this->short[$table] = ['written' => $written, 'wanted' => $count, 'reason' => self::reason($error)];
        }

        return $written;
    }

    /**
     * `$spread`: every column as a real row has it (the volume); false: only what has to be written (NOT NULL columns
     * without a default, the relations, the timestamps).
     *
     * @return array<string, mixed>
     */
    private function rowOf(string $table, int $i, bool $retry = false, bool $spread = true): array
    {
        // A retry needs other values: a unique column already holds this row's.
        $seed = $retry ? $i + mt_rand(1, 1_000_000) * 1_000_003 : $i;
        $n = $retry ? $i + mt_rand(1_000_000, 2_000_000) : $i;
        $meta = $this->meta($table);
        $casts = $this->casts[$table] ?? [];
        $row = [];
        $references = [];
        // A relation inside a unique index walks its parents in turn (one stats row per learner), the rest are skewed.
        $q = $n - 1;
        foreach ($meta['foreignKeys'] as $p => $fk) {
            array_push($references, ...$fk['columns']);
            $nullable = $spread && array_filter($fk['columns'], static fn (string $c): bool => ! $meta['columns'][$c]['nullable']) === [];
            if ($nullable && $n % 4 === 0) {
                continue;
            }
            if (in_array($p, $meta['spread'], true)) {
                $pool = $this->pool($table, $fk['foreign_table'], $fk['foreign_columns']);
                $parent = $pool === [] || ($nullable && $q >= count($pool)) ? null : $pool[$q % count($pool)];
                $q = intdiv($q, max(1, count($pool)));
            } else {
                $parent = $this->parentRow($table, $fk['foreign_table'], $fk['foreign_columns']);
            }
            foreach ($parent === null ? [] : $fk['columns'] as $k => $column) {
                $row[$column] = $parent[$fk['foreign_columns'][$k]];
            }
        }
        foreach ($this->morphs[$table] ?? [] as $m) {
            $aliases = array_keys($m['targets']);
            if ($aliases === [] && $m['loose'] !== []) {
                // Aliases the morph map does not name (`quiz`, `demo`): stored as they are, the id a generic value.
                $row[$m['type']] = $m['loose'][CheckConstraint::skew(count($m['loose']))];
                $row[$m['id']] = $this->value($meta['columns'][$m['id']], $n, null);

                continue;
            }
            if ($aliases === []) {
                continue;
            }
            array_push($references, $m['type'], $m['id']);
            if (in_array($m['id'], $meta['unique'], true)) {
                // Inside a unique index (one reaction per author): walk every (alias, row) in turn.
                $all = $this->morphPool($table, $m);
                if ($all !== []) {
                    [$row[$m['type']], $row[$m['id']]] = $all[$q % count($all)];
                    $q = intdiv($q, count($all));
                }

                continue;
            }
            $alias = $aliases[CheckConstraint::skew(count($aliases))];
            $key = $this->key($m['targets'][$alias]);
            $parent = $key === null ? null : $this->parentRow($table, $m['targets'][$alias], [$key]);
            if ($parent !== null) {
                $row[$m['type']] = $alias;
                $row[$m['id']] = $parent[$key];
            }
        }
        foreach ($meta['columns'] as $c) {
            $timestamp = in_array($c['name'], ['created_at', 'updated_at'], true);
            // A reference with no parent row stays empty: a made-up id would only fail as a foreign key violation.
            if (array_key_exists($c['name'], $row) || in_array($c['name'], $references, true)
                || $c['auto_increment'] || ($c['generation'] ?? null) !== null || $c['name'] === 'deleted_at'
                || (! $timestamp && ($spread ? $c['nullable'] && $n % 4 === 0 : $c['nullable'] || $c['default'] !== null))) {
                continue;
            }
            $cast = $casts[$c['name']] ?? null;
            if ($cast !== null && in_array($c['name'], $meta['unique'], true) && enum_exists($cast) && ($cases = $cast::cases()) !== []) {
                // An enum inside a unique index (one badge of each kind per learner): its cases in turn too.
                $row[$c['name']] = $cases[$q % count($cases)]->value;
                $q = intdiv($q, count($cases));

                continue;
            }
            $value = $this->value($c, $n, $cast);
            if ($value !== null) {
                $row[$c['name']] = $value;
            }
        }
        if ($meta['checks'] === []) {
            return $row;
        }
        // A column a CHECK needs set: a foreign key takes a real parent, anything else a generic value.
        $fill = function (string $column) use ($table, $meta, $n, $casts): mixed {
            foreach ($meta['foreignKeys'] as $fk) {
                if (($k = array_search($column, $fk['columns'], true)) !== false) {
                    return $this->parentRow($table, $fk['foreign_table'], $fk['foreign_columns'])[$fk['foreign_columns'][$k]] ?? null;
                }
            }

            return isset($meta['columns'][$column]) ? $this->value($meta['columns'][$column], $n, $casts[$column] ?? null) : null;
        };
        // Twice: satisfying one CHECK can break one solved before it.
        foreach ([1, 2] as $pass) {
            foreach ($meta['checks'] as $check) {
                if (! $check->holds($row)) {
                    $row = $check->satisfy($row, $seed, $meta['columns'], $fill) ?? $row;
                }
            }
        }

        return $row;
    }

    /**
     * Every (alias, id) a pair can name, target by target.
     *
     * @param  array{type: string, targets: array<string, string>}  $m
     * @return list<array{0: string, 1: mixed}>
     */
    private function morphPool(string $table, array $m): array
    {
        $cached = 'morph:'.$table.'|'.$m['type'];
        if (isset($this->pools[$cached])) {
            return $this->pools[$cached];
        }
        $out = [];
        foreach ($m['targets'] as $alias => $target) {
            $key = $this->key($target);
            foreach ($key === null ? [] : $this->pool($table, $target, [$key]) as $r) {
                $out[] = [$alias, $r[$key]];
            }
        }

        return $this->pools[$cached] = $out;
    }

    /** A skewed existing row of `$parent`. Null for a self reference, a cycle or an empty parent. */
    private function parentRow(string $table, string $parent, array $columns): ?array
    {
        $pool = $this->pool($table, $parent, $columns);

        return $pool === [] ? null : $pool[CheckConstraint::skew(count($pool))];
    }

    /**
     * Up to POOL rows of `$parent` (filled with a few rows first if empty): the referenced columns only.
     *
     * @return list<array<string, mixed>>
     */
    private function pool(string $table, string $parent, array $columns): array
    {
        if ($parent === $table) {
            return [];
        }
        $key = $parent.'|'.implode(',', $columns);
        if (! isset($this->pools[$key])) {
            if (! $this->db()->table($parent)->exists()) {
                if (isset($this->filling[$parent])) {
                    return [];
                }
                $this->filling[$parent] = true;
                $this->insert($parent, self::PARENT_ROWS);
                unset($this->filling[$parent]);
            }
            $this->pools[$key] = $this->db()->table($parent)->limit(self::POOL)->get($columns)->map(static fn ($r): array => (array) $r)->all();
        }

        return $this->pools[$key];
    }

    /** @param  array<string, mixed>  $c  a column as `Schema::getColumns()` reports it */
    private function value(array $c, int $i, ?string $cast): mixed
    {
        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            $cases = $cast::cases();

            return $cases === [] ? null : $cases[self::spread(count($cases))]->value;
        }
        $type = strtolower((string) $c['type_name']);
        $full = strtolower((string) $c['type']);
        $length = preg_match('/^[a-z ]+\((\d+)\)/', $full, $m) ? (int) $m[1] : null;
        $text = ['char', 'bpchar', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'citext'];

        return match (true) {
            $type === 'uuid', $length === 36 && in_array($type, $text, true) => (string) Str::uuid(),
            $length === 26 && in_array($type, $text, true) => strtolower((string) Str::ulid()),
            in_array($type, ['bool', 'boolean'], true), str_starts_with($full, 'tinyint(1)') => $i % 2 === 0,
            in_array($type, ['int2', 'smallint', 'tinyint'], true) => 1, // enum-like: 1 is every enum's first case
            in_array($type, ['int4', 'int8', 'int', 'integer', 'mediumint', 'bigint'], true) => $i,
            in_array($type, ['numeric', 'decimal', 'float4', 'float8', 'float', 'double', 'real'], true) => self::number($full, $i),
            in_array($type, ['json', 'jsonb'], true) => '{}',
            $type === 'date' => gmdate('Y-m-d', time() - ($i % 365) * 86400),
            in_array($type, ['timestamp', 'timestamptz', 'datetime'], true) => gmdate('Y-m-d H:i:s', time() - $i * 60),
            in_array($type, ['time', 'timetz'], true) => '12:00:00',
            $type === 'enum' && preg_match("/^enum\\('((?:[^']|'')*)'/", $full, $e) === 1 => str_replace("''", "'", $e[1]),
            in_array($type, ['inet', 'cidr'], true) => '127.0.0.1',
            in_array($type, $text, true) => $this->text($c['name'], $i, $length),
            default => null,
        };
    }

    /**
     * A case of `$n`, every one of them in use, the first ones more often (of four: about 50, 21, 16 and 13 %).
     * {@see CheckConstraint::skew()} leaves the last cases of a long enum almost empty.
     */
    private static function spread(int $n): int
    {
        return min($n - 1, (int) floor($n * (mt_rand() / mt_getrandmax()) ** 2));
    }

    /** Unique per row (unique columns hold); a short column gets the row number in base 36, unique up to 36^length. */
    private function text(string $column, int $i, ?int $length): string
    {
        $value = "{$column}-{$i}-".dechex(mt_rand());

        return $length === null || strlen($value) <= $length ? $value : substr(str_pad(base_convert((string) $i, 10, 36), $length, '0', STR_PAD_LEFT), -$length);
    }

    /** Unique per row within the column's precision (`numeric(5,2)` holds up to 999.99). */
    private static function number(string $type, int $i): int|float
    {
        if (! preg_match('/\((\d+),\s*(\d+)\)/', $type, $m)) {
            return $i;
        }
        $scale = 10 ** (int) $m[2];

        return ($i % (10 ** (int) $m[1])) / $scale;
    }

    /** The first line of the database's refusal, without the connection details Laravel appends. */
    private static function reason(Throwable $e): string
    {
        $line = strtok($e->getMessage(), "\n") ?: $e::class;

        return Str::limit(trim(explode(' (Connection:', $line)[0]), 200);
    }

    /**
     * `spread`: the foreign keys (their positions) that are part of a unique or primary index; `key`: the single-column
     * primary key, or null; `unique`: the columns of every unique or primary index.
     *
     * @return array{columns: array<string, array<string, mixed>>, foreignKeys: list<array<string, mixed>>, checks: list<CheckConstraint>, spread: list<int>, key: ?string, unique: list<string>}
     */
    private function meta(string $table): array
    {
        if (isset($this->meta[$table])) {
            return $this->meta[$table];
        }
        $schema = Schema::connection($this->connection);
        $foreignKeys = $schema->getForeignKeys($table);
        $unique = [];
        $key = null;
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['unique'] || $index['primary']) {
                array_push($unique, ...$index['columns']);
            }
            if ($index['primary'] && count($index['columns']) === 1) {
                $key = $index['columns'][0];
            }
        }

        return $this->meta[$table] = [
            'columns' => array_column($schema->getColumns($table), null, 'name'),
            'foreignKeys' => $foreignKeys,
            'checks' => CheckConstraint::of($this->db(), $table),
            'spread' => array_keys(array_filter($foreignKeys, static fn (array $fk): bool => array_intersect($fk['columns'], $unique) !== [])),
            'key' => $key,
            'unique' => array_values(array_unique($unique)),
        ];
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
