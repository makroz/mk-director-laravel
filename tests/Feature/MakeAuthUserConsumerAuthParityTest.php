<?php

declare(strict_types=1);

use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Lo que `mk:make:auth-user` deja montado en las rutas de auth, medido sobre el
 * `Http/Routes/api.php` GENERADO (no sobre el stub).
 *
 * 1. Un scope `--kind=consumer` tiene las mismas cuatro rutas del PIN por email
 *    que un manager, con el mismo middleware. El stub consumer nació antes que
 *    el PIN (2026-07-13 vs 2026-07-15) y nunca se actualizó: el consumer —el
 *    scope de la app móvil, para el que se hizo el feature— salía sin ellas.
 * 2. El hint de RBAC que imprime el comando dice lo que el código hace: las
 *    rutas de auth sólo piden `mk.auth:{scope}`, ninguna `mk.ability`. Antes
 *    mandaba a configurar `mk_director.auth.abilities`, que ninguna ruta lee.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

const CONSUMER_PARITY_PIN_ROUTES = [
    'POST api/{scope}/auth/password/reset/code/request',
    'POST api/{scope}/auth/password/reset/code/confirm',
    'POST api/{scope}/auth/password/code/request',
    'POST api/{scope}/auth/password/code/confirm',
];

/**
 * `"METHOD uri" => middleware[]` de las rutas que agrega el archivo generado,
 * con el scope reemplazado por `{scope}` para poder comparar dos scopes.
 *
 * El stand-in del controller es el mismo truco de MakeAuthUserThrottlePrefixTest:
 * con una acción que no resuelve, Laravel registra la ruta pero se come el
 * middleware del registrar.
 *
 * @return array<string, array<int, string>>
 */
function scaffoldedRouteMiddleware(object $test, array $args): array
{
    [$exit, $output, $base] = $test->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    $scope = strtolower($args['scope']);
    $namespace = 'App\\Modules\\'.$args['scope'].'\\Http\\Controllers\\';
    $standIn = function (string $class) use ($namespace): void {
        if (str_starts_with($class, $namespace)) {
            eval('namespace '.rtrim($namespace, '\\').'; class '.substr($class, strlen($namespace)).' { public static function __callStatic($m, $a) {} }');
        }
    };

    $before = app('router')->getRoutes()->getRoutes();
    spl_autoload_register($standIn);
    try {
        require $base.'/app/Modules/'.$args['scope'].'/Http/Routes/api.php';
    } finally {
        spl_autoload_unregister($standIn);
    }

    $routes = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (in_array($route, $before, true)) {
            continue;
        }
        $middleware = array_map(fn ($mw) => str_replace($scope, '{scope}', $mw), array_values(array_filter($route->middleware(), 'is_string')));
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routes[$method.' '.str_replace($scope, '{scope}', $route->uri())] = $middleware;
        }
    }

    return $routes;
}

test('consumer: las cuatro rutas del PIN existen con el MISMO middleware que en el manager', function () {
    $manager = scaffoldedRouteMiddleware($this, ['scope' => 'Gerente']);
    $this->tearDownHttpApp();
    $consumer = scaffoldedRouteMiddleware($this, ['scope' => 'Socio', '--kind' => 'consumer', '--managed-by' => 'Admin']);

    // Contraprueba: el manager las tiene, así que la comparación no es vacía.
    expect($manager)->toHaveKeys(CONSUMER_PARITY_PIN_ROUTES);
    expect($consumer)->toHaveKeys(CONSUMER_PARITY_PIN_ROUTES);

    foreach (CONSUMER_PARITY_PIN_ROUTES as $route) {
        expect($consumer[$route])->toBe($manager[$route], "{$route}: middleware distinto al del manager");
    }

    // Las de reset son públicas (sólo throttle); las de cambio, autenticadas.
    expect($consumer['POST api/{scope}/auth/password/reset/code/request'])
        ->toBe(['throttle:3,10,{scope}-reset-code-req']);
    expect($consumer['POST api/{scope}/auth/password/code/confirm'])
        ->toBe(['mk.auth:{scope}', 'throttle:5,10,{scope}-pwd-code-confirm']);

    // Y el consumer sigue sin CRUD propio.
    expect(array_filter(array_keys($consumer), fn ($r) => ! str_contains($r, '/auth/')))->toBe([]);
});

test('el hint de RBAC no manda a configurar abilities: las rutas de auth sólo piden mk.auth', function () {
    [$exit, $output] = $this->runScaffolderInTempDir(['scope' => 'Gerente']);
    expect($exit)->toBe(0, $output);

    expect($output)->toContain('RBAC integration habilitada');
    expect($output)->not->toContain("'abilities' =>");
    expect($output)->not->toContain('auth.gerente.me');
    expect($output)->toContain('Las rutas de auth sólo exigen mk.auth:gerente; ninguna pide ability.');

    // Lo que el hint afirma, medido sobre las rutas generadas.
    $this->tearDownHttpApp();
    $routes = scaffoldedRouteMiddleware($this, ['scope' => 'Gerente']);
    expect($routes['GET api/{scope}/auth/me'])->toBe(['mk.auth:{scope}']);
    expect($routes['POST api/{scope}/auth/logout'])->toBe(['mk.auth:{scope}']);
    foreach ($routes as $route => $middleware) {
        if (str_contains($route, '/auth/')) {
            expect(array_filter($middleware, fn ($mw) => str_starts_with($mw, 'mk.ability:')))->toBe([], "{$route} pide ability");
        }
    }
});
