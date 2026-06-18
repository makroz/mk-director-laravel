<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkServiceProvider registers a `terminating` callback
 * that flushes the TenantContext (audit R2-005).
 *
 * Long-lived workers (Octane / Swoole) reuse the PHP process across
 * requests. Without an explicit flush, the TenantContext singleton
 * carries the previous request's tenant id into the next request —
 * a cross-tenant data leak (R2-005).
 *
 * @see audit-2026-06-17-R2-005
 */
uses(MkLaravelTestCase::class);

function mkServiceProviderSource(): string
{
    return (string) file_get_contents(__DIR__ . '/../../../src/MkServiceProvider.php');
}

test('MkServiceProvider registers a terminating callback that flushes TenantContext', function () {
    $src = mkServiceProviderSource();

    expect($src)->toContain('terminating');
    expect($src)->toContain('TenantContext');
    expect($src)->toContain('->flush()');
});

test('MkServiceProvider terminating callback is positioned in boot() (not register)', function () {
    $src = mkServiceProviderSource();

    $bootPos = strpos($src, 'public function boot()');
    $registerPos = strpos($src, 'public function register()');
    $terminatingPos = strpos($src, 'terminating');

    expect($bootPos)->toBeGreaterThan(0);
    expect($registerPos)->toBeGreaterThan(0);
    expect($terminatingPos)->toBeGreaterThan(0);

    // terminating() should be inside boot(), which is declared after register().
    expect($terminatingPos)->toBeGreaterThan($bootPos);
});

test('MkServiceProvider terminating callback swallows flush failures', function () {
    // The callback wraps the flush in try/catch so a buggy TenantContext
    // cannot break the response pipeline.
    $src = mkServiceProviderSource();

    $terminatingPos = strpos($src, 'terminating');
    $terminatingEnd = strpos($src, '});', $terminatingPos);
    expect($terminatingEnd)->toBeGreaterThan($terminatingPos);

    $callbackBody = substr($src, $terminatingPos, $terminatingEnd - $terminatingPos);
    expect($callbackBody)->toContain('try');
    expect($callbackBody)->toContain('catch');
});