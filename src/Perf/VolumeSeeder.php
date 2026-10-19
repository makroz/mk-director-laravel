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
 * Generic volume for the scratch database, from the real columns and foreign keys (no factories: most modules
 * have none). This version fills a fixed number of rows in each of the module's own tables, parents first. A
 * table the module points at from outside gets a few rows, so every foreign key holds a real id.
 *
 * Only what has to be written is: NOT NULL columns without a default, every foreign key (nullable too), and the
 * timestamps. An enum column takes the first case of the model's cast. A table that cannot be filled (a CHECK,
 * a unique the generic values break, a type it does not know) is reported in `skipped()`, never fatal.
 *
 * ponytail: uniform values and one row count for every table; skew, the relation graph and the medium/large
 * volumes are the next slice.
 */
final class VolumeSeeder
{
    public const ROWS = ['small' => 1000];

    private const PARENT_ROWS = 10;

    private const CHUNK = 200;

    private string $connection = '';

    /** @var array<string, string> table => why it has no rows */
    private array $skipped = [];

    /** @var array<string, list<array<string, mixed>>> "table|col,col" => rows of the referenced columns */
    private array $pools = [];

    /** @var array<string, true> parents being filled right now: a cycle stops here */
    private array $filling = [];

    /** @var array<string, array{columns: list<array<string, mixed>>, foreignKeys: list<array<string, mixed>>}> */
    private array $meta = [];

    /**
     * @param  array<string, array<string, string>>  $tables  the module's own tables => their model's casts
     * @param  array<int, mixed>  $edges  the relation graph (next slice); unused here
     * @return array<string, int> table => rows written
     */
    public function fill(string $connection, array $tables, string $volume, array $edges = []): array
    {
        $rows = self::ROWS[$volume] ?? throw new InvalidArgumentException("unknown volume {$volume}: only small is measured in this version.");
        $this->connection = $connection;
        $this->skipped = $this->pools = $this->filling = $this->meta = [];
        $out = [];
        foreach ($this->order(array_keys($tables)) as $table) {
            $out[$table] = $this->insert($table, $rows, $tables[$table]);
        }

        return $out;
    }

    /** @return array<string, string> table => why it could not be filled */
    public function skipped(): array
    {
        return $this->skipped;
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

        return $this->rowOf($table, $i, $casts);
    }

    /** Parents before children, among the module's tables; a cycle keeps the given order. @param list<string> $tables */
    private function order(array $tables): array
    {
        $deps = [];
        foreach ($tables as $t) {
            $deps[$t] = array_values(array_intersect(array_column($this->meta($t)['foreignKeys'], 'foreign_table'), $tables));
        }
        $out = [];
        $visit = function (string $t, array $path) use (&$visit, &$out, $deps): void {
            if (in_array($t, $out, true) || in_array($t, $path, true)) {
                return;
            }
            foreach ($deps[$t] as $parent) {
                $visit($parent, [...$path, $t]);
            }
            $out[] = $t;
        };
        foreach ($tables as $t) {
            $visit($t, []);
        }

        return $out;
    }

    /** @param  array<string, string>  $casts */
    private function insert(string $table, int $count, array $casts): int
    {
        $written = 0;
        $error = null;
        foreach (array_chunk(range(1, $count), self::CHUNK) as $chunk) {
            $rows = [];
            foreach ($chunk as $i) {
                $rows[$i] = $this->rowOf($table, $i, $casts);
            }
            try {
                $this->db()->table($table)->insert(array_values($rows));
                $written += count($rows);

                continue;
            } catch (Throwable) {
                // One row breaks the chunk: write them one by one, each with a second set of values.
            }
            foreach ($rows as $i => $row) {
                foreach ([$row, null] as $attempt) {
                    try {
                        $this->db()->table($table)->insert($attempt ?? $this->rowOf($table, $i, $casts));
                        $written++;

                        break;
                    } catch (Throwable $e) {
                        $error = $e;
                    }
                }
            }
        }
        if ($written === 0 && $error !== null) {
            $this->skipped[$table] = self::reason($error);
        }

        return $written;
    }

    /** @param  array<string, string>  $casts @return array<string, mixed> */
    private function rowOf(string $table, int $i, array $casts): array
    {
        $row = [];
        $references = [];
        foreach ($this->meta($table)['foreignKeys'] as $fk) {
            array_push($references, ...$fk['columns']);
            $parent = $this->parentRow($table, $fk['foreign_table'], $fk['foreign_columns']);
            foreach ($parent === null ? [] : $fk['columns'] as $k => $column) {
                $row[$column] = $parent[$fk['foreign_columns'][$k]];
            }
        }
        foreach ($this->meta($table)['columns'] as $c) {
            $timestamp = in_array($c['name'], ['created_at', 'updated_at'], true);
            // A reference with no parent row stays empty: a made-up id would only fail as a foreign key violation.
            if (array_key_exists($c['name'], $row) || in_array($c['name'], $references, true)
                || $c['auto_increment'] || ($c['generation'] ?? null) !== null
                || (! $timestamp && ($c['nullable'] || $c['default'] !== null))) {
                continue;
            }
            $value = $this->value($c, $i, $casts[$c['name']] ?? null);
            if ($value !== null) {
                $row[$c['name']] = $value;
            }
        }

        return $row;
    }

    /** A random existing row of `$parent` (filled with a few rows first if empty). Null for a self reference or a cycle. */
    private function parentRow(string $table, string $parent, array $columns): ?array
    {
        if ($parent === $table) {
            return null;
        }
        $key = $parent.'|'.implode(',', $columns);
        if (! isset($this->pools[$key])) {
            if (! $this->db()->table($parent)->exists()) {
                if (isset($this->filling[$parent])) {
                    return null;
                }
                $this->filling[$parent] = true;
                $this->insert($parent, self::PARENT_ROWS, []);
                unset($this->filling[$parent]);
            }
            $this->pools[$key] = $this->db()->table($parent)->limit(1000)->get($columns)->map(static fn ($r): array => (array) $r)->all();
        }

        return $this->pools[$key] === [] ? null : $this->pools[$key][array_rand($this->pools[$key])];
    }

    /** @param  array<string, mixed>  $c  a column as `Schema::getColumns()` reports it */
    private function value(array $c, int $i, ?string $cast): mixed
    {
        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return $cast::cases()[0]->value ?? null;
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
            in_array($type, ['numeric', 'decimal', 'float4', 'float8', 'float', 'double', 'real'], true) => $i % 100,
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

    /** Unique per row (unique columns hold), cut to the column's length. */
    private function text(string $column, int $i, ?int $length): string
    {
        $value = "{$column}-{$i}-".bin2hex(random_bytes(3));

        return $length === null || strlen($value) <= $length ? $value : substr($i.bin2hex(random_bytes(16)), 0, $length);
    }

    /** The first line of the database's refusal, without the connection details Laravel appends. */
    private static function reason(Throwable $e): string
    {
        $line = strtok($e->getMessage(), "\n") ?: $e::class;

        return Str::limit(trim(explode(' (Connection:', $line)[0]), 200);
    }

    /** @return array{columns: list<array<string, mixed>>, foreignKeys: list<array<string, mixed>>} */
    private function meta(string $table): array
    {
        $schema = Schema::connection($this->connection);

        return $this->meta[$table] ??= ['columns' => $schema->getColumns($table), 'foreignKeys' => $schema->getForeignKeys($table)];
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }
}
