<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
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
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `AccessGrantGuard`: nadie se da a sí mismo lo que no tiene, ni se lo saca a
 * quien tiene más que él.
 *
 * Medido en el piloto NetPizza, por la cadena HTTP real: un encargado cuya
 * ÚNICA ability era `admin.admins.update` hizo
 * `POST /api/admins/{su propio id}/abilities {abilities: ['admin.branches.viewAll', 'admin.admins.delete']}`
 * → 200, y `canMk('admin.branches.viewAll')` pasó a true. El endpoint generado
 * lo gateaba sólo con `update`, y su propio comentario decía «puede escalar
 * privilegios si no se gatea explícitamente».
 *
 * Contra sqlite real, con las migraciones de RBAC del paquete: las abilities
 * efectivas salen de roles y grants directos de verdad, no de un mock.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    $this->tearDownHttpApp();
    // Las migraciones de RBAC del paquete crean las pivots CON `user_type`, y
    // esa detección se cachea por proceso: sin limpiarla, el archivo siguiente
    // (con pivots sin la columna) escribe `user_type` y revienta.
    MorphPivot::flushSchemaCache();
    MkPivot::clearUserTypeCache();
});

/** Los overrides de pivots que el scaffolder emite en todo modelo de scope. */
trait GuardTestPivots
{
    public function roles(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
                ->using(MkRoleUserPivot::class)
                ->wherePivot('user_type', static::class)
                ->withTimestamps()
        );
    }

    public function directAbilities(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Ability::class, 'ability_user', 'user_id', 'ability_id')
                ->using(MkAbilityUserPivot::class)
                ->wherePivot('user_type', static::class)
                ->withTimestamps()
        );
    }
}

final class GuardAdmin extends AuthUser
{
    use GuardTestPivots;

    protected $table = 'guard_admins';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('admin');
    }
}

final class GuardMesero extends AuthUser
{
    use GuardTestPivots;

    protected $table = 'guard_meseros';

    protected $guarded = [];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('mesero');
    }
}

function bootGuardWorld(object $test): void
{
    $test->bootHttpApp(GuardAdmin::class);
    foreach (glob(dirname(__DIR__, 3).'/src/Auth/Database/Migrations/*.php') ?: [] as $migration) {
        (require $migration)->up();
    }
    foreach (['guard_admins', 'guard_meseros'] as $table) {
        Schema::create($table, function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->string('auth_scope')->nullable();
            $t->unsignedTinyInteger('status')->default(1);
            $t->timestamps();
        });
    }
}

/** @param  array<int, string>  $abilities  grants directos */
function guardUser(string $class, string $name, array $abilities = [], array $roles = []): AuthUser
{
    /** @var AuthUser $user */
    $user = $class::create(['name' => $name, 'password' => 'x']);
    foreach ($abilities as $ability) {
        $user->giveAbilityTo($ability);
    }
    foreach ($roles as $role) {
        $user->assignRole($role);
    }

    return $user->fresh(['roles.abilities', 'directAbilities']);
}

function guardRole(string $name, string $guard, array $abilities): Role
{
    $role = Role::query()->create(['name' => $name, 'guard' => $guard]);
    foreach ($abilities as $ability) {
        $id = Ability::query()->firstOrCreate(['name' => $ability])->id;
        DB::table('ability_role')->insert(['role_id' => $role->id, 'ability_id' => $id]);
    }

    return $role;
}

/** Corre `$fn` y devuelve el código del rechazo, o null si pasó. */
function guardDenial(Closure $fn): ?string
{
    try {
        $fn();
    } catch (AccessGrantDeniedException $e) {
        return $e->errorCode;
    }

    return null;
}

test('el caso medido: con sólo `admin.admins.update`, no se da a sí mismo abilities', function () {
    bootGuardWorld($this);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.admins.update']);

    expect(guardDenial(fn () => app(AccessGrantGuard::class)->assertCanChangeAccess($manager, $manager, null, ['admin.branches.viewAll', 'admin.admins.delete'])))
        ->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
});

test('a OTRO: sólo concede abilities que tiene; lo que tiene sí', function () {
    bootGuardWorld($this);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.admins.update', 'admin.orders.view']);
    $staff = guardUser(GuardAdmin::class, 'mozo');
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, null, ['admin.branches.viewAll'])))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, null, ['admin.orders.view'])))->toBeNull();
});

test('roles: concede un rol sólo si tiene TODAS sus abilities; `*` sólo lo da quien tiene `*`', function () {
    bootGuardWorld($this);
    guardRole('cajero', 'admin', ['admin.orders.view']);
    guardRole('gerente', 'admin', ['admin.orders.view', 'admin.branches.viewAll']);
    guardRole('super-admin', 'admin', ['*']);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.orders.view']);
    $staff = guardUser(GuardAdmin::class, 'mozo');
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, ['cajero'], null)))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, ['gerente'], null)))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, ['super-admin'], null)))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
});

