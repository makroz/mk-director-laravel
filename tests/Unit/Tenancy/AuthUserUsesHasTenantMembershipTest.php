<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that the AuthUser model uses the HasTenantMembership trait so
 * that every tenant-aware code path reads the tenant id through a single
 * source of truth (audit R2-004).
 *
 * Implementation note: we parse the source file rather than reflecting
 * the class because AuthUser uses Laravel\\Sanctum\\HasApiTokens which is
 * not a runtime dependency of the package.
 *
 * @see audit-2026-06-17-R2-004
 */
uses(MkLaravelTestCase::class);

function authUserSourcePath(): string
{
    return __DIR__ . '/../../../src/Auth/Models/AuthUser.php';
}

function authUserUsesHasTenantMembership(): bool
{
    $src = (string) file_get_contents(authUserSourcePath());
    return (bool) preg_match('/use\s+HasTenantMembership\s*;/', $src);
}

test('AuthUser uses the HasTenantMembership trait', function () {
    expect(authUserUsesHasTenantMembership())->toBeTrue(
        'AuthUser must `use HasTenantMembership;` so TenantResolver / MkMultiTenantPlugin ' .
        'read \$user->getTenantId() instead of poking at \$user->client_id.'
    );
});

test('HasTenantMembership trait file exists and exposes the expected API', function () {
    $path = __DIR__ . '/../../../src/Tenancy/Concerns/HasTenantMembership.php';
    expect(file_exists($path))->toBeTrue();

    $src = (string) file_get_contents($path);
    expect($src)->toContain('public function getTenantId(): ');
    expect($src)->toContain('public function getTenantColumn(): string');
    expect($src)->toContain("'client_id'");
});