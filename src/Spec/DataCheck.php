<?php

declare(strict_types=1);

namespace Mk\Director\Spec;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What MK Studio's «Revisar contra la app» asks of the app's DATA (`mk-studio:data-check`): how many values a dropped
 * column erases, how many rows use an enum case the spec removes, whether a relation's rows point at rows that exist,
 * and which migrations the database ran. Read-only: only `SELECT`s through the query builder.
 *
 * Every table and column of the request is checked against the schema's own listing before it reaches a query: a name
 * the schema does not have answers `error` and never becomes SQL. Each query runs under the driver's statement
 * timeout (MariaDB `max_statement_time`, MySQL `max_execution_time`, PostgreSQL `statement_timeout`; sqlite has none)
 * and an item that hits it answers `timedOut: true` instead of a number.
 */
final class DataCheck
{
    /** At most this many orphan values per relation: enough to look them up, not the table. */
    private const SAMPLE = 20;

    /** @var list<string>|null the schema's tables, read once */
    private ?array $tables = null;

    /** @var array<string, list<string>> columns of the tables asked about, read once each */
    private array $columns = [];

    public function __construct(private readonly int $timeoutMs = 5000) {}

    /**
     * @param  array{relations?: list<array{table: string, column: string, target: string, targetKey: string}>, columns?: list<array{table: string, column: string}>, values?: list<array{table: string, column: string, value: string|int}>, migrations?: bool}  $request
     * @return array{relations: list<array>, columns: list<array>, values: list<array>, migrations?: list<string>}
     */
    public function run(array $request): array
    {
        $restore = $this->setTimeout();
        try {
            $out = [
                'relations' => array_map(fn (array $r): array => $this->relation(...$this->names($r, ['table', 'column', 'target', 'targetKey'])), $request['relations'] ?? []),
                'columns' => array_map(fn (array $c): array => $this->column(...$this->names($c, ['table', 'column'])), $request['columns'] ?? []),
                'values' => array_map(fn (array $v): array => $this->value(...[...$this->names($v, ['table', 'column']), 'value' => $v['value'] ?? null]), $request['values'] ?? []),
            ];
            if (! empty($request['migrations'])) {
                $out['migrations'] = $this->migrations();
            }

            return $out;
        } finally {
            $restore();
        }
    }

    /** `t.c → target.k`: rows, nulls, rows whose value is not a `k` of `target`, and up to {@see SAMPLE} of those values. */
    private function relation(string $table, string $column, string $target, string $targetKey): array
    {
        $key = compact('table', 'column', 'target', 'targetKey');
        $targetExists = $this->has($target, $targetKey);
        $types = ['columnType' => $this->type($table, $column), 'targetKeyType' => $targetExists ? $this->type($target, $targetKey) : null];
        $base = $key + ['targetExists' => $targetExists] + $types + [
            'sameType' => $types['columnType'] !== null && $types['columnType'] === $types['targetKeyType'],
            'rows' => 0, 'nulls' => 0, 'orphans' => 0, 'sample' => [],
        ];
        if (($error = $this->unknown($table, $column)) !== null) {
            return $base + ['error' => $error];
        }

        // Keys of different types are not joined: PostgreSQL refuses `uuid = character varying` outright, and where a
        // driver casts, the match would be the cast's. The answer is the two types; the orphans are not counted.
        $join = $targetExists && $base['sameType'];

        return [...$base, ...$this->guarded(function () use ($table, $column, $target, $targetKey, $targetExists, $join): array {
            $counts = [
                'rows' => DB::table($table)->count(),
                'nulls' => DB::table($table)->whereNull($column)->count(),
            ];
            if ($targetExists && ! $join) {
                return $counts;
            }
            // Aliased, so a relation of a table to itself (a parent_id) joins two copies of it.
            $orphans = DB::table("{$table} as s")->whereNotNull("s.{$column}");
            if ($join) {
                $orphans->leftJoin("{$target} as t", "t.{$targetKey}", '=', "s.{$column}")->whereNull("t.{$targetKey}");
            }

            return $counts + [
                'orphans' => (clone $orphans)->count(),
                'sample' => (clone $orphans)->distinct()->orderBy("s.{$column}")->limit(self::SAMPLE)->pluck("s.{$column}")->all(),
            ];
        })];
    }

    /** `t.c`: the values a `dropColumn` would erase. */
    private function column(string $table, string $column): array
    {
        $item = compact('table', 'column') + ['nonNull' => 0];

        return ($error = $this->unknown($table, $column)) !== null
            ? $item + ['error' => $error]
            : [...$item, ...$this->guarded(fn (): array => ['nonNull' => DB::table($table)->whereNotNull($column)->count()])];
    }

