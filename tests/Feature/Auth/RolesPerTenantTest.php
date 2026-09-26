<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Access\AccessGrantDeniedException;
use Mk\Director\Auth\Access\AccessGrantGuard;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkAbilityUserPivot;
use Mk\Director\Auth\Pivots\MkPivot;
use Mk\Director\Auth\Pivots\MkRoleUserPivot;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk_director.tenant.roles_per_tenant`: en un consumer multi-tenant, cada
 * tenant tiene sus roles y no toca los de la plataforma ni el catálogo.
 *
 * 🔴 Medido en NetPizza antes del opt-in, por HTTP y con efecto en la base: el
 * dueño de un restaurante (`super-admin` + `*`) vaciaba y BORRABA el rol que
 * usaba otro restaurante, y renombraba abilities de todos. Los roles eran
 * globales y el `*` saltaba toda guarda.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    app(TenantContext::class)->flush();
    $this->tearDownHttpApp();
    MorphPivot::flushSchemaCache();
    MkPivot::clearUserTypeCache();
});

trait TenantRolePivots
{
    public function roles(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
                ->using(MkRoleUserPivot::class)
                ->withTimestamps()
        );
    }

    public function directAbilities(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Ability::class, 'ability_user', 'user_id', 'ability_id')
                ->using(MkAbilityUserPivot::class)
                ->withTimestamps()
        );
    }
}

final class TenantRoleAdmin extends AuthUser
{
    use TenantRolePivots;

    protected $table = 'tenant_role_admins';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('admin');
    }
}

function bootTenantRolesWorld(object $test, bool $perTenant = true): void
{
    $test->bootHttpApp(TenantRoleAdmin::class);
    foreach (glob(dirname(__DIR__, 3).'/src/Auth/Database/Migrations/*.php') ?: [] as $migration) {
        (require $migration)->up();
    }
    // La columna la agrega el consumer, con el tipo de su id de tenant, y el
    // único pasa a incluirla: dos tenants pueden tener cada uno su «cajero».
    Schema::table('roles', function ($t) {
        $t->string('tenant_id')->nullable();
        $t->dropUnique(['name', 'guard']);
        $t->unique(['name', 'guard', 'tenant_id']);
    });
    Schema::create('tenant_role_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('password')->nullable();
        $t->string('auth_scope')->nullable();
        $t->unsignedTinyInteger('status')->default(1);
        $t->timestamps();
    });
    config(['mk_director.tenant.roles_per_tenant' => $perTenant]);
}

/** Crea un rol del tenant dado (o de la plataforma con `null`) sin pasar por el scope. */
function tenantRole(string $name, ?string $tenant, array $abilities = []): Role
{
    $id = DB::table('roles')->insertGetId(['name' => $name, 'guard' => 'admin', 'tenant_id' => $tenant, 'is_fixed' => 0]);
    foreach ($abilities as $ability) {
        DB::table('ability_role')->insert(['role_id' => $id, 'ability_id' => Ability::query()->firstOrCreate(['name' => $ability])->id]);
    }

    return Role::query()->withoutGlobalScopes()->findOrFail($id);
}

function asTenant(?string $tenant): void
{
    $tenant === null ? app(TenantContext::class)->flush() : app(TenantContext::class)->set($tenant);
}

function tenantDenial(Closure $fn): ?string
{
    try {
        $fn();
    } catch (AccessGrantDeniedException $e) {
        return $e->errorCode;
    }

    return null;
}

test('🔴 desde un tenant se ven los roles de la plataforma y los suyos, nunca los de otro', function () {
    bootTenantRolesWorld($this);
    tenantRole('super-admin', null);
    tenantRole('cajero', 'napoli');
    tenantRole('cajero', 'roma');

    asTenant('napoli');
    expect(Role::query()->orderBy('id')->pluck('tenant_id')->all())->toBe([null, 'napoli']);

    asTenant(null);
    expect(Role::query()->count())->toBe(3);
});

test('un rol creado desde un tenant nace de ese tenant; sin contexto, de la plataforma', function () {
    bootTenantRolesWorld($this);

    asTenant('napoli');
    expect(Role::query()->create(['name' => 'mozo', 'guard' => 'admin'])->tenant_id)->toBe('napoli');

    asTenant(null);
    expect(Role::query()->create(['name' => 'viewer', 'guard' => 'admin'])->tenant_id)->toBeNull();
});

test('🔴 asignar un rol por nombre resuelve el del propio tenant aunque otro tenga uno igual', function () {
    bootTenantRolesWorld($this);
    // ⚠️ El ajeno es `napoli` y el propio `roma` a propósito: sin el scope, la
    // búsqueda por nombre recorre el índice (name, guard, tenant_id) y devuelve
    // el primero en orden alfabético. Al revés, el test pasaba sin el arreglo.
    tenantRole('cajero', 'napoli');
    $propio = tenantRole('cajero', 'roma');

    asTenant('roma');
    $mozo = TenantRoleAdmin::create(['name' => 'mozo', 'password' => 'x']);
    $mozo->assignRole('cajero');

    expect($mozo->fresh()->roles->pluck('id')->all())->toBe([$propio->id]);
});

test('🔴 ni con `*` se toca desde un tenant un rol de la plataforma o de otro tenant; el propio sí', function () {
    bootTenantRolesWorld($this);
    $plataforma = tenantRole('editor', null, ['admin.orders.view']);
    $roma = tenantRole('cajero', 'roma', ['admin.orders.view']);
    $propio = tenantRole('cajero', 'napoli', ['admin.orders.view']);

    asTenant('napoli');
    $dueno = TenantRoleAdmin::create(['name' => 'dueño', 'password' => 'x']);
    $dueno->giveAbilityTo('*');
    $guard = app(AccessGrantGuard::class);

    expect(tenantDenial(fn () => $guard->assertCanChangeRole($dueno->fresh(), $plataforma, [])))->toBe(AccessGrantGuard::ERR_PLATFORM_ROLE)
        ->and(tenantDenial(fn () => $guard->assertCanChangeRole($dueno->fresh(), $plataforma, null)))->toBe(AccessGrantGuard::ERR_PLATFORM_ROLE)
        ->and(tenantDenial(fn () => $guard->assertCanChangeRole($dueno->fresh(), $roma, [])))->toBe(AccessGrantGuard::ERR_PLATFORM_ROLE)
        ->and(tenantDenial(fn () => $guard->assertCanChangeRole($dueno->fresh(), $propio, ['admin.orders.view', 'admin.orders.update'])))->toBeNull();
});

test('🔴 desde un tenant el catálogo de abilities no se crea, renombra ni borra; sin tenant, rigen las reglas de siempre', function () {
    bootTenantRolesWorld($this);
    $ability = Ability::query()->create(['name' => 'admin.orders.view']);
    $guard = app(AccessGrantGuard::class);

    asTenant('napoli');
    expect(tenantDenial(fn () => $guard->assertCanChangeAbility(null, null, 'admin.orders.new')))->toBe(AccessGrantGuard::ERR_PLATFORM_ABILITY)
        ->and(tenantDenial(fn () => $guard->assertCanChangeAbility(null, $ability, 'admin.*')))->toBe(AccessGrantGuard::ERR_PLATFORM_ABILITY)
        ->and(tenantDenial(fn () => $guard->assertCanDeleteAbility(null, $ability)))->toBe(AccessGrantGuard::ERR_PLATFORM_ABILITY);

    asTenant(null);
    expect(tenantDenial(fn () => $guard->assertCanChangeAbility(null, $ability, 'admin.orders.view')))->toBeNull();
});

test('apagado (el default), los roles siguen siendo globales: nada cambia para quien no lo pidió', function () {
    bootTenantRolesWorld($this, perTenant: false);
    tenantRole('cajero', 'roma');
    $plataforma = tenantRole('editor', null);

    asTenant('napoli');
    $actor = TenantRoleAdmin::create(['name' => 'dueño', 'password' => 'x']);
    $actor->giveAbilityTo('*');

    expect(Role::query()->count())->toBe(2)
        ->and(Role::query()->create(['name' => 'mozo', 'guard' => 'admin'])->tenant_id)->toBeNull()
        ->and(tenantDenial(fn () => app(AccessGrantGuard::class)->assertCanChangeRole($actor->fresh(), $plataforma, [])))->toBeNull();
});
