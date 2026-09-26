<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mk\Director\Auth\Access\AccessGrantDeniedException;
use Mk\Director\Auth\Access\AccessGrantGuard;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkPivot;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El CRUD GENERADO no deja escalar privilegios.
 *
 * Medido en el piloto NetPizza, por la cadena HTTP real: un admin cuya ÚNICA
 * ability era `admin.admins.update` hizo
 * `POST /api/admins/{su propio id}/abilities {abilities: ['admin.branches.viewAll', 'admin.admins.delete']}`
 * → 200, y las tuvo. Y nada impedía que ese mismo encargado bloqueara o borrara
 * al dueño (`PUT /api/admins/{dueño}` con `status=3`, o `DELETE`).
 *
 * Se ejecuta el código GENERADO — el controller, sus FormRequests y el Service —
 * sobre sqlite real con la migración generada. Un test sobre el guard solo
 * pasaría en verde con el controller sin llamarlo, que era exactamente el bug.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    // Las migraciones de RBAC del paquete crean las pivots CON `user_type`, y
    // esa detección se cachea por proceso: sin limpiarla, el archivo siguiente
    // (con pivots sin la columna) escribe `user_type` y revienta.
    MorphPivot::flushSchemaCache();
    MkPivot::clearUserTypeCache();
    $this->cleanScaffolderTempDirs();
});

function bootGeneratedCrew(object $test): void
{
    [$exit, $output, $base] = $test->runScaffolderInTempDir(['scope' => 'Crew']);
    expect($exit)->toBe(0, $output);

    $module = "{$base}/app/Modules/Crew";
    foreach (array_merge(glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [], glob("{$module}/Database/Migrations/*.php") ?: []) as $migration) {
        (require $migration)->up();
    }

    foreach ([
        'Enums/CrewStatus.php', 'Models/Crew.php', 'Repositories/Contracts/CrewRepositoryInterface.php',
        'Repositories/CrewRepository.php', 'Services/CrewService.php', 'Http/Requests/AssignAccessRequest.php',
        'Http/Requests/AssignRolesRequest.php', 'Http/Requests/AssignDirectAbilitiesRequest.php', 'Http/Resources/CrewResource.php', 'Http/Controllers/CrewController.php',
        'Http/Resources/RoleResource.php', 'Http/Resources/AbilityResource.php', 'Http/Controllers/RoleController.php', 'Http/Controllers/AbilityController.php', 'Http/Requests/SyncRoleAbilitiesRequest.php',
    ] as $file) {
        if (! class_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)
            && ! interface_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)
            && ! enum_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)) {
            require "{$module}/{$file}";
        }
    }

    // `$request->validate()` es una macro que en un consumer registra
    // `FoundationServiceProvider`; el kernel pelado de `BootsHttpApp` no la trae.
    Request::macro('validate', function (array $rules, ...$params) {
        return validator()->validate($this->all(), $rules, ...$params);
    });

    // Lo que hace el ServiceProvider generado: sin esto el controller no resuelve el Service.
    app()->bind('App\\Modules\\Crew\\Repositories\\Contracts\\CrewRepositoryInterface', 'App\\Modules\\Crew\\Repositories\\CrewRepository');

    foreach (['crew.crews.update', 'crew.crews.delete', 'crew.branches.viewAll', 'crew.roles.update', 'crew.roles.delete', '*'] as $name) {
        Ability::query()->firstOrCreate(['name' => $name]);
    }
}

/** @param  array<int, string>  $abilities */
function crewUser(string $name, array $abilities): AuthUser
{
    $class = 'App\\Modules\\Crew\\Models\\Crew';
    /** @var AuthUser $user */
    $user = $class::create(['name' => $name, 'email' => "{$name}@crew.test", 'password' => 'secreto123']);
    foreach ($abilities as $ability) {
        $user->giveAbilityTo($ability);
    }

    return $user->fresh(['roles.abilities', 'directAbilities']);
}

