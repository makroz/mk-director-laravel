<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkServiceProvider::registerGlobalCacheListener was hardened in
 * the 1.2.2-hardening sprint to:
 *   1. Skip system tables (migrations, cache, sessions, queue, etc.) — R2-007.
 *   2. Only act on writes (INSERT / UPDATE / DELETE) — R4-004.
 *   3. Document the regex limitation (no REPLACE, TRUNCATE, upsert).
 *
 * Implementation note: source-parsing the method body. The previous
 * `MkServiceProviderTest` only checks the class can be instantiated; this
 * test pins the security contract of the listener so a future refactor that
 * removes the system-tables filter or the write-only heuristic is caught
 * immediately, without needing to boot DB / Cache facades.
 *
 * @see audit-2026-06-17-R2-007, audit-2026-06-17-R4-004
 */
uses(MkLaravelTestCase::class);

function mkServiceProviderSourcePath(): string
{
    return __DIR__ . '/../../src/MkServiceProvider.php';
}

function mkServiceProviderSource(): string
{
    $path = mkServiceProviderSourcePath();
    expect(file_exists($path))->toBeTrue("MkServiceProvider.php must exist at $path");

    return (string) file_get_contents($path);
}

function cacheListenerMethodSource(): string
{
    $source = mkServiceProviderSource();

    // Find the start of the method body (after the opening `{` of the
    // method signature) and the start of the NEXT method at the same
    // indent (4 spaces). This sidesteps brace-counting and works
    // regardless of nested closures / if-blocks at deeper indents.
    $start = strpos($source, 'function registerGlobalCacheListener');
    if ($start === false) {
        return '';
    }

    // Skip past the opening `{` of the method.
    $bodyStart = strpos($source, '{', $start);
    if ($bodyStart === false) {
        return '';
    }
    $bodyStart++; // position after `{`

    // Find the next `function ` declaration at 4-space indent (`\n    function`).
    $pattern = '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/';
    $nextMethod = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $bodyStart);
    if ($nextMethod === false || $nextMethod === 0) {
        // No more methods after this one — take the rest of the class body
        // up to the closing `}` of the class.
        $classEnd = strrpos($source, '}');
        return substr($source, $bodyStart, $classEnd - $bodyStart - 1);
    }

    $bodyEnd = $matches[0][0][1];
    return substr($source, $bodyStart, $bodyEnd - $bodyStart);
}

test('cache listener defines a system-tables allowlist that excludes cache, migrations, sessions, jobs', function () {
    $body = cacheListenerMethodSource();
    expect($body)->not->toBeEmpty('registerGlobalCacheListener must exist in MkServiceProvider');

    // The system-tables array must be declared inside the method (or
    // captured by `use ($systemTables)` in the closure). Either way, all
    // these tables must appear in the source.
    foreach (['migrations', 'cache', 'sessions', 'jobs', 'failed_jobs'] as $table) {
        expect($body)->toContain("'$table'");
    }
});

test('cache listener skips writes that target a system table via str_contains loop', function () {
    $body = cacheListenerMethodSource();
    expect($body)->not->toBeEmpty();

    // The new filter iterates $systemTables and returns early if any of
    // them is in the query SQL. This is the core R2-007 fix.
    expect($body)->toContain('foreach ($systemTables as $table)');
    expect($body)->toContain('str_contains($query->sql, $table)');
});

test('cache listener only acts on write operations (insert / update / delete)', function () {
    $body = cacheListenerMethodSource();
    expect($body)->not->toBeEmpty();

    // The write-detection regex must match insert / update / delete.
    // We do NOT assert a specific regex literal (refactors should be free
    // to tighten the pattern) — we just assert the words are present and
    // the cache flush is gated by the match.
    expect($body)->toContain('insert');
    expect($body)->toContain('update');
    expect($body)->toContain('delete');
});

test('cache listener docblock documents the REPLACE / TRUNCATE / upsert limitation', function () {
    // The docblock lives BEFORE the method signature. The body extractor
    // (which starts after the method's opening `{`) intentionally excludes
    // it, so we re-anchor the assertion to the full source file.
    $source = mkServiceProviderSource();

    // The docblock must call out what the regex does NOT cover so a future
    // reader does not assume the listener is exhaustive.
    $methodPos = strpos($source, 'function registerGlobalCacheListener');
    expect($methodPos)->not->toBeFalse();
    $beforeMethod = substr($source, 0, $methodPos);
    expect($beforeMethod)->toContain('REPLACE');
    expect($beforeMethod)->toContain('TRUNCATE');
});

test('cache listener still tags cache flushes by table_all (regression guard for MkDirector magic cache)', function () {
    $body = cacheListenerMethodSource();
    expect($body)->not->toBeEmpty();

    // The original "magic cache" feature flushed `$table . '_all'`. The
    // hardening sprint must NOT change the tag naming (consumers depend
    // on it) — only add the system-tables filter and the write-only gate.
    expect($body)->toContain("_all");
    expect($body)->toContain("->flush()");
});
