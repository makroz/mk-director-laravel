<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mk\Director\Tenancy\Concerns\HasTenantMembership;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HasTenantMembership — centralizes the "what is this user's tenant id?"
 * lookup so every tenancy-aware code path (TenantResolver, HasTenantScope,
 * MkMultiTenantPlugin, etc.) reads from a single source of truth.
 *
 * Spec: MK-LAR-1.0.6 + audit-2026-06-17-R2-004 (helper).
 *
 * Tests cover:
 *  - returns the column value when present
 *  - returns null when missing
 *  - getTenantColumn is overridable per model
 *  - returns null safely on a non-Model caller (defensive)
 *
 * @see audit-2026-06-17-R2-004
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    \Mockery::close();
});

/**
 * Concrete Eloquent model that uses the trait with the default column.
 */
final class TenantUserDefault extends Model
{
    use HasTenantMembership;

    protected $table = 'fake_tenant_users_default';
    protected $fillable = ['client_id', 'name'];
    public $timestamps = false;
}

/**
 * Concrete Eloquent model that overrides getTenantColumn to 'org_id'.
 */
final class TenantUserOrgId extends Model
{
    use HasTenantMembership;

    protected $table = 'fake_tenant_users_org_id';
    protected $fillable = ['org_id', 'name'];
    public $timestamps = false;

    public function getTenantColumn(): string
    {
        return 'org_id';
    }
}

test('HasTenantMembership: getTenantId returns the column value when present', function () {
    $user = new TenantUserDefault(['client_id' => 'tenant-42']);

    expect($user->getTenantId())->toBe('tenant-42');
});

test('HasTenantMembership: getTenantId returns null when the column is missing', function () {
    $user = new TenantUserDefault();

    expect($user->getTenantId())->toBeNull();
});

test('HasTenantMembership: getTenantId returns null when the column is empty string', function () {
    $user = new TenantUserDefault(['client_id' => '']);

    expect($user->getTenantId())->toBeNull();
});

test('HasTenantMembership: getTenantColumn default is "client_id"', function () {
    $user = new TenantUserDefault();

    expect($user->getTenantColumn())->toBe('client_id');
});

test('HasTenantMembership: getTenantColumn is overridable per model', function () {
    $user = new TenantUserOrgId(['org_id' => 'org-7']);

    expect($user->getTenantColumn())->toBe('org_id');
    expect($user->getTenantId())->toBe('org-7');
});

test('HasTenantMembership: cast integer tenant ids remain as-is (no PHP int conversion surprise)', function () {
    $user = new TenantUserDefault(['client_id' => 123]);

    // We accept whatever the column returns; the trait is a passthrough.
    // The tenant id type contract is enforced by TenantResolver (string|int|null).
    expect($user->getTenantId())->toBe(123);
});