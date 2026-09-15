<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Hallazgo #30 del piloto NetPizza: los throttles que emite el scaffolder
 * COMPARTEN un solo contador.
 *
 * `ThrottleRequests` arma la clave como `$prefix.sha1(dominio|ip)`: la RUTA no
 * entra. Los stubs emitían `throttle:{limite}` sin tercer parámetro, así que
 * login, forgot, reset y los dos del código por PIN —de TODOS los scopes—
 * escribían en el mismo contador por IP. Medido en NetPizza: tres pedidos de
 * código de recuperación dejaron el login (5 por minuto) con DOS intentos y
 * bloqueado DIEZ minutos, porque el TTL lo fija la primera ruta que escribe.
 * Detrás de un NAT —una pizzería con un módem— un mesero pidiendo su código
 * deja sin login a todo el local.
 *
 * El arreglo es el que el piloto ya aplicó a mano: tercer parámetro
 * `{scope}-{endpoint}`.
 *
 * Dos niveles de prueba:
 *  1. Sobre el ARCHIVO generado: cada throttle lleva prefijo, con el scope, y
 *     no se repite.
 *  2. De COMPORTAMIENTO: los middlewares de las rutas generadas se montan en
 *     rutas de prueba de una app real y se queman — el contador de un endpoint
 *     no le come intentos al otro. El controller generado no es autoloadable
 *     en el tempdir, por eso se reusan los middlewares y no la ruta entera; lo
 *     que se mide es exactamente el string que emitió el scaffolder.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/** @return array<int, string> todos los `throttle:...` del archivo, ya evaluados */
function routeThrottles(string $routesFile): array
{
    Route::getRoutes(); // asegura el router del facade
    require $routesFile;

    $throttles = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        foreach ($route->middleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'throttle:')) {
                $throttles[$route->uri()] = $mw;
            }
        }
    }

    return $throttles;
}

test('cada throttle generado lleva prefijo {scope}-{endpoint}, y ninguno se repite', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => 'operadores',
        '--verify-email' => true,
    ]);
    expect($exit)->toBe(0, $output);

    $throttles = routeThrottles($base.'/app/Modules/Operador/Http/Routes/api.php');

    // Los siete endpoints con límite: login, forgot, reset, los dos del PIN
    // público, los dos del PIN autenticado y el reenvío de verificación.
    expect($throttles)->toHaveKeys([
        'api/operador/auth/login',
        'api/operador/auth/password/forgot',
        'api/operador/auth/password/reset',
        'api/operador/auth/password/reset/code/request',
        'api/operador/auth/password/reset/code/confirm',
        'api/operador/auth/password/code/request',
        'api/operador/auth/password/code/confirm',
        'api/operador/auth/email/resend',
    ]);

    foreach ($throttles as $uri => $mw) {
        // `throttle:max,minutos,prefijo` — tres partes, y el prefijo nombra al scope.
        $parts = explode(',', substr($mw, strlen('throttle:')));
        expect($parts)->toHaveCount(3, "{$uri} emite {$mw} sin prefijo");
        expect($parts[2])->toStartWith('operador-', "{$uri}: el prefijo no nombra al scope");
    }

    $prefixes = array_map(fn ($mw) => explode(',', $mw)[2], $throttles);
    expect(array_unique($prefixes))->toHaveCount(count($prefixes));
});

test('scope consumer: login/forgot/reset también llevan prefijo propio', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Mesero',
        '--kind' => 'consumer',
        '--managed-by' => 'Admin',
    ]);
    expect($exit)->toBe(0, $output);

    $throttles = routeThrottles($base.'/app/Modules/Mesero/Http/Routes/api.php');

    expect($throttles)->toHaveKeys(['api/mesero/auth/login', 'api/mesero/auth/password/forgot', 'api/mesero/auth/password/reset']);
    foreach ($throttles as $uri => $mw) {
        expect((string) (explode(',', $mw)[2] ?? ''))->toStartWith('mesero-', "{$uri} emite {$mw}");
    }
});

test('COMPORTAMIENTO: quemar el pedido de código NO le come intentos al login', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operador']);
    expect($exit)->toBe(0, $output);

    $throttles = routeThrottles($base.'/app/Modules/Operador/Http/Routes/api.php');
    $code = $throttles['api/operador/auth/password/reset/code/request']; // 3 cada 10 min
    $login = $throttles['api/operador/auth/login'];                       // 5 por minuto

    // Alias que en un consumer registra `bootstrap/app.php` (defaults de
    // `withMiddleware()`); el kernel pelado de `BootsHttpApp` no lo trae.
    app('router')->aliasMiddleware('throttle', ThrottleRequests::class);
    Route::post('probe/codigo', fn () => 'ok')->middleware($code);
    Route::post('probe/login', fn () => 'ok')->middleware($login);

    $post = fn (string $uri) => $this->httpKernel->handle(Request::create($uri, 'POST', server: ['HTTP_ACCEPT' => 'application/json']))->getStatusCode();

    // El limitador del código está vivo: 3 pasan, el 4º es 429. Sin esta
    // contraprueba, "el login sigue en 200" también saldría con el throttle roto.
    expect([$post('/probe/codigo'), $post('/probe/codigo'), $post('/probe/codigo'), $post('/probe/codigo')])
        ->toBe([200, 200, 200, 429]);

    // Con el contador compartido el login arrancaba con 3 consumidos: el 3º
    // intento daba 429. Con prefijo propio tiene sus 5 enteros.
    expect([$post('/probe/login'), $post('/probe/login'), $post('/probe/login'), $post('/probe/login'), $post('/probe/login')])
        ->toBe([200, 200, 200, 200, 200]);
    expect($post('/probe/login'))->toBe(429);
});
