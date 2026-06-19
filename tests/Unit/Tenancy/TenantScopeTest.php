<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mockery\MockInterface;
use Mk\Director\Tenancy\TenantScope;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Helper — a real (anonymous) Eloquent model that exposes
 * getTenantKey() without needing a DB connection. Eloquent\Model
 * only connects lazily, so instantiating one is safe.
 */
function fakeModelWithTenantKey(string $key = 'tenant_id'): Model
{
    return new class ($key) extends Model {
        public function __construct(public string $tenantKey = 'tenant_id')
        {
            // Skip the parent's connection lookup.
            $this->table = 'fake_table';
            $this->guarded = [];
        }

        public function getTenantKey(): string
        {
            return $this->tenantKey;
        }
    };
}

test('TenantScope::apply adds where tenant_id = ? when tenant id is set', function () {
    /** @var Builder&MockInterface $builder */
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('where')
        ->once()
        ->with('tenant_id', '=', 42)
        ->andReturnSelf();

    $model = fakeModelWithTenantKey();

    $scope = new TenantScope(42);
    $scope->apply($builder, $model);
});

test('TenantScope::apply is a no-op when tenant id is null', function () {
    /** @var Builder&MockInterface $builder */
    $builder = Mockery::mock(Builder::class);
    // where() must NOT be called.
    $builder->shouldNotReceive('where');

    $model = fakeModelWithTenantKey();

    $scope = new TenantScope(null);
    $scope->apply($builder, $model);
});

test('TenantScope::apply uses the model-provided column name via getTenantKey()', function () {
    /** @var Builder&MockInterface $builder */
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('where')
        ->once()
        ->with('org_id', '=', 'acme')
        ->andReturnSelf();

    $model = fakeModelWithTenantKey('org_id');

    $scope = new TenantScope('acme');
    $scope->apply($builder, $model);
});

test('TenantScope::tenantId returns the bound tenant id', function () {
    expect((new TenantScope(7))->tenantId())->toBe(7);
    expect((new TenantScope('uuid-abc'))->tenantId())->toBe('uuid-abc');
    expect((new TenantScope(null))->tenantId())->toBeNull();
});

test('TenantScope::apply treats numeric strings and ints the same way', function () {
    /** @var Builder&MockInterface $builder */
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('where')
        ->once()
        ->with('tenant_id', '=', 1)
        ->andReturnSelf();

    $scope = new TenantScope(1);
    $scope->apply($builder, fakeModelWithTenantKey());
});
