<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Tenancy\NullTenantConnectionResolver;
use Mk\Director\Tenancy\TenantConnectionResolver;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * S1 — the tenant's connection becomes the default while the tenant is
 * active, and the original one comes back on flush.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class SwitchTestResolver implements TenantConnectionResolver
{
    public function connectionFor(string|int $tenantId): ?string
    {
        return match ($tenantId) {
            'a' => 'tenant_a',
            'b' => 'tenant_b',
            default => null,
        };
    }
}

final class SwitchItem extends Model
{
    protected $table = 'switch_items';

    public $timestamps = false;

    protected $guarded = [];
}

function bootSwitchApp(?string $resolver): void
{
    test()->bootHttpApp(PushTestUser::class, [
        'tenant' => ['enabled' => false] + ($resolver !== null ? ['connection_resolver' => $resolver] : []),
    ]);

    foreach (['tenant_a', 'tenant_b'] as $name) {
        config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    }

    foreach (['testing' => 'main', 'tenant_a' => 'row-a', 'tenant_b' => 'row-b'] as $connection => $label) {
        Schema::connection($connection)->create('switch_items', fn ($t) => $t->string('label'));
        DB::connection($connection)->table('switch_items')->insert(['label' => $label]);
    }
}

test('a resolver that returns a connection makes it the default while the tenant is active, flush brings the original back', function () {
    bootSwitchApp(SwitchTestResolver::class);
    $tenant = app(TenantContext::class);

    expect(SwitchItem::pluck('label')->all())->toBe(['main']);

    $tenant->set('a');
    expect(DB::getDefaultConnection())->toBe('tenant_a');
    expect(SwitchItem::pluck('label')->all())->toBe(['row-a']);

    $tenant->set('b');
    expect(SwitchItem::pluck('label')->all())->toBe(['row-b']);

    $tenant->flush();
    expect(DB::getDefaultConnection())->toBe('testing');
    expect(SwitchItem::pluck('label')->all())->toBe(['main']);
});

test('run() restores the connection of the previous tenant, or the original one', function () {
    bootSwitchApp(SwitchTestResolver::class);
    $tenant = app(TenantContext::class);

    $tenant->set('a');
    $tenant->run('b', fn () => expect(SwitchItem::pluck('label')->all())->toBe(['row-b']));
    expect(SwitchItem::pluck('label')->all())->toBe(['row-a']);

    $tenant->flush();
    $tenant->run('b', fn () => null);
    expect(DB::getDefaultConnection())->toBe('testing');
});

test('a tenant the resolver maps to null goes back to the original connection', function () {
    bootSwitchApp(SwitchTestResolver::class);
    $tenant = app(TenantContext::class);

    $tenant->set('a');
    $tenant->set('unmapped');

    expect(DB::getDefaultConnection())->toBe('testing');
});

test('with the default resolver nothing changes', function (?string $resolver) {
    bootSwitchApp($resolver);
    $tenant = app(TenantContext::class);

    expect(app(TenantConnectionResolver::class))->toBeInstanceOf(NullTenantConnectionResolver::class);

    $tenant->set('a');
    expect(DB::getDefaultConnection())->toBe('testing');
    expect(SwitchItem::pluck('label')->all())->toBe(['main']);

    $tenant->flush();
    expect(DB::getDefaultConnection())->toBe('testing');
})->with([
    'key absent' => [null],
    'key set to the null resolver' => [NullTenantConnectionResolver::class],
]);