test('quitar lo que el actor no tiene también está prohibido (no desarma a quien tiene más)', function () {
    bootGuardWorld($this);
    guardRole('gerente', 'admin', ['admin.branches.viewAll']);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.orders.view', 'admin.admins.update']);
    // El target tiene sólo lo que el actor tiene (así no lo "supera") más un
    // grant directo del actor; quitarle ese grant sí se puede.
    $staff = guardUser(GuardAdmin::class, 'mozo', ['admin.orders.view']);
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $staff, null, [])))->toBeNull();

    // Un target con algo que el actor no tiene: el actor no puede tocarlo.
    $boss = guardUser(GuardAdmin::class, 'jefe', [], ['gerente']);
    expect(guardDenial(fn () => $guard->assertCanChangeAccess($manager, $boss, [], null)))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
});

test('quien tiene `*` concede cualquier cosa (a otros)', function () {
    bootGuardWorld($this);
    guardRole('super-admin', 'admin', ['*']);
    $owner = guardUser(GuardAdmin::class, 'dueño', [], ['super-admin']);
    $staff = guardUser(GuardAdmin::class, 'mozo');
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanChangeAccess($owner, $staff, ['super-admin'], ['admin.branches.viewAll'])))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanChangeAccess($owner, $owner, [], null)))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
});

test('editar o borrar a quien tiene MÁS acceso: prohibido; a un par, permitido', function () {
    bootGuardWorld($this);
    guardRole('super-admin', 'admin', ['*']);
    $owner = guardUser(GuardAdmin::class, 'dueño', [], ['super-admin']);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.admins.update', 'admin.admins.delete']);
    $peer = guardUser(GuardAdmin::class, 'par', ['admin.admins.update']);
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanUpdate($manager, $owner, ['status' => 3])))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(guardDenial(fn () => $guard->assertCanDelete($manager, $owner)))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(guardDenial(fn () => $guard->assertCanUpdate($manager, $peer, ['status' => 3])))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanDelete($manager, $peer)))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanUpdate($owner, $manager, ['status' => 3])))->toBeNull();
});

test('su propio status: cambiarlo no; mandar el mismo valor o editar el nombre sí', function () {
    bootGuardWorld($this);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.admins.update']);
    DB::table('guard_admins')->where('id', $manager->getKey())->update(['status' => 3]);
    $manager = $manager->fresh(['roles.abilities', 'directAbilities']);
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanUpdate($manager, $manager, ['status' => 1])))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
    expect(guardDenial(fn () => $guard->assertCanUpdate($manager, $manager, ['status' => 3, 'name' => 'Nuevo'])))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanUpdate($manager, $manager, ['name' => 'Nuevo'])))->toBeNull();
});

test('otro scope (recurso managed): lo gatea la ability del manager, el guard no compara namespaces distintos', function () {
    bootGuardWorld($this);
    $admin = guardUser(GuardAdmin::class, 'admin', ['admin.meseros.update']);
    $mesero = guardUser(GuardMesero::class, 'mesero', ['mesero.orders.create']);
    $guard = app(AccessGrantGuard::class);

    expect(guardDenial(fn () => $guard->assertCanChangeAccess($admin, $mesero, null, ['mesero.orders.create', 'mesero.tables.view'])))->toBeNull();
    expect(guardDenial(fn () => $guard->assertCanUpdate($admin, $mesero, ['status' => 3])))->toBeNull();
});

test('sin actor (consola, seeders) no hay escalada que frenar', function () {
    bootGuardWorld($this);
    $staff = guardUser(GuardAdmin::class, 'mozo');

    expect(guardDenial(fn () => app(AccessGrantGuard::class)->assertCanChangeAccess(null, $staff, ['cualquiera'], ['admin.*'])))->toBeNull();
});

test('el rechazo se renderiza como 403 con el sobre del paquete y el código específico', function () {
    bootGuardWorld($this);
    $manager = guardUser(GuardAdmin::class, 'encargado', ['admin.admins.update']);

    try {
        app(AccessGrantGuard::class)->assertCanChangeAccess($manager, $manager, null, ['admin.x']);
        $this->fail('tenía que rechazar');
    } catch (AccessGrantDeniedException $e) {
        $response = $e->render(Request::create('/'));
        expect($response->getStatusCode())->toBe(403);
        $body = json_decode((string) $response->getContent(), true);
        expect($body['success'])->toBeFalse();
        expect($body['__extraData']['code'])->toBe('ERR_SELF_ACCESS_CHANGE');
    }
});
