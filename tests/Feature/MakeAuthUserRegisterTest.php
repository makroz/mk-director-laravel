<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Mk\Director\Auth\Controllers\BaseAuthController;
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
 *
 * Y el tercero, encontrado generando `Operator --no-crud` en NetPizza: el
 * register salía SIEMPRE. La condición era `profile fields || verify-email`, y
 * los profile fields de base nunca están vacíos, así que todo scope nuevo —un
 * backoffice, los operadores de plataforma— nacía con un alta PÚBLICA, sin auth
 * y sin throttle: cualquiera en internet se creaba una cuenta. Ahora es opt-in
 * con `--with-register`, lleva throttle con prefijo propio, y combinado con
 * `--multi-tenant` el comando falla en vez de generar un alta sin tenant.
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
        '--with-register' => true,
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

    // Alias que en un consumer registra `bootstrap/app.php`; el kernel pelado
    // de `BootsHttpApp` no lo trae, y el register ahora lleva throttle.
    app('router')->aliasMiddleware('throttle', ThrottleRequests::class);

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

test('DEFAULT: sin --with-register NO hay alta pública — ni ruta ni método, tampoco con --no-crud', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operator';
    expect(file_get_contents($module.'/Http/Routes/api.php'))->not->toContain("'register'");
    expect(file_get_contents($module.'/Http/Controllers/AuthController.php'))->not->toContain('function register(');

    // Contraprueba: con el flag el mismo scope SÍ lo trae. Sin esto, la aserción
    // negativa quedaría verde con el register roto por cualquier otra causa.
    $this->tearDownHttpApp();
    [$exit2, $output2, $base2] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--with-register' => true]);
    expect($exit2)->toBe(0, $output2);
    expect(file_get_contents($base2.'/app/Modules/Operator/Http/Controllers/AuthController.php'))->toContain('function register(');
});

test('--with-register: la ruta lleva throttle con prefijo {scope}-register y la clave existe en la config', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
        '--with-register' => true,
    ]);
    expect($exit)->toBe(0, $output);

    require $base.'/app/Modules/Operator/Http/Routes/api.php';
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->uri() === 'api/operator/auth/register');

    expect($route)->not->toBeNull();
    $throttle = collect($route->middleware())->first(fn ($mw) => is_string($mw) && str_starts_with($mw, 'throttle:'));
    expect($throttle)->toBe('throttle:3,1,operator-register');

    $config = require dirname(__DIR__, 2).'/config/mk_director.php';
    expect($config['auth']['rate_limits'])->toHaveKey('register');
});

test('--with-register + --multi-tenant: falla con FAILURE y no genera nada (sería un alta sin tenant)', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--with-register' => true,
        '--multi-tenant' => true,
    ]);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('--with-register');
    expect($output)->toContain('--multi-tenant');
    expect(is_dir($base.'/app/Modules/Operator'))->toBeFalse();
});

test('--verify-email sin --with-register: verify + resend coherentes, sin register, y el PHP generado compila', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--verify-email' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operator';
    $routes = file_get_contents($module.'/Http/Routes/api.php');
    $controller = file_get_contents($module.'/Http/Controllers/AuthController.php');

    expect($routes)->toContain("'email/verify/{id}/{hash}'");
    expect($routes)->toContain("'email/resend'");
    expect($routes)->not->toContain("'register'");
    // verify/resend los hereda de BaseAuthController: el thin wrapper no los redeclara.
    expect($controller)->toContain('extends BaseAuthController');
    expect(method_exists(BaseAuthController::class, 'resendVerification'))->toBeTrue();
    expect($controller)->not->toContain('function register(');

    foreach ([$module.'/Http/Routes/api.php', $module.'/Http/Controllers/AuthController.php'] as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $lint, $code);
        expect($code)->toBe(0, implode("\n", $lint));
    }
});

test('--verify-email + --with-register: el controller y las rutas generadas compilan, y register despacha la verificación', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--verify-email' => true,
        '--with-register' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operator';
    expect(file_get_contents($module.'/Http/Controllers/AuthController.php'))->toContain('sendEmailVerificationNotification()');

    foreach ([$module.'/Http/Routes/api.php', $module.'/Http/Controllers/AuthController.php', $module.'/Models/Operator.php'] as $file) {
        exec('php -l '.escapeshellarg($file).' 2>&1', $lint, $code);
        expect($code)->toBe(0, implode("\n", $lint));
    }
});

test('api_contract.md: la nota de register dice lo que se generó (nada / público / gateado)', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);
    $contract = file_get_contents($base.'/app/Modules/Operator/Docs/api_contract.md');
    expect($contract)->not->toContain('auth/register');
    expect($contract)->not->toContain('{{registerContractNote}}');

    $this->tearDownHttpApp();
    [$exit2, $output2, $base2] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--with-register' => true]);
    expect($exit2)->toBe(0, $output2);
    expect(file_get_contents($base2.'/app/Modules/Operator/Docs/api_contract.md'))->toContain('es PÚBLICO');
});