/** FormRequest generado, resuelto y validado como lo hace Laravel, con `$actor` autenticado. */
function crewFormRequest(string $class, array $data, AuthUser $actor): Request
{
    $request = ('App\\Modules\\Crew\\Http\\Requests\\'.$class)::create('/', 'POST', $data);
    $request->setContainer(app());
    // El Resource de la respuesta arma URLs: necesita la request en el container.
    // 🔴 ANTES del user resolver: al RE-bindear `request`, el AuthServiceProvider
    // le pisa el resolver con el del guard por defecto (que acá no autenticó a
    // nadie). Puesto al revés, desde la segunda request `user()` daba null y el
    // guard veía "sin actor": un verde que no medía nada.
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $actor);
    $request->validateResolved();

    return $request;
}

function crewDenial(Closure $fn): ?string
{
    try {
        $fn();
    } catch (AccessGrantDeniedException $e) {
        return $e->errorCode;
    }

    return null;
}

test('el caso medido: con sólo `update`, POST /{propio id}/abilities es 403 y no gana nada', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.crews.update']);
    $controller = new ('App\\Modules\\Crew\\Http\\Controllers\\CrewController');

    $request = crewFormRequest('AssignDirectAbilitiesRequest', ['abilities' => ['crew.branches.viewAll', 'crew.crews.delete']], $manager);

    expect(crewDenial(fn () => $controller->assignDirectAbilities((string) $manager->getKey(), $request)))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
    expect($manager->fresh(['roles.abilities', 'directAbilities'])->getEffectiveAbilities())->toBe(['crew.crews.update']);
});

test('/access y /roles sobre sí mismo también son 403', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.crews.update']);
    $controller = new ('App\\Modules\\Crew\\Http\\Controllers\\CrewController');
    Role::query()->create(['name' => 'super-admin', 'guard' => 'crew']);

    $access = crewFormRequest('AssignAccessRequest', ['roles' => ['super-admin'], 'abilities' => ['crew.branches.viewAll']], $manager);
    expect(crewDenial(fn () => $controller->assignAccess((string) $manager->getKey(), $access)))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);

    $roles = crewFormRequest('AssignRolesRequest', ['roles' => ['super-admin']], $manager);
    expect(crewDenial(fn () => $controller->assignRoles((string) $manager->getKey(), $roles)))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
});

test('a OTRO: conceder lo que no tiene es 403; lo que tiene pasa (contraprueba)', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.crews.update']);
    $staff = crewUser('mozo', []);
    $controller = new ('App\\Modules\\Crew\\Http\\Controllers\\CrewController');

    $escalate = crewFormRequest('AssignDirectAbilitiesRequest', ['abilities' => ['crew.branches.viewAll']], $manager);
    expect(crewDenial(fn () => $controller->assignDirectAbilities((string) $staff->getKey(), $escalate)))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);

    $held = crewFormRequest('AssignDirectAbilitiesRequest', ['abilities' => ['crew.crews.update']], $manager);
    expect($controller->assignDirectAbilities((string) $staff->getKey(), $held)->getStatusCode())->toBe(200);
    expect($staff->fresh(['roles.abilities', 'directAbilities'])->getEffectiveAbilities())->toBe(['crew.crews.update']);
});

test('Service generado: bloquear o borrar al dueño es 403; su propio status también', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $manager = crewUser('encargado', ['crew.crews.update', 'crew.crews.delete']);
    $service = new ('App\\Modules\\Crew\\Services\\CrewService')(new ('App\\Modules\\Crew\\Repositories\\CrewRepository'));
    $asManager = Request::create('/', 'PUT');
    $asManager->setUserResolver(fn () => $manager);

    expect(crewDenial(fn () => $service->beforeUpdate($asManager, (string) $owner->getKey(), ['status' => 3])))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(crewDenial(fn () => $service->beforeDelete($asManager, $owner, (string) $owner->getKey())))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(crewDenial(fn () => $service->beforeUpdate($asManager, (string) $manager->getKey(), ['status' => 3])))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);

    // Contraprueba: el dueño sí bloquea al encargado, y el encargado edita su nombre.
    $asOwner = Request::create('/', 'PUT');
    $asOwner->setUserResolver(fn () => $owner);
    expect(crewDenial(fn () => $service->beforeUpdate($asOwner, (string) $manager->getKey(), ['status' => 3])))->toBeNull();
    expect(crewDenial(fn () => $service->beforeUpdate($asManager, (string) $manager->getKey(), ['name' => 'Nuevo'])))->toBeNull();
});

/**
 * 🔴 El Service de usuarios corría también para roles y abilities.
 *
 * `RoleController` y `AbilityController` generados declaraban el MISMO
 * `'service' => CrewService::class`, así que `PUT /roles/{id}` llegaba a
 * `CrewService::beforeUpdate()` con el id de un ROL y hacía
 * `Crew::find($id)`: buscaba un uuid con un entero. En Postgres eso es
 * `SQLSTATE[22P02]` → 500 en toda edición de un rol o una ability (medido en
 * RETO). En sqlite/MySQL no revienta, devuelve null — por eso se mide la
 * CONSULTA, que es la causa, y no el 500, que depende del motor.
 */
test('editar un rol o una ability NO busca un usuario con su id', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $role = Role::query()->create(['name' => 'mesero', 'guard' => 'crew']);
    $ability = Ability::query()->where('name', 'crew.crews.update')->firstOrFail();

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    foreach ([
        ['RoleController', (string) $role->getKey(), ['name' => 'mozo', 'guard' => 'crew']],
        ['AbilityController', (string) $ability->getKey(), ['description' => 'Editar crews.']],
    ] as [$class, $id, $data]) {
        $request = Request::create('/', 'PUT', $data);
        app()->instance('request', $request);
        $request->setUserResolver(fn () => $owner);

        $queries = [];
        $response = (new ('App\\Modules\\Crew\\Http\\Controllers\\'.$class))->update($request, $id);

        expect($response->getStatusCode())->toBe(200, $class);
        expect(array_filter($queries, fn (string $sql) => preg_match('/from ["`]?crews["`]?/i', $sql) === 1))
            ->toBe([], "{$class}::update consultó la tabla de usuarios");
    }

    expect($role->fresh()->name)->toBe('mozo');
});

test('la guarda sigue viva en el CRUD de USUARIOS: editar al dueño por el controller es 403', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $manager = crewUser('encargado', ['crew.crews.update']);

    $request = Request::create('/', 'PUT', ['status' => 3]);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $manager);

    $controller = new ('App\\Modules\\Crew\\Http\\Controllers\\CrewController');
    expect(crewDenial(fn () => $controller->update($request, (string) $owner->getKey())))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
});

/**
 * 🔴 El CRUD de ROLES es otra puerta al mismo acceso.
 *
 * Editar las abilities de un rol es editárselas a TODOS los que lo tienen, y el
 * `RoleController` generado no pasaba por `AccessGrantGuard`: con sólo
 * `crew.roles.update`, un encargado le agregaba abilities a su PROPIO rol por
 * `PUT /roles/{id}/abilities` y las tenía (medido en RETO, #26).
 */
function crewRole(string $name, array $abilities, bool $fixed = false): Role
{
    $role = Role::query()->create(['name' => $name, 'guard' => 'crew', 'is_fixed' => $fixed ? 1 : 0]);
    $role->abilities()->sync(Ability::query()->whereIn('name', $abilities)->pluck('id'));

    return $role;
}

function crewRoleController(): object
{
    return new ('App\\Modules\\Crew\\Http\\Controllers\\RoleController');
}

function crewPut(array $data, AuthUser $actor, string $method = 'PUT'): Request
{
    $request = Request::create('/', $method, $data);
    app()->instance('request', $request);
    $request->setUserResolver(fn () => $actor);

    return $request;
}

/** @return array<int, string> */
function roleAbilityNames(Role $role): array
{
    return $role->fresh()->abilities()->pluck('name')->sort()->values()->all();
}

test('rol: nadie cambia las abilities de un rol que TIENE — el caso medido', function () {
    bootGeneratedCrew($this);
    $role = crewRole('encargado', ['crew.roles.update', 'crew.crews.update']);
    $manager = crewUser('encargado', []);
    $manager->assignRole($role);
    $manager = $manager->fresh(['roles.abilities', 'directAbilities']);

    $request = crewFormRequest('SyncRoleAbilitiesRequest', ['abilities' => ['crew.roles.update', 'crew.crews.update', 'crew.crews.delete']], $manager);

    expect(crewDenial(fn () => crewRoleController()->syncAbilities((string) $role->getKey(), $request)))->toBe(AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
    expect(roleAbilityNames($role))->toBe(['crew.crews.update', 'crew.roles.update']);
});

test('rol: sólo se agrega o se quita lo que el actor tiene; lo que tiene pasa (contraprueba)', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.roles.update', 'crew.crews.update']);
    $waiter = crewRole('mesero', []);

    $escalate = crewFormRequest('SyncRoleAbilitiesRequest', ['abilities' => ['crew.branches.viewAll']], $manager);
    expect(crewDenial(fn () => crewRoleController()->syncAbilities((string) $waiter->getKey(), $escalate)))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(roleAbilityNames($waiter))->toBe([]);

    $held = crewFormRequest('SyncRoleAbilitiesRequest', ['abilities' => ['crew.crews.update']], $manager);
    expect(crewRoleController()->syncAbilities((string) $waiter->getKey(), $held)->getStatusCode())->toBe(200);
    expect(roleAbilityNames($waiter))->toBe(['crew.crews.update']);
});

test('rol: nadie toca un rol que tiene alguien con MÁS acceso — ni sync, ni edición, ni borrado', function () {
    bootGeneratedCrew($this);
    $cashier = crewRole('caja', ['crew.crews.update']);
    $owner = crewUser('dueño', ['*']);
    $owner->assignRole($cashier);
    $manager = crewUser('encargado', ['crew.roles.update', 'crew.roles.delete', 'crew.crews.update']);

    // Un sync que no cambia nada: el rol es de alguien con más acceso, y eso ya alcanza.
    $sync = crewFormRequest('SyncRoleAbilitiesRequest', ['abilities' => ['crew.crews.update']], $manager);
    expect(crewDenial(fn () => crewRoleController()->syncAbilities((string) $cashier->getKey(), $sync)))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(crewDenial(fn () => crewRoleController()->update(crewPut(['name' => 'otra', 'guard' => 'crew'], $manager), (string) $cashier->getKey())))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
    expect(crewDenial(fn () => crewRoleController()->destroy(crewPut([], $manager, 'DELETE'), (string) $cashier->getKey())))->toBe(AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);

    expect(Role::query()->whereKey($cashier->getKey())->value('name'))->toBe('caja');
    expect(roleAbilityNames($cashier))->toBe(['crew.crews.update']);
});

test('rol FIJO: no se edita, sincroniza ni borra por el CRUD, ni con `*`', function () {
    bootGeneratedCrew($this);
    $superAdmin = crewRole('super-admin', ['*'], fixed: true);
    $owner = crewUser('dueño', ['*']);

    $sync = crewFormRequest('SyncRoleAbilitiesRequest', ['abilities' => ['crew.crews.update']], $owner);
    expect(crewDenial(fn () => crewRoleController()->syncAbilities((string) $superAdmin->getKey(), $sync)))->toBe(AccessGrantGuard::ERR_FIXED_ROLE);
    expect(crewDenial(fn () => crewRoleController()->update(crewPut(['name' => 'nadie', 'guard' => 'crew'], $owner), (string) $superAdmin->getKey())))->toBe(AccessGrantGuard::ERR_FIXED_ROLE);
    expect(crewDenial(fn () => crewRoleController()->destroy(crewPut([], $owner, 'DELETE'), (string) $superAdmin->getKey())))->toBe(AccessGrantGuard::ERR_FIXED_ROLE);

    expect(Role::query()->whereKey($superAdmin->getKey())->value('name'))->toBe('super-admin');
    expect(roleAbilityNames($superAdmin))->toBe(['*']);
});

test('rol: el CRUD no escribe `is_fixed`, ni en el alta ni en la edición', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $waiter = crewRole('mesero', []);

    expect(crewRoleController()->store(crewPut(['name' => 'intocable', 'guard' => 'crew', 'is_fixed' => 1], $owner, 'POST'))->getStatusCode())->toBe(201);
    expect(crewRoleController()->update(crewPut(['name' => 'mesero', 'guard' => 'crew', 'is_fixed' => 1], $owner), (string) $waiter->getKey())->getStatusCode())->toBe(200);

    expect(Role::query()->where('name', 'intocable')->firstOrFail()->is_fixed->value)->toBe(0);
    expect($waiter->fresh()->is_fixed->value)->toBe(0);
});

/**
 * 🔴 El CRUD de ABILITIES es la tercera puerta al mismo acceso.
 *
 * Roles y grants apuntan a la ability por id: renombrarla es cambiarles el
 * permiso a todos los que la tienen, y borrarla es sacárselo. Medido en RETO
 * (#27): con `admin.abilities.update`, un admin renombró una ability suya a
 * `admin.*` y tuvo el scope entero. Y cualquiera borraba o renombraba `*`.
 */
function crewAbilityController(): object
{
    return new ('App\\Modules\\Crew\\Http\\Controllers\\AbilityController');
}

function crewAbility(string $name): Ability
{
    return Ability::query()->where('name', $name)->firstOrFail();
}

test('ability: renombrar una propia a un nombre que no tiene es 403 — el caso medido', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.abilities.update', 'crew.crews.update']);
    $ability = crewAbility('crew.crews.update');

    // `crew.reports.export` es lo que pide la ruta de exportar: quien renombra
    // una suya a ese nombre, y todos los que la tienen, pasan a poder.
    expect(crewDenial(fn () => crewAbilityController()->update(crewPut(['name' => 'crew.reports.export'], $manager), (string) $ability->getKey())))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    // Y `crew.*` (el caso de RETO) ni siquiera es un nombre válido: 422.
    expect(fn () => crewAbilityController()->update(crewPut(['name' => 'crew.*'], $manager), (string) $ability->getKey()))->toThrow(ValidationException::class);
    expect($ability->fresh()->name)->toBe('crew.crews.update');
});

test('ability: renombrar pide el nombre viejo Y el nuevo; mandar el mismo nombre no es renombrar', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.abilities.update', 'crew.crews.update']);
    $owner = crewUser('dueño', ['*']);
    $foreign = crewAbility('crew.branches.viewAll');

    // Viejo no tenido → 403, aunque el nuevo sea suyo.
    expect(crewDenial(fn () => crewAbilityController()->update(crewPut(['name' => 'crew.crews.otra'], $manager), (string) $foreign->getKey())))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect($foreign->fresh()->name)->toBe('crew.branches.viewAll');

    // El nuevo cubierto (`crew.*`) no alcanza: el viejo, sin scope conocido,
    // se exige igual. Si no, quien tiene `crew.*` le saca `kitchen.send` a todos.
    $scoped = crewUser('jefe', ['crew.*']);
    $noScope = Ability::query()->create(['name' => 'kitchen.send']);
    expect(crewDenial(fn () => crewAbilityController()->update(crewPut(['name' => 'crew.kitchen.send'], $scoped), (string) $noScope->getKey())))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect($noScope->fresh()->name)->toBe('kitchen.send');

    // El mismo nombre con otra descripción: no es renombrar, pasa.
    expect(crewAbilityController()->update(crewPut(['name' => 'crew.branches.viewAll', 'description' => 'Ver sucursales.'], $manager), (string) $foreign->getKey())->getStatusCode())->toBe(200);

    // Contraprueba: quien tiene `*` renombra.
    expect(crewAbilityController()->update(crewPut(['name' => 'crew.branches.list'], $owner), (string) $foreign->getKey())->getStatusCode())->toBe(200);
    expect($foreign->fresh()->name)->toBe('crew.branches.list');
});

test('ability: crear y borrar piden tenerla; con `*` pasa (contraprueba)', function () {
    bootGeneratedCrew($this);
    $manager = crewUser('encargado', ['crew.abilities.create', 'crew.abilities.delete', 'crew.crews.update']);
    $owner = crewUser('dueño', ['*']);

    expect(crewDenial(fn () => crewAbilityController()->store(crewPut(['name' => 'crew.branches.export'], $manager, 'POST'))))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(crewDenial(fn () => crewAbilityController()->store(crewPut(['name' => 'crew.reports'], $manager, 'POST'))))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(Ability::query()->where('name', 'like', 'crew.reports%')->orWhere('name', 'crew.branches.export')->count())->toBe(0);

    $foreign = crewAbility('crew.branches.viewAll');
    expect(crewDenial(fn () => crewAbilityController()->destroy(crewPut([], $manager, 'DELETE'), (string) $foreign->getKey())))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(Ability::query()->whereKey($foreign->getKey())->exists())->toBeTrue();

    expect(crewAbilityController()->store(crewPut(['name' => 'crew.reports'], $owner, 'POST'))->getStatusCode())->toBe(201);
    expect(crewAbilityController()->destroy(crewPut([], $owner, 'DELETE'), (string) $foreign->getKey())->getStatusCode())->toBe(200);
});

