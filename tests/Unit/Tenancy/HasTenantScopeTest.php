<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Mockery;
use Mk\Director\Tenancy\HasTenantScope;
use Mk\Director\Tenancy\TenantContext;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
    // Reset the container so tests do not leak state.
    Container::setInstance(null);
});

/**
 * A minimal concrete model that uses HasTenantScope. It is registered
 * on the Eloquent boot registry lazily — we never connect to a DB
 * because we test the trait's config + container behavior, not
 * the SQL it produces.
 */
function newDemoModel(): Model
{
    $cls = new class extends Model {
        use HasTenantScope;

        protected $table = 'demo_tenantables';

        protected $guarded = [];
    };

    return new $cls;
}

test('HasTenantScope::getTenantKey returns tenant_id by default', function () {
    $model = newDemoModel();

    expect($model->getTenantKey())->toBe('tenant_id');
});

test('HasTenantScope can override the tenant key column', function () {
    $cls = new class extends Model {
        use HasTenantScope;

        protected $table = 'orgs';

        protected $guarded = [];

        public function getTenantKey(): string
        {
            return 'org_id';
        }
    };

    expect((new $cls)->getTenantKey())->toBe('org_id');
});

test('HasTenantScope does not register the global scope when tenant.enabled is false', function () {
    // Boot a fresh container with config that has tenant.enabled = false.
    $app = new Container();
    Container::setInstance($app);

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('get')
        ->with('mk_director.tenant.enabled', false)
        ->andReturn(false);
    $app->instance('config', $config);

    $cls = newDemoModel()::class;

    // Eloquent stores global scopes in a static $globalScopes array
    // keyed by class name. Reset it to mimic a fresh boot.
    $cls::clearBootedModels();
    $ref = new \ReflectionClass(Model::class);
    $prop = $ref->getProperty('globalScopes');
    $prop->setAccessible(true);
    $prop->setValue(null, []);

    // Trigger the static boot hook.
    $cls::bootHasTenantScope();

    $all = $prop->getValue();
    $scopes = $all[$cls] ?? [];

    expect($scopes)->toBeArray()->toBeEmpty();
});

test('HasTenantScope registers the global scope when tenant.enabled is true and TenantContext has a value', function () {
    // Boot a fresh container with the feature enabled and a
    // TenantContext that has a value.
    $app = new Container();
    Container::setInstance($app);

    $config = Mockery::mock(Config::class);
    $config->shouldReceive('get')
        ->with('mk_director.tenant.enabled', false)
        ->andReturn(true);
    $app->instance('config', $config);

    $context = new TenantContext();
    $context->set(42);
    $app->instance(TenantContext::class, $context);

    $cls = newDemoModel()::class;

    $cls::clearBootedModels();
    $ref = new \ReflectionClass(Model::class);
    $prop = $ref->getProperty('globalScopes');
    $prop->setAccessible(true);
    $prop->setValue(null, []);

    // Trigger the boot hook.
    $cls::bootHasTenantScope();

    $all = $prop->getValue();
    $scopes = $all[$cls] ?? [];

    // After the B-3 refactor, the registered scope is a TenantScope
    // instance (constructed without an id) instead of a closure. The
    // scope reads the TenantContext lazily on every apply() call.
    expect($scopes)->toBeArray()->toHaveKey('tenant');
    expect($scopes['tenant'])->toBeInstanceOf(\Mk\Director\Tenancy\TenantScope::class);
});

test('HasTenantScope is a no-op when no container is bound (CLI without app)', function () {
    // No container bound at all.
    Container::setInstance(null);

    $cls = newDemoModel()::class;

    $cls::clearBootedModels();
    $ref = new \ReflectionClass(Model::class);
    $prop = $ref->getProperty('globalScopes');
    $prop->setAccessible(true);
    $prop->setValue(null, []);

    $cls::bootHasTenantScope();

    $all = $prop->getValue();
    $scopes = $all[$cls] ?? [];

    expect($scopes)->toBeArray()->toBeEmpty();
});
