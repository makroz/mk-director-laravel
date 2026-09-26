<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Mk\Director\Console\Commands\MakeModuleCommand;
use Mk\Director\Tests\Concerns\BootsGeneratedRbacModule;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:module --with-rbac --middleware=...`: el middleware lo pasa el consumer.
 *
 * 🔴 Medido: las 18 rutas del pack salían SIN middleware (`gatherMiddleware()`
 * vacío). Asignar roles y editar usuarios quedaba público, y sin actor la
 * guarda de escalada no aplica. Con `--with-rbac` la opción es obligatoria:
 * omitirla aborta ANTES de escribir nada (falla cerrada, como
 * `--kind=consumer` sin `--managed-by` en `mk:make:auth-user`).
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, BootsGeneratedRbacModule::class);

afterEach(function () {
    $this->tearDownHttpApp();
    foreach ($this->tempDirs ?? [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

/**
 * @param  array<string, mixed>  $args
 * @return array{0: int, 1: string, 2: string} [exit code, output, dir del módulo]
 */
function runMakeModule(object $test, array $args): array
{
    $base = sys_get_temp_dir().'/mk-module-mw-'.uniqid();
    $test->tempDirs[] = $base;
    mkdir($base.'/app', 0755, true);

    $app = $test->bootHttpApp(stdClass::class);
    $app->setBasePath($base);

    $command = new MakeModuleCommand;
    $command->setLaravel($app);
    $output = new BufferedOutput;
    $exit = $command->run(new ArrayInput($args), $output);
    $test->tearDownHttpApp();

    return [$exit, $output->fetch(), $base.'/app/Modules/'.$args['name']];
}

test('las rutas generadas van envueltas en el middleware de la opción', function () {
    [$exit, , $dir] = runMakeModule($this, ['name' => 'Patrol', '--with-rbac' => true, '--middleware' => 'api, auth:patrol,mk.auth:admin']);

    expect($exit)->toBe(0)
        ->and(file_get_contents("{$dir}/Routes/api.php"))->toContain("Route::middleware(['api', 'auth:patrol', 'mk.auth:admin'])->group(function () {");
});

test('las 18 rutas del módulo llegan al router con el middleware', function () {
    $this->bootRbacModule();

    $routes = array_filter(
        $this->httpApp['router']->getRoutes()->getRoutes(),
        fn ($route) => str_starts_with($route->uri(), 'api/squad'),
    );

    expect($routes)->toHaveCount(18);
    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('api');
    }
});

test('sin --middleware, --with-rbac aborta sin escribir nada', function (array $extra) {
    [$exit, $output, $dir] = runMakeModule($this, ['name' => 'Patrol', '--with-rbac' => true] + $extra);

    expect($exit)->toBe(1)
        ->and($output)->toContain('--with-rbac requiere --middleware')
        ->and(is_dir($dir))->toBeFalse();
})->with([
    'omitida' => [[]],
    'vacía' => [['--middleware' => ' , ']],
]);

test('un middleware inválido aborta sin escribir nada', function (string $middleware) {
    [$exit, $output, $dir] = runMakeModule($this, ['name' => 'Patrol', '--with-rbac' => true, '--middleware' => $middleware]);

    expect($exit)->toBe(1)
        ->and($output)->toContain('no es un middleware válido')
        ->and(is_dir($dir))->toBeFalse();
})->with([
    'parámetro con coma' => ['api,throttle:60,1'],
    'comilla' => ["api,auth:x'];"],
]);

test('--middleware sin --with-rbac aborta: el pack estándar no lo emite', function () {
    [$exit, $output, $dir] = runMakeModule($this, ['name' => 'Patrol', '--middleware' => 'api']);

    expect($exit)->toBe(1)
        ->and($output)->toContain('sólo aplica con --with-rbac')
        ->and(is_dir($dir))->toBeFalse();
});