test('ability FIJA: `*` (aunque no tenga la marca) y las `is_fixed` no se editan ni se borran, ni con `*`', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $wildcard = crewAbility('*');
    expect($wildcard->is_fixed)->not->toBe(FixedStatus::Fixed); // `giveAbilityTo('*')` la crea sin la marca.
    $fixed = Ability::query()->create(['name' => 'crew.system.run', 'is_fixed' => 1]);

    foreach ([$wildcard, $fixed] as $ability) {
        expect(crewDenial(fn () => crewAbilityController()->update(crewPut(['name' => 'crew.x.y'], $owner), (string) $ability->getKey())))->toBe(AccessGrantGuard::ERR_FIXED_ABILITY);
        expect(crewDenial(fn () => crewAbilityController()->update(crewPut(['description' => 'otra'], $owner), (string) $ability->getKey())))->toBe(AccessGrantGuard::ERR_FIXED_ABILITY);
        expect(crewDenial(fn () => crewAbilityController()->destroy(crewPut([], $owner, 'DELETE'), (string) $ability->getKey())))->toBe(AccessGrantGuard::ERR_FIXED_ABILITY);
        expect($ability->fresh()->name)->toBe($ability->name);
    }
});

test('ability: `is_fixed` e `is_baseline` no llegan del body; el renombre sigue pidiendo el prefijo del scope', function () {
    bootGeneratedCrew($this);
    $owner = crewUser('dueño', ['*']);
    $ability = crewAbility('crew.branches.viewAll');

    foreach (['is_fixed' => 1, 'is_baseline' => 1] as $field => $value) {
        expect(fn () => crewAbilityController()->store(crewPut(['name' => 'crew.x.y', $field => $value], $owner, 'POST')))->toThrow(ValidationException::class);
        expect(fn () => crewAbilityController()->update(crewPut([$field => $value], $owner), (string) $ability->getKey()))->toThrow(ValidationException::class);
    }
    expect(fn () => crewAbilityController()->update(crewPut(['name' => 'kitchen.send'], $owner), (string) $ability->getKey()))->toThrow(ValidationException::class);

    $fresh = $ability->fresh();
    expect([$fresh->name, $fresh->is_fixed, $fresh->is_baseline])->toBe(['crew.branches.viewAll', FixedStatus::Editable, false]);
    expect(Ability::query()->where('name', 'crew.x.y')->exists())->toBeFalse();
});

test('ability: un nombre de OTRO scope conocido queda afuera; uno sin scope conocido se exige', function () {
    bootGeneratedCrew($this);
    // Dos scopes de verdad: guards cuyo modelo extiende `AuthUser`.
    $model = 'App\\Modules\\Crew\\Models\\Crew';
    config([
        'auth.guards.crew' => ['driver' => 'session', 'provider' => 'crews'],
        'auth.guards.boss' => ['driver' => 'session', 'provider' => 'bosses'],
        'auth.providers.crews' => ['driver' => 'eloquent', 'model' => $model],
        'auth.providers.bosses' => ['driver' => 'eloquent', 'model' => $model],
    ]);
    $manager = crewUser('encargado', ['crew.abilities.delete']);
    $otherScope = Ability::query()->create(['name' => 'boss.things.view']);
    $noScope = Ability::query()->create(['name' => 'kitchen.send']);

    expect(crewDenial(fn () => crewAbilityController()->destroy(crewPut([], $manager, 'DELETE'), (string) $noScope->getKey())))->toBe(AccessGrantGuard::ERR_ACCESS_NOT_HELD);
    expect(crewAbilityController()->destroy(crewPut([], $manager, 'DELETE'), (string) $otherScope->getKey())->getStatusCode())->toBe(200);
});
