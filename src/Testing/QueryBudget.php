<?php

declare(strict_types=1);

namespace Mk\Director\Testing;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mk\Director\Perf\QueryRecorder;
use PHPUnit\Framework\Assert;

/**
 * The slope test of a screen, for Pest or PHPUnit in any app: the screen must run the SAME number of queries with 1 row
 * and with many. A query per row (N+1) is exactly what breaks that, and a relation added on purpose is not.
 *
 * Why not a fixed ceiling ("at most 25 queries"): with the few rows a test seeds, an N+1 fits under any number (3 rows
 * of an N+1 are ~9 extra queries, under 25: the test stays green with the bug in). And a ceiling ages: each legitimate
 * relation raises it a little, until nobody tells "one more relation" from "the N+1 came back". Keep a generous
 * `$ceiling` only for the regression that raises every size evenly.
 *
 *     QueryBudget::assertFlat(
 *         screen: fn () => $this->getJson('/api/posts')->assertOk()->json('data'),
 *         seedRows: fn (int $n) => Post::factory()->count($n)->create(),
 *         rows: [8, 20],
 *     );
 *
 * Counts the default connection's reads (its query log; the log is restored as it was): a write, like a token's
 * `last_used_at`, can land on one size and not the next.
 */
final class QueryBudget
{
    /**
     * Seeds 1 row, runs `$screen` once to warm up (the first call fills session, permission and config caches that
     * later calls do not repeat: without it 7 against 10 queries came out with no N+1 at all), counts its queries, then
     * seeds up to each size of `$rows` and counts again. Fails when a size runs more queries than 1 row plus `$growth`,
     * or more than `$ceiling`.
     *
     * When `$screen` returns an int or a countable (an array of rows, a collection), that is the rows it listed: the
     * check also fails when the largest size listed no more rows than 1 did. A fixture that never reaches the screen
     * (rows of another user, a status the screen filters out) costs the same at every size and would pass forever.
     *
     * @param  callable(): mixed  $screen  requests the screen once
     * @param  callable(int): mixed  $seedRows  adds that many rows the screen lists
     * @param  int|list<int>  $rows  the sizes compared with 1, each above 1 (e.g. 8, or [8, 20])
     * @param  int|null  $ceiling  the most queries any size may run
     * @param  int  $growth  the extra queries a larger size may run, declared (e.g. a second polymorphic type among the rows)
     * @return array<int, int> queries per size, 1 first
     */
    public static function assertFlat(
        callable $screen,
        callable $seedRows,
        int|array $rows = 8,
        ?int $ceiling = null,
        int $growth = 0,
    ): array {
        $sizes = is_int($rows) ? [$rows] : array_values($rows);
        sort($sizes);
        if ($sizes === [] || $sizes[0] <= 1) {
            throw new InvalidArgumentException('QueryBudget: every size in $rows must be above 1');
        }

        $seedRows(1);
        $screen();
        $counts = [];
        $shapes = [];
        $listed = [];
        $seeded = 1;
        foreach ([1, ...$sizes] as $size) {
            if ($size > $seeded) {
                $seedRows($size - $seeded);
                $seeded = $size;
            }
            [$counts[$size], $shapes[$size], $listed[$size]] = self::measure($screen);
        }

        $last = end($sizes);
        if ($listed[1] !== null && $listed[$last] !== null && $listed[$last] <= $listed[1]) {
            Assert::fail(sprintf(
                'QueryBudget: the screen listed %d row(s) with 1 row seeded and %d with %d: the fixture does not reach the screen, so the counts compare empty lists.',
                $listed[1], $listed[$last], $last,
            ));
        }

        foreach ($sizes as $size) {
            Assert::assertLessThanOrEqual($counts[1] + $growth, $counts[$size], self::grew($counts, $shapes, $size, $growth));
        }
        if ($ceiling !== null) {
            Assert::assertLessThanOrEqual($ceiling, max($counts), sprintf(
                'QueryBudget: %s, over the ceiling of %d.', self::line($counts), $ceiling,
            ));
        }

        return $counts;
    }

    /** @return array{0: int, 1: array<string, int>, 2: ?int} queries, queries per shape, and the rows listed (when the screen says) */
    private static function measure(callable $screen): array
    {
        $connection = DB::connection();
        $was = $connection->logging();
        $from = count($connection->getQueryLog());
        $connection->enableQueryLog();
        try {
            $result = $screen();
        } finally {
            $log = array_slice($connection->getQueryLog(), $from);
            if (! $was) {
                $connection->disableQueryLog();
            }
        }

        // Reads only: a token's `last_used_at` is written whenever the clock ticked to a new second since the last
        // request, so a write lands on one size and not the next.
        $log = array_values(array_filter($log, static fn (array $q): bool => QueryRecorder::isSelect($q['query'])));

        $listed = match (true) {
            is_int($result) => $result,
            is_countable($result) => count($result),
            default => null,
        };

        return [count($log), array_count_values(array_map(fn (array $q) => QueryRecorder::normalize($q['query']), $log)), $listed];
    }

    /** The failure: every count, and the shapes that ran more times at `$size` than at 1 row (the per-row ones). */
    private static function grew(array $counts, array $shapes, int $size, int $growth): string
    {
        $more = [];
        foreach ($shapes[$size] as $sql => $times) {
            $before = $shapes[1][$sql] ?? 0;
            if ($times > $before) {
                $more[] = sprintf('  %d → %d× %s', $before, $times, $sql);
            }
        }

        return sprintf(
            "QueryBudget: the query count grows with the rows: %s (allowed growth %d). A query per row (N+1). The shapes that grew:\n%s",
            self::line($counts), $growth, implode("\n", $more),
        );
    }

    private static function line(array $counts): string
    {
        return implode(', ', array_map(fn (int $size, int $n) => "{$size} row(s) → {$n} queries", array_keys($counts), $counts));
    }
}