    /** `t.c = v`: the rows using an enum case the spec would remove. */
    private function value(string $table, string $column, mixed $value): array
    {
        $item = compact('table', 'column', 'value') + ['rows' => 0];
        $error = is_string($value) || is_int($value) || is_float($value) ? $this->unknown($table, $column) : 'expected a string or number value';

        return $error !== null
            ? $item + ['error' => $error]
            : [...$item, ...$this->guarded(fn (): array => ['rows' => DB::table($table)->where($column, $value)->count()])];
    }

    /** @return list<string> what the migrations table says ran, by name; none without that table */
    private function migrations(): array
    {
        $config = config('database.migrations', 'migrations');
        $table = is_array($config) ? ($config['table'] ?? 'migrations') : (string) $config;

        return $this->has($table, 'migration')
            ? DB::table($table)->orderBy('migration')->pluck('migration')->all()
            : [];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string> the request's names as strings (a missing one is '', which no schema has)
     */
    private function names(array $item, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = is_string($item[$k] ?? null) ? $item[$k] : '';
        }

        return $out;
    }

    private function unknown(string $table, string $column): ?string
    {
        return match (true) {
            ! in_array($table, $this->tables(), true) => "unknown table {$table}",
            ! $this->has($table, $column) => "unknown column {$table}.{$column}",
            default => null,
        };
    }

    private function has(string $table, string $column): bool
    {
        if (! in_array($table, $this->tables(), true)) {
            return false;
        }

        return in_array($column, $this->columns[$table] ??= Schema::getColumnListing($table), true);
    }

    private function type(string $table, string $column): ?string
    {
        if (! $this->has($table, $column)) {
            return null;
        }
        foreach (Schema::getColumns($table) as $c) {
            if ($c['name'] === $column) {
                return strtolower($c['type']);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return $this->tables ??= Schema::getTableListing(Schema::getCurrentSchemaListing(), false);
    }

    /** The query's numbers, `timedOut` when the statement timeout cut it, or the database's `error` for that item alone. */
    private function guarded(callable $query): array
    {
        try {
            return $query();
        } catch (QueryException $e) {
            return $this->isTimeout($e) ? ['timedOut' => true] : ['error' => self::reason($e)];
        }
    }

    /**
     * What a failed query says to the screen: its SQLSTATE and the database's first line. Never Laravel's whole message,
     * which carries the connection (host, port, database) and the SQL.
     */
    public static function reason(QueryException $e): string
    {
        $line = (string) strtok((string) ($e->getPrevious()?->getMessage() ?? ''), "\n");
        $line = (string) preg_replace(['/^SQLSTATE\[[^\]]*\]:?\s*/', '/\s*\(Connection:.*$/s'], '', trim($line));

        return trim('SQLSTATE['.($e->errorInfo[0] ?? $e->getCode()).'] '.mb_substr($line, 0, 160));
    }

    private function isTimeout(QueryException $e): bool
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => $e->getCode() === '57014', // query_canceled
            'mysql', 'mariadb' => in_array($e->errorInfo[1] ?? null, [1969, 3024], true), // MariaDB, MySQL
            default => false,
        };
    }

    /**
     * Sets the session's statement timeout and returns what puts the previous one back. A setting of the session, not
     * a write: nothing of the database changes.
     *
     * @return callable(): void
     */
    private function setTimeout(): callable
    {
        $driver = DB::connection()->getDriverName();
        $version = $driver === 'sqlite' ? '' : (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        [$read, $set] = match (true) {
            $driver === 'pgsql' => ['show statement_timeout', static fn ($v) => DB::statement('set statement_timeout = '.DB::getPdo()->quote((string) $v))],
            // MariaDB counts seconds (a decimal), MySQL milliseconds; Laravel's `mysql` driver may be either.
            $driver === 'mariadb' || ($driver === 'mysql' && str_contains($version, 'MariaDB')) => ['select @@max_statement_time', static fn ($v) => DB::statement('set session max_statement_time = '.(float) $v)],
            $driver === 'mysql' => ['select @@max_execution_time', static fn ($v) => DB::statement('set session max_execution_time = '.(int) $v)],
            default => [null, null],
        };
        if ($read === null) {
            return static function (): void {};
        }
        try {
            $previous = array_values((array) DB::selectOne($read))[0];
            $set(str_contains($read, 'max_statement_time') ? $this->timeoutMs / 1000 : ($driver === 'pgsql' ? "{$this->timeoutMs}ms" : $this->timeoutMs));
        } catch (Throwable) {
            return static function (): void {}; // no timeout rather than no answer
        }

        return static function () use ($set, $previous): void {
            try {
                $set($previous);
            } catch (Throwable) {
                // the connection ends with the command anyway
            }
        };
    }
}
