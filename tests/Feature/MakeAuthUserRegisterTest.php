<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Hallazgo #49 del piloto NetPizza: el `register()` que emite el scaffolder
 * daba 500 SIEMPRE.
 *
 *     POST api/admin/auth/register -> 500
 *     Class "App\Modules\Admin\Http\Controllers\Admin" not found
 *
 * La plantilla llamaba `{Scope}::create($data)` sin importar el modelo, así que
 * PHP lo resolvía en el namespace del CONTROLLER. Ningún test lo veía: los que
 * había parsean el heredoc de `buildRegisterMethod()` y afirman que dice
 * `::create(` dentro de un `DB::transaction(` — y lo decía.
 *
 * 🔴 Por eso este test EJECUTA el endpoint generado: scaffoldea, corre la
 * migración generada, carga el modelo y el controller generados, monta la ruta
 * generada y le pega por el Kernel real. Es la única forma de ver un 500 de
 * resolución de clases.
 *
 * Y el segundo problema del hallazgo: en un consumer multi-tenant el register
 * crea un usuario SIN tenant (no hay contexto que lo ancle) y con el login
 * único global permite ocupar el identificador de otro cliente. Con
 * `--multi-tenant` no se emite.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

test('el register generado CREA el usuario (201), no revienta resolviendo el modelo', function () {
    // Nombre único: el modelo y el controller generados se cargan en ESTE
    // proceso, y una clase no se puede declarar dos veces.
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Registrante',
        '--no-crud' => true,
        '--no-rbac' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Registrante';
    expect(file_get_contents($module.'/Http/Routes/api.php'))->toContain("Route::post('register'");

    // Las tablas RBAC del paquete (el modelo asigna el rol base al crearse, como
    // en un consumer) y la migración generada del scope, tal cual.
    $migrations = array_merge(
        glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [],
        glob($module.'/Database/Migrations/*.php') ?: [],
    );
    foreach ($migrations as $migracion) {
        (require $migracion)->up();
    }

    // `$request->validate()` es una macro que en un consumer registra
    // `FoundationServiceProvider`; el kernel pelado de `BootsHttpApp` no la trae.
    Request::macro('validate', function (array $rules, ...$params) {
        return validator()->validate($this->all(), $rules, ...$params);
    });

    require $module.'/Models/Registrante.php';
    require $module.'/Http/Controllers/AuthController.php';
    require $module.'/Http/Routes/api.php';

    $response = $this->httpKernel->handle(Request::create('/api/registrante/auth/register', 'POST', [
        'name' => 'Ana',
        'email' => 'ana@example.com',
        'password' => 'secreto123',
    ], server: ['HTTP_ACCEPT' => 'application/json']));

    expect($response->getStatusCode())->toBe(201, (string) $response->getContent());
    expect(DB::table('registrantes')->where('email', 'ana@example.com')->value('auth_scope'))->toBe('registrante');
});

test('--multi-tenant: NO se emite register (ni método ni ruta) y el comando avisa por qué', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--multi-tenant' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operador';
    expect(file_get_contents($module.'/Http/Routes/api.php'))->not->toContain("'register'");
    expect(file_get_contents($module.'/Http/Controllers/AuthController.php'))->not->toContain('function register(');
    expect($output)->toContain('register');
    expect($output)->toContain('--multi-tenant');

    // Contraprueba: sin --multi-tenant el mismo scope SÍ lo trae. Si no, la
    // aserción negativa de arriba quedaría verde con el register roto por otra causa.
    $this->tearDownHttpApp();
    [$exit2, $output2, $base2] = $this->runScaffolderInTempDir(['scope' => 'Operador']);
    expect($exit2)->toBe(0, $output2);
    expect(file_get_contents($base2.'/app/Modules/Operador/Http/Controllers/AuthController.php'))->toContain('function register(');
});
