<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Plugins;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (rc13): `MkMultiTenantPlugin::resolveTenantId()` should
 * prefer the public `getTenantId()` accessor from `HasTenantMembership`
 * over direct property access. This matches the pattern already used
 * by `TenantResolver` (TenantResolver.php:89-90) and lets consumers
 * with custom tenant-resolution logic (e.g. derived from org
 * memberships) override the accessor without touching the plugin.
 *
 * Why this matters: the previous code accessed `$user->{$this->tenantColumn}`
 * directly. That works for the common case (column on the model) but
 * fails when the tenant id is computed dynamically — e.g. when the
 * user model joins an `org_memberships` table at runtime.
 *
 * BC strategy: the new code uses `getTenantId()` if available, falls
 * back to direct property access for legacy consumers that don't
 * implement the trait. Both paths are exercised by the existing
 * test suite (Tenancy tests cover the trait behavior).
 */
uses(MkLaravelTestCase::class);

function multiTenantPluginPath(): string
{
    return __DIR__ . '/../../../src/Plugins/Enterprise/MkMultiTenantPlugin.php';
}

function multiTenantPluginSource(): string
{
    $path = multiTenantPluginPath();
    expect(file_exists($path))->toBeTrue("MkMultiTenantPlugin.php must exist at $path");

    return (string) file_get_contents($path);
}

function resolveTenantIdMethodSource(): string
{
    $source = multiTenantPluginSource();

    $start = strpos($source, 'function resolveTenantId(');
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

test('resolveTenantId uses getTenantId() method when available (R-PKG-024)', function () {
    $body = resolveTenantIdMethodSource();
    expect($body)->not->toBeEmpty();

    // The new code checks `method_exists($user, 'getTenantId')` and
    // calls it as the preferred path.
    expect($body)->toContain("method_exists(\$user, 'getTenantId')");
    expect($body)->toContain("\$user->getTenantId()");
});

test('resolveTenantId falls back to direct property access for legacy consumers (BC)', function () {
    $body = resolveTenantIdMethodSource();
    expect($body)->not->toBeEmpty();

    // The legacy path is preserved as a fallback when `getTenantId()`
    // is NOT available (e.g. consumer models that predated the
    // HasTenantMembership trait).
    expect($body)->toContain('$this->tenantColumn');
    // The fallback is the `$user->{$this->tenantColumn}` access.
    expect($body)->toMatch('/\$user\s*->\s*\{\s*\$this\s*->\s*tenantColumn\s*\}/');
});

test('resolveTenantId handles null user gracefully (no error)', function () {
    $body = resolveTenantIdMethodSource();
    expect($body)->not->toBeEmpty();

    // When `$user` is null (no auth), resolveTenantId should return
    // null (not throw). This is the behavior beforeQuery() expects.
    expect($body)->toContain('$user');
    expect($body)->toContain('return null');
});
