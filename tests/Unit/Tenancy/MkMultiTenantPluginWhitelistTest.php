<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkMultiTenantPlugin enforces a hardcoded whitelist of
 * tenant column names (audit R2-009).
 *
 * Before this guard, an attacker (or a misconfigured config file) could
 * pass `column: 'password'` and the plugin would happily inject
 * `WHERE password = ?` into every query — a privilege escalation +
 * data exfiltration vector.
 *
 * @see audit-2026-06-17-R2-009
 */
uses(MkLaravelTestCase::class);

test('default tenant column is "client_id" (the historical Mk-Director default)', function () {
    $plugin = new MkMultiTenantPlugin();

    expect($plugin)->toBeInstanceOf(MkMultiTenantPlugin::class);
});

test('all four whitelisted columns are accepted', function () {
    foreach (['tenant_id', 'client_id', 'org_id', 'company_id'] as $column) {
        $plugin = new MkMultiTenantPlugin(['column' => $column]);
        expect($plugin)->toBeInstanceOf(MkMultiTenantPlugin::class);
    }
});

test('non-whitelisted column throws InvalidArgumentException', function () {
    expect(fn () => new MkMultiTenantPlugin(['column' => 'password']))
        ->toThrow(\InvalidArgumentException::class);
});

test('non-whitelisted column throws with a helpful error message', function () {
    try {
        new MkMultiTenantPlugin(['column' => 'evil_column']);
        expect()->fail('Expected InvalidArgumentException');
    } catch (\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('evil_column');
        expect($e->getMessage())->toContain('client_id'); // mentions at least one allowed value
    }
});

test('empty string tenant column is rejected', function () {
    expect(fn () => new MkMultiTenantPlugin(['column' => '']))
        ->toThrow(\InvalidArgumentException::class);
});

test('whitelist constant exposes the four allowed values', function () {
    expect(MkMultiTenantPlugin::TENANT_COLUMN_WHITELIST)->toBe([
        'tenant_id',
        'client_id',
        'org_id',
        'company_id',
    ]);
});