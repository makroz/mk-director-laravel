<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkPivot;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 17: LA REGLA `in:` DE LOS FORMREQUEST GENERADOS ACEPTA EL NOMBRE DE UN
 * ROL DE OTRO GUARD.
 *
 * La tabla `roles` es compartida y los nombres se repiten entre scopes **por
 * diseño**: el scaffolder siembra `super-admin`, `admin`, `editor`, `viewer` y
 * `base` en CADA guard. `AssignRolesRequest` y `AssignAccessRequest` validaban con
 * `in:` sobre `Role::query()->pluck('name')` —sin filtrar por guard—, así que el
 * nombre de un rol del scope ajeno era «válido».
 *
 * ── 🔴 Y HOY EL SÍNTOMA ES PEOR QUE EL ORIGINAL ─────────────────────────────
 *
 * El repositorio generado ya filtra por guard (`admin-repository.stub`, cerrado en
 * `4625d6f`). Con el filtro de un lado y no del otro, el nombre ajeno pasa la
 * validación, el repositorio no encuentra nada, y `sync([])` **borra todos los
 * roles del usuario** contestando 200.
 *
 * Un 200 que borra en silencio es peor que la fuga original: la fuga dejaba un
 * `viewer, viewer` visible en la pantalla, y esto deja al usuario sin ningún rol
 * —o sea sin ninguna ability— sin que nada lo diga.
 *
 * ── 🔴 EL NOMBRE DEL ROL DEL TEST TIENE QUE EXISTIR EN OTRO GUARD ───────────
 *
 * Con un nombre inventado el test pasa en verde con el bug vivo: el `in:` lo
 * rechaza igual, porque no está en NINGÚN guard. Lo único que discrimina es un
 * nombre que existe —en el guard equivocado—.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    MorphPivot::flushSchemaCache();
    MkPivot::clearUserTypeCache();
    $this->cleanScaffolderTempDirs();
});

function bootGeneratedGuardScope(object $test): void
{
    [$exit, $output, $base] = $test->runScaffolderInTempDir(['scope' => 'Crew']);
    expect($exit)->toBe(0, $output);

    $module = "{$base}/app/Modules/Crew";
    foreach (array_merge(
        glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [],
        glob("{$module}/Database/Migrations/*.php") ?: [],
    ) as $migration) {
        (require $migration)->up();
    }

    foreach ([
        'Enums/CrewStatus.php', 'Models/Crew.php',
        'Repositories/Contracts/CrewRepositoryInterface.php', 'Repositories/CrewRepository.php',
        'Services/CrewService.php',
        'Http/Requests/AssignAccessRequest.php', 'Http/Requests/AssignRolesRequest.php',
        'Http/Requests/AssignDirectAbilitiesRequest.php',
        'Http/Resources/CrewResource.php', 'Http/Controllers/CrewController.php',
    ] as $file) {
        $fqcn = 'App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file);
        if (! class_exists($fqcn, false) && ! interface_exists($fqcn, false) && ! enum_exists($fqcn, false)) {
            require "{$module}/{$file}";
        }
    }

    Ability::query()->firstOrCreate(['name' => 'crew.crews.update']);

    // El mismo nombre en los DOS guards, que es exactamente lo que siembra el
    // scaffolder por cada scope.
    Role::query()->create(['name' => 'viewer', 'guard' => 'mesero']);
}

/**
 * Valida el FormRequest generado como lo hace Laravel.
 *
 * 🔴 CON `Accept: application/json`, Y NO ES DECORADO. Sin ese header, un fallo de
 * validación hace que Laravel arme un REDIRECT, y el harness del paquete no tiene
 * URL generator: la excepción que sale es
 * `Error: Call to a member function getUrlGenerator() on null`, no la
 * `ValidationException`. O sea que un `toThrow(ValidationException::class)` daría
 * rojo con la validación funcionando perfecto — el test mediría el harness.
 */
function validarRequestDeCrew(string $class, array $data): void
{
    $request = ('App\\Modules\\Crew\\Http\\Requests\\'.$class)::create(
        '/', 'POST', $data, server: ['HTTP_ACCEPT' => 'application/json'],
    );
    $request->setContainer(app());
    app()->instance('request', $request);
    $request->setUserResolver(fn () => null);
    $request->setRedirector(app('redirect'));
    $request->validateResolved();
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: AssignRolesRequest rechaza un rol que existe en OTRO guard', function () {
    bootGeneratedGuardScope($this);

    expect(fn () => validarRequestDeCrew('AssignRolesRequest', ['roles' => ['viewer']]))
        ->toThrow(ValidationException::class);
});

test('🔴 EL BUG, la otra puerta: AssignAccessRequest también lo rechaza', function () {
    bootGeneratedGuardScope($this);

    expect(fn () => validarRequestDeCrew('AssignAccessRequest', ['roles' => ['viewer']]))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| LOS CONTROLES. Sin ellos «arreglar» esto rechazando todo también pone los
| dos rojos en verde, y el endpoint queda inutilizable.
|--------------------------------------------------------------------------
*/

test('CONTROL: el MISMO nombre en el guard PROPIO pasa', function () {
    bootGeneratedGuardScope($this);
    Role::query()->create(['name' => 'viewer', 'guard' => 'crew']);

    validarRequestDeCrew('AssignRolesRequest', ['roles' => ['viewer']]);
    validarRequestDeCrew('AssignAccessRequest', ['roles' => ['viewer']]);

    // Sin una aserción, un test que sólo «no tira» no deja constancia de nada.
    expect(Role::query()->where('guard', 'crew')->where('name', 'viewer')->exists())->toBeTrue();
});

test('CONTROL: un nombre que no existe en ningún guard sigue dando 422', function () {
    bootGeneratedGuardScope($this);

    expect(fn () => validarRequestDeCrew('AssignRolesRequest', ['roles' => ['no-existe']]))
        ->toThrow(ValidationException::class);
});

/**
 * El motivo por el que esto es peor que la fuga original, medido: con el nombre
 * ajeno aceptado, el repositorio —que sí filtra por guard— no encuentra nada y
 * `sync([])` deja al usuario SIN ningún rol, contestando 200.
 */
test('CONTROL del daño: si la validación no frenara, el sync dejaría al usuario sin roles', function () {
    bootGeneratedGuardScope($this);
    Role::query()->create(['name' => 'viewer', 'guard' => 'crew']);

    $class = 'App\\Modules\\Crew\\Models\\Crew';
    $user = $class::create(['name' => 'mozo', 'email' => 'mozo@crew.test', 'password' => 'secreto123']);

    $repo = new ('App\\Modules\\Crew\\Repositories\\CrewRepository');
    $repo->syncRoles($user, ['viewer']);
    expect($user->fresh(['roles'])->roles)->toHaveCount(1);

    // El nombre del guard ajeno, pasando por alto la validación: 0 roles.
    $repo->syncRoles($user->fresh(), ['viewer-de-otro-guard-inexistente']);
    expect($user->fresh(['roles'])->roles)->toHaveCount(0);

    unset($repo, $user);
    Request::createFromGlobals();
});
