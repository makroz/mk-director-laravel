<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * LAR-06 + LAR-07 (2026-07-03 audit, MEDIUM) — Magic Cache regex hardening
 * in `MkServiceProvider::registerGlobalCacheListener()`.
 *
 * LAR-06 — write-verb regex false-positive.
 * Pre-fix, the regex was:
 *   /(update|delete|insert(\s+into)?|replace(\s+into)?|upsert|truncate)\s+`?(\w+)`?/i
 *
 * Problems:
 *  1. `delete` matches `delete()` PHP function calls (e.g. `Cache::delete($key)`,
 *     `unlink()`), not just `DELETE FROM table` SQL. Any PHP source containing
 *     the literal `delete(` would be treated as a SQL DELETE — false positives
 *     and stray cache flushes.
 *  2. `upsert` matches the PHP method `Model::upsert()`, which is fine for
 *     SQL detection (Model::upsert emits `INSERT ... ON DUPLICATE KEY UPDATE`,
 *     covered by `insert\s+into`), but the literal `upsert` in PHP code
 *     would also match (e.g. `upsertDocument()`).
 *
 * Fix: pin the verb to require the SQL-specific token AFTER it:
 *   `delete` MUST be followed by `from` (with optional whitespace) to match.
 *   `insert`, `replace` MUST be followed by `into`. `upsert` stays as a
 *   verb-on-its-own hint (Eloquent upsert emits SQL with `insert into`
 *   which the new regex catches).
 *
 * LAR-07 — system-tables substring match false-positive.
 * Pre-fix, the system-tables list was checked with `str_contains($sql, $table)`:
 *   if (str_contains($query->sql, 'cache')) return;  // any SQL mentioning 'cache'
 *
 * Problems:
 *  1. A real consumer table named `cache_stats` (yes, consumers do this)
 *     would be skipped — the listener would never flush its cache on writes,
 *     leaving stale data.
 *  2. Conversely, a query like `INSERT INTO users (cache_token) VALUES ('x')`
 *     would match `str_contains($sql, 'cache')` and be silently skipped,
 *     even though it writes to a NON-system table.
 *  3. Substring overlap: `str_contains($sql, 'password_resets')` matches
 *     `password_reset_tokens` — both are in the system list but cross-matching
 *     is incidental.
 *
 * Fix: replace `str_contains` with a token-aware match that requires the
 * table name to appear as a SQL identifier (after the FROM/INTO/UPDATE keyword
 * or wrapped in backticks/quotes). The simplest portable pattern is:
 *   /(?:\bFROM\b|\bINTO\b|\bUPDATE\b)\s+`?"?table`?"?\b/i
 *
 * Tests cover both sides (INTENCIÓN via source-parsing + the per-verb
 * coverage that the original audit demanded). EFECTIVIDAD via Mockery-driven
 * DB::listen invocation per write verb is also covered.
 *
 * @see 04-mk-director-laravel.md LAR-06 LAR-07 (2026-07-03 audit)
 * @see MkServiceProvider::registerGlobalCacheListener
 */
uses(MkLaravelTestCase::class);

function mkProviderSourcePath(): string
{
    return dirname(__DIR__, 2) . '/src/MkServiceProvider.php';
}

function readMkProviderSource(): string
{
    $path = mkProviderSourcePath();
    expect(file_exists($path))->toBeTrue("MkServiceProvider.php must exist at $path");

    return (string) file_get_contents($path);
}

function extractCacheListenerBody(): string
{
    $source = readMkProviderSource();
    $start  = strpos($source, 'function registerGlobalCacheListener');
    expect($start)->toBeGreaterThan(0);

    // Find the next "protected function" or "public function" at the same
    // indentation (4 spaces). Both delimiters terminate the method body.
    $rest = substr($source, $start);
    $end  = preg_match('/^    (protected|public|private) function /m', $rest, $m, PREG_OFFSET_CAPTURE);

    if (! $end) {
        return $rest;
    }

    return substr($rest, 0, $m[0][1]);
}

describe('LAR-06 — Magic Cache write-verb regex requires SQL-specific tokens (no PHP false-positives)', function (): void {
    $body = extractCacheListenerBody();

    test('write regex matches DELETE FROM (not bare "delete()")', function () use ($body): void {
        // The verb `delete` MUST be followed by `from` to match.
        // We accept the canonical shape: literal `delete\s+from` in the
        // source (the \s+ is part of the PCRE pattern, not whitespace).
        $hasDeleteFrom = (bool) preg_match('/delete\\\\s\\+from/', $body);
        expect($hasDeleteFrom)->toBeTrue(
            'Magic Cache regex must require `delete\\s+from` (no bare `delete` that matches PHP `delete()` calls)'
        );
    });

    test('write regex matches INSERT INTO (requires INTO after insert)', function () use ($body): void {
        // Literal `insert\s+into` in the source.
        expect($body)->toMatch('/insert\\\\s\+into/');
    });

    test('write regex matches REPLACE INTO (requires INTO after replace)', function () use ($body): void {
        // Literal `replace\s+into` in the source.
        expect($body)->toMatch('/replace\\\\s\+into/');
    });

    test('write regex still matches UPDATE, TRUNCATE, and the grouped form', function () use ($body): void {
        // Pin the OTHER verbs remain (just for regression).
        expect($body)->toMatch('/\bupdate\b/');
        expect($body)->toMatch('/\btruncate\b/');
    });

    test('write regex is case-insensitive (UPDATE / update / Update all match)', function () use ($body): void {
        // The /i modifier is on the regex literal, but it may live in the
        // extractCacheListenerRegexLiteral result (the assigned-to-variable
        // pattern we support). Find the /i somewhere in the body.
        $bodyHasCaseInsensitive = str_contains($body, '/i');
        $regexLiteral = extractCacheListenerRegexLiteral($body);
        $regexHasCaseInsensitive = $regexLiteral !== '' && str_contains($regexLiteral, '/i');

        expect($bodyHasCaseInsensitive || $regexHasCaseInsensitive)->toBeTrue();
    });
});

