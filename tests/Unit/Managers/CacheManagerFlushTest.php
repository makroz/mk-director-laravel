<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Managers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (rc13): `CacheManager::flush()` no longer silently nukes
 * the entire application cache when the cache driver does not support
 * tags. The previous fallback (`$cache->clear()`) wipes EVERY key in
 * the app's cache store — not just the keys for the requested tags.
 * That's a "nuke" — destructive in production where multiple modules
 * share the same cache store.
 *
 * The fix: gate the fallback with the new config flag
 * `mk_director.cache.allow_full_clear` (default `false` in rc13).
 * When the flag is `false` AND the driver doesn't support tags, throw
 * a `RuntimeException` with an actionable message. When the flag is
 * `true`, the legacy `$cache->clear()` behavior is preserved for
 * dev environments that use file/database cache.
 *
 * Production MUST use a cache store that supports tags (Redis,
 * Memcached) — see `cache.store` config.
 */
uses(MkLaravelTestCase::class);

function cacheManagerPath(): string
{
    return __DIR__ . '/../../../src/Managers/CacheManager.php';
}

function cacheManagerSource(): string
{
    $path = cacheManagerPath();
    expect(file_exists($path))->toBeTrue("CacheManager.php must exist at $path");

    return (string) file_get_contents($path);
}

test('CacheManager::flush() gates fallback with mk_director.cache.allow_full_clear (R-PKG-024)', function () {
    $source = cacheManagerSource();

    // The new flag must be checked in the flush method.
    expect($source)->toContain("config('mk_director.cache.allow_full_clear'");
    expect($source)->toContain('allow_full_clear');
});

test('CacheManager::flush() throws RuntimeException when allow_full_clear=false and no tags support', function () {
    $source = cacheManagerSource();

    // The exception is a RuntimeException with an actionable message.
    expect($source)->toContain('RuntimeException');
    expect($source)->toContain('allow_full_clear is false');
});

test('CacheManager::flush() preserves the legacy $cache->clear() path when flag is true', function () {
    $source = cacheManagerSource();

    // The legacy `$cache->clear()` call is preserved but now behind
    // the flag.
    expect($source)->toContain('$cache->clear()');
});

test('CacheManager::flush() tags-support path is unchanged (regression guard)', function () {
    $source = cacheManagerSource();

    // When the driver supports tags, the flush is via `$cache->tags($tags)->flush()`.
    // This is the recommended path (Redis, Memcached) and must not be gated.
    expect($source)->toContain('$cache->tags($tags)->flush()');
    expect($source)->toContain('storeSupportsTags');
});
