<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Mk\Director\Auth\Access\AccessGrantDeniedException;
use Mk\Director\Auth\Access\AccessGrantGuard;
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
    ] as $file) {
        if (! class_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)
            && ! interface_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)
            && ! enum_exists('App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file), false)) {
            require "{$module}/{$file}";
        }
    }

    foreach (['crew.crews.update', 'crew.crews.delete', 'crew.branches.viewAll', '*'] as $name) {
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