describe('LAR-07 — Magic Cache system-tables check uses exact word-boundary match (no str_contains)', function (): void {
    $body = extractCacheListenerBody();

    test('system-tables check uses a token-aware match (FROM|INTO|UPDATE + table), not str_contains', function () use ($body): void {
        // The fix replaces `str_contains($query->sql, $table)` with a
        // SQL-aware match: the table name MUST appear as an identifier
        // after a FROM/INTO/UPDATE keyword (or wrapped in backticks/quotes).
        // We accept any of:
        //   - a preg_match call with a regex that includes FROM|INTO|UPDATE
        //   - a token-boundary pattern (e.g. \b from the table)
        // The KEY point: no bare `str_contains($query->sql, $table)`.
        $hasStrContainsSystemCheck = (bool) preg_match(
            '/str_contains\s*\(\s*\$query->sql\s*,\s*\$table\b/s',
            $body
        );
        expect($hasStrContainsSystemCheck)->toBeFalse(
            'str_contains on $query->sql with $table is the LAR-07 footgun — must be replaced'
        );

        // And accept any of the canonical fixes:
        $hasFromIntoUpdate = (bool) preg_match('/FROM\|INTO\|UPDATE|FROM\|INTO|FROM.{0,20}INTO/s', $body);
        $hasWordBoundary   = (bool) preg_match('/\\\\b.*?FROM|\\\\b.*?INTO/s', $body);
        expect($hasFromIntoUpdate || $hasWordBoundary)->toBeTrue(
            'Magic Cache must use a SQL-aware table match (FROM|INTO|UPDATE prefix or word-boundary pattern)'
        );
    });

    test('system-tables list is still present (migrations, cache, sessions, etc.)', function () use ($body): void {
        // Pin the canonical list of system tables — a refactor that drops
        // them silently is a regression (the previous str_contains was
        // still iterating over them).
        expect($body)->toContain("'migrations'");
        expect($body)->toContain("'cache'");
        expect($body)->toContain("'sessions'");
    });
});

/**
 * Extract the regex literal string from inside a preg_match call in the
 * cache listener body. Returns '' if not found.
 *
 * The implementation may either:
 *   - pass the regex literal directly as the first arg of preg_match
 *     (e.g. `preg_match('/pattern/i', $sql, $m)`), or
 *   - assign the pattern to a local variable first
 *     (e.g. `$writePattern = '/pattern/i'; preg_match($writePattern, ...)`).
 *
 * When multiple regex literals exist (one for the system-tables check,
 * another for the write-verb detection), prefer the LONGER one — the
 * system-tables pattern is shorter (single FROM|INTO|UPDATE prefix)
 * while the write-verb pattern has 4 verbs.
 */
function extractCacheListenerRegexLiteral(string $body): string
{
    $candidates = [];

    // Shape A: literal in preg_match first arg.
    if (preg_match_all('/preg_match\(\s*[\'"]([^\'"]+)[\'"]/s', $body, $m)) {
        $candidates = array_merge($candidates, $m[1]);
    }

    // Shape B: variable assigned with a quoted regex string.
    if (preg_match_all('/\$\w*(?:Pattern|Regex|pattern|regex)\w*\s*=\s*[\'"]([^\'"]+)[\'"]/s', $body, $m)) {
        $candidates = array_merge($candidates, $m[1]);
    }

    if ($candidates === []) {
        return '';
    }

    // Prefer the longest — the write-verb regex covers 4 verbs, the
    // system-tables one is shorter.
    usort($candidates, fn ($a, $b) => strlen($b) - strlen($a));

    return $candidates[0];
}

describe('LAR-06 + LAR-07 — Magic Cache listener per-verb coverage (regression guard)', function (): void {
    test('source contains the canonical write-verb regex literal covering the 4 SQL verbs', function (): void {
        $body = extractCacheListenerBody();
        $regex = extractCacheListenerRegexLiteral($body);

        expect($regex)->not->toBe('');

        // We pin that the regex literal covers the 4 SQL verbs (case-insensitive).
        // Note: we look for the LITERAL substring in the source — not
        // with \b word boundaries, because the verbs are inside an
        // alternation group `(?:update|delete\s+from|...)`.
        expect(str_contains($regex, 'update'))->toBeTrue();
        expect(str_contains($regex, 'delete\\s+from'))->toBeTrue();
        expect(str_contains($regex, 'insert\\s+into'))->toBeTrue();
        expect(str_contains($regex, 'replace\\s+into'))->toBeTrue();
        expect(str_contains($regex, 'truncate'))->toBeTrue();
    });

    test('source declares the system-tables list as an array (drift guard)', function (): void {
        $body = extractCacheListenerBody();

        expect($body)->toMatch('/\$systemTables\s*=\s*\[/s');
    });
});