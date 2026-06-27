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
 * The fix: broaden the regex to
 * `(update|delete|insert(\s+into)?|replace(\s+into)?|upsert|truncate)`.
 * The pattern still requires `\s+` after the verb (so `updateHook` is
 * not matched), and the table name is still required after the verb
 * (so `update` alone doesn't match).
 *
 * Note: Eloquent's `upsert()` generates
 * `INSERT ... ON DUPLICATE KEY UPDATE` on MySQL/MariaDB (covered by
 * `insert\s+into`) or `INSERT ... ON CONFLICT` on PostgreSQL/SQLite.
 * The MySQL path is the common case and is now covered.
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

test('registerGlobalCacheListener regex covers REPLACE, TRUNCATE, upsert (R-PKG-024)', function () {
    $source = serviceProviderSource();

    // The new verb set must include all five: update, delete, insert,
    // replace, truncate, upsert. (insert is matched with optional
    // `into`, same for replace.)
    expect($source)->toContain('update|delete');
    expect($source)->toContain('insert(\s+into)?');
    expect($source)->toContain('replace(\s+into)?');
    expect($source)->toContain('|upsert|');
    expect($source)->toContain('|truncate)');
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

    // The code reads `$matches[5]` (after the broadening) to get the
    // table name and calls `Cache::tags([$table.'_all'])->flush()`.
    expect($source)->toContain("Cache::tags([\$table.'_all'])->flush()");
});
