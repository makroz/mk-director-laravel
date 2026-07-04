<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (rc13): `MkServiceProvider::registerGlobalCacheListener()`
 * regex was previously `(update|delete|insert\s+into)` — missing
 * `REPLACE`, `TRUNCATE`, and `upsert()`. The auto-cache invalidation
 * listener would NOT fire for these mutations, leaving stale cache
 * after a `TRUNCATE TABLE` or `Eloquent::upsert()`.
 *
 * R-PKG-024 broadened the regex to
 * `(update|delete|insert(\s+into)?|replace(\s+into)?|upsert|truncate)`.
 *
 * LAR-06 (2026-07-03 audit): the optional `(\s+into)?` was tightened to
 * mandatory `\s+into` (same for `replace\s+into`), and `delete` was
 * tightened to require `\s+from` — the bare-verb alternatives were the
 * source of false positives (PHP `delete()`, `insert()`, `replace()`
 * function calls triggered the listener). Eloquent's `upsert()` emits
 * `INSERT ... ON DUPLICATE KEY UPDATE`, covered by the
 * `insert\s+into` branch.
 *
 * The pattern still requires `\s+` after the verb (so `updateHook` is
 * not matched), and the table name is still required after the verb
 * (so `update` alone doesn't match).
 */
uses(MkLaravelTestCase::class);

function serviceProviderPath(): string
{
    return __DIR__ . '/../../../src/MkServiceProvider.php';
}

function serviceProviderSource(): string
{
    $path = serviceProviderPath();
    expect(file_exists($path))->toBeTrue("MkServiceProvider.php must exist at $path");

    return (string) file_get_contents($path);
}

test('registerGlobalCacheListener regex covers the 5 SQL verbs (LAR-06 hardening)', function () {
    $source = serviceProviderSource();

    // The new verb set must include all five: update, delete from,
    // insert into, replace into, truncate. The pattern uses alternation
    // with REQUIRED SQL-specific tokens after each verb (delete\s+from,
    // insert\s+into, replace\s+into) so bare PHP function calls like
    // `delete()`, `insert()`, `replace()` no longer trigger the listener.
    expect($source)->toContain('update|delete\\s+from');
    expect($source)->toContain('insert\\s+into');
    expect($source)->toContain('replace\\s+into');
    expect($source)->toContain('truncate');
});

test('registerGlobalCacheListener still requires whitespace after verb (regression guard)', function () {
    $source = serviceProviderSource();

    // The pattern must require whitespace after the verb so that
    // identifiers like `updateHook` or `deletedAt` are NOT matched.
    // We verify by checking the literal source contains the `\s+`
    // token after the verb group.
    expect($source)->toContain('truncate)\\s+');
});

test('registerGlobalCacheListener still extracts table name from match group (regression guard)', function () {
    $source = serviceProviderSource();

    // Pineando contra formatting whitespace-fragile. La regla pint
    // `concat_space` cambió `'a'.'b'` → `'a'.'b'` (sin espacio alrededor
    // del `.`), así que el toContain original
    // ("CacheManager::flush([\$table . '_all'])") se rompe post-format.
    // Usamos regex tolerante (puede o no haber whitespace alrededor del
    // `.` de concatenación) — la invariante es que el flush pasa `$table._all`.
    expect($source)->toMatch('/CacheManager::flush\(\[\s*\$table\s*\.\s*\'_all\'\s*\]\)/');
});
