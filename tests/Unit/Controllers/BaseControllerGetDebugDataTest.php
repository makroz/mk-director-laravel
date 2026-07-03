<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (rc13): `BaseController::getDebugData()` no longer calls
 * `DB::select("EXPLAIN " . $query, $bindings)` — that path was a SQL
 * injection vector when the query contained user-controlled values
 * (an authenticated `super-admin` or `dev` could pass `?debug=true&_debug=1`
 * to reach it, and any field that leaked into the query was vulnerable).
 *
 * The fix:
 *   1. Slow-query candidates are LOGGED via `Log::debug()` for offline
 *      analysis. This is the safe default.
 *   2. The actual `EXPLAIN` is gated behind a new config flag
 *      `mk_director.debug.explain_enabled` (default `false` in rc13).
 *      When the flag is `true`, the SQL is logged as a `warning` so a
 *      developer can run `EXPLAIN` manually in a safe environment. The
 *      query is NOT interpolated into `DB::select`.
 *
 * Implementation note: source-parsing — same pattern as R2-010 hardening
 * and T2/T3/T4 pineos. The method is `protected` and the class is
 * `abstract`, so runtime instantiation needs an anonymous test subclass
 * — overkill for a 5-line conditional change.
 */
uses(MkLaravelTestCase::class);

function getDebugDataMethodSourceForR3(): string
{
    $path = __DIR__ . '/../../../src/Controllers/BaseController.php';
    $source = (string) file_get_contents($path);
    $start = strpos($source, 'function getDebugData(');
    if ($start === false) {
        return '';
    }
    $pattern = '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/';
    $matches = [];
    $next = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $start + 1);
    if ($next === 0) {
        $classEnd = strrpos($source, '}');
        return substr($source, $start, $classEnd - $start - 1);
    }
    $bodyEnd = $matches[0][0][1];
    return substr($source, $start, $bodyEnd - $start);
}

test('getDebugData R3.1 fix: no longer interpolates SQL into DB::select (SQL injection vector closed)', function () {
    $body = getDebugDataMethodSourceForR3();
    expect($body)->not->toBeEmpty();

    // The dangerous pattern `DB::select("EXPLAIN " . $query['query'], $query['bindings'])`
    // must be GONE. Any interpolation of `$query['query']` (or `$query`) into
    // `DB::select` is a regression.
    expect($body)->not->toContain('DB::select("EXPLAIN "');
    expect($body)->not->toMatch('/DB::select\s*\([^)]*\$query\s*\[[\'"]query[\'"]\]\s*\.?\s*[^)]*\)/');
    // Also no `str_starts_with(strtolower($query['query'])` followed by EXPLAIN —
    // the whole EXPLAIN branch is replaced with a log-only path.
    expect($body)->not->toMatch('/EXPLAIN\s+\$query\s*\[[\'"]query[\'"]\]\s*\./');
});

test('getDebugData R3.1 fix: uses Log::debug for slow-query candidates (offline analysis)', function () {
    $body = getDebugDataMethodSourceForR3();
    expect($body)->not->toBeEmpty();

    // The replacement is `Log::debug('[mk-director] slow query candidate', [...])`.
    expect($body)->toContain("Log::debug(");
    expect($body)->toContain('slow query candidate');
});

test('getDebugData R3.1 fix: actual EXPLAIN gated behind mk_director.debug.explain_enabled flag', function () {
    $body = getDebugDataMethodSourceForR3();
    expect($body)->not->toBeEmpty();

    // The new config flag must be checked.
    expect($body)->toContain("config('mk_director.debug.explain_enabled'");
    expect($body)->toContain('explain_enabled');
});

test('getDebugData R3.1 fix: when explain_enabled, query is logged as warning (not DB::select)', function () {
    $body = getDebugDataMethodSourceForR3();
    expect($body)->not->toBeEmpty();

    // When the flag is true, we log a warning (not error, not info) so
    // the developer sees it but no SQL injection risk.
    expect($body)->toContain('Log::warning(');
    expect($body)->toContain('EXPLAIN query (run manually in safe env)');
});

test('getDebugData R2-010 regression guard: role gate still in place (super-admin / dev only)', function () {
    $body = getDebugDataMethodSourceForR3();
    expect($body)->not->toBeEmpty();

    // The role gate is preserved from the R2-010 hardening — this is
    // a defense-in-depth layer on top of the new flag.
    expect($body)->toContain("hasRole('super-admin')");
    expect($body)->toContain("hasRole('dev')");
    expect($body)->toContain("method_exists(\$user, 'hasRole')");
});
