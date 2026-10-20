<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The queries a callback runs on a scratch database (`*_mk_studio_perf`), by database name rather than connection
 * name: while a run is on, the app's own connection name points at the scratch too, and both count.
 *
 * Each query is kept twice: `sql`, its normalized shape (what is compared, counted and shown), and `raw` plus
 * `bindings`, what really ran (what a plan reader re-runs: the shape folds `in (?, ?, ?)` and no longer matches
 * its bindings).
 */
final class QueryRecorder
{
    private bool $listening = false;

    private bool $on = false;

    /** @var list<array{sql: string, raw: string, bindings: array, ms: float}> */
    private array $log = [];

    /** @return list<array{sql: string, raw: string, bindings: array, ms: float}> the queries `$run` ran, in order */
    public function record(callable $run): array
    {
        if (! $this->listening) {
            $this->listening = true;
            DB::listen(function (QueryExecuted $q): void {
                if ($this->on && preg_match(ScratchDatabase::NAME, (string) $q->connection->getDatabaseName())) {
                    $this->log[] = ['sql' => self::normalize($q->sql), 'raw' => $q->sql, 'bindings' => $q->bindings, 'ms' => (float) $q->time];
                }
            });
        }
        $this->log = [];
        $this->on = true;
        try {
            $run();
        } finally {
            $this->on = false;
        }

        return $this->log;
    }

    /** One shape per query: whitespace collapsed and `in (?, ?, ?)` as `in (?)` (bindings are already `?`). */
    public static function normalize(string $sql): string
    {
        return (string) preg_replace(['/\s+/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/'], [' ', '(?)'], trim($sql));
    }

    /** Queries of one request that repeat a shape already run in it: 0 when every shape ran once. */
    public static function duplicates(array $log): int
    {
        return count($log) - count(array_unique(array_column($log, 'sql')));
    }

    /**
     * A read: it starts with SELECT or WITH and modifies nothing anywhere in it, a data-modifying CTE included
     * (`with x as (…) update …`, which PostgreSQL runs). A `for update` / `for share` lock is still a read. Literals and
     * quoted names are left out of the check.
     */
    public static function isSelect(string $sql): bool
    {
        if (preg_match('/^\s*\(?\s*(select|with)\b/i', $sql) !== 1) {
            return false;
        }
        $bare = (string) preg_replace(
            ["/'(?:[^']|'')*'/", '/"(?:[^"]|"")*"/', '/`[^`]*`/', '/\bfor\s+(?:no\s+key\s+)?(?:update|share)\b/i'],
            ' ',
            $sql,
        );

        return preg_match('/\b(insert|update|delete|merge)\b/i', $bare) !== 1;
    }

    /**
     * A query of the engine itself, not of the app's data: its catalog (`information_schema`, `performance_schema`,
     * `mysql.`, `SHOW …`, PostgreSQL's `pg_catalog` and `pg_*` tables: Laravel's `Schema::getColumns()` reads
     * `pg_attribute`) or a lock function (`pg_advisory_*`, `pg_try_advisory_*`, MariaDB's `GET_LOCK` and friends).
     */
    public static function isEngineQuery(string $sql): bool
    {
        return preg_match(
            '/^\s*show\b|\b(?:information_schema|performance_schema|pg_catalog|mysql)\s*\.|\b(?:from|join)\s+"?pg_\w+'
            .'|\bpg_(?:try_)?advisory_\w*\s*\(|\b(?:get_lock|release_lock|release_all_locks|is_free_lock|is_used_lock)\s*\(/i',
            $sql,
        ) === 1;
    }

    /** `$sql` without its trailing row lock (`for update`, `for share`, … `of t`, `nowait`, `skip locked`; MariaDB `lock in share mode`). */
    public static function withoutLock(string $sql): string
    {
        return (string) preg_replace(
            '/\s+(?:for\s+(?:no\s+key\s+update|update|key\s+share|share)(?:\s+of\s+[^\s,]+(?:\s*,\s*[^\s,]+)*)?(?:\s+(?:nowait|skip\s+locked))?|lock\s+in\s+share\s+mode)\s*$/i',
            '',
            $sql,
        );
    }

    /**
     * Bindings as the connection receives them, minus what it would change: a date becomes its string, and a bool stays
     * a bool (`prepareBindings()` makes it 0/1, and PostgreSQL has no `boolean = integer`). For the capture's JSON and
     * for the bindings a plan reader writes into the SQL (`escape()` writes a bool as each engine reads one).
     */
    public static function bindable(Connection $db, array $bindings): array
    {
        $format = $db->getQueryGrammar()->getDateFormat();

        return array_map(static fn (mixed $v): mixed => $v instanceof DateTimeInterface ? $v->format($format) : $v, $bindings);
    }
}
