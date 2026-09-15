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

/**
 * Auditoría de TODA ruta pública (sin `mk.auth`) que emite el scaffolder.
 *
 * Encontrado por el gate de NetPizza «toda ruta pública tiene rate limit»
 * sobre el `Operator` recién generado: `POST auth/refresh` salía sin throttle.
 * Y con `--no-rbac` también login/forgot/reset (el throttle colgaba del flag de
 * RBAC), y el `email/verify` firmado nunca lo tuvo.
 *
 * 🔴 La lista de rutas NO está escrita acá: sale del archivo generado. Una ruta
 * pública nueva sin throttle pone esto en rojo sin que nadie tenga que acordarse
 * de agregarla.
 */
test('toda ruta pública generada lleva throttle con prefijo {scope}- único', function (array $args, string $scope) {
    [$exit, $output, $base] = $this->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    // 🔴 Los controllers generados no son autoloadables en el tempdir, y con un
    // `[Clase::class, 'metodo']` que no resuelve Laravel (13.20) registra la
    // ruta pero SE COME el `Route::middleware([...])` del registrar: las rutas
    // CRUD salían sin `mk.auth` y este test las contaba como públicas. Un
    // stand-in con `__callStatic` hace que la acción resuelva como en un
    // consumer, sin cargar el controller real.
    $controllerNamespace = 'App\\Modules\\'.$args['scope'].'\\Http\\Controllers\\';
    $standInLoader = function (string $class) use ($controllerNamespace): void {
        if (str_starts_with($class, $controllerNamespace)) {
            $short = substr($class, strlen($controllerNamespace));
            eval('namespace '.rtrim($controllerNamespace, '\\').'; class '.$short.' { public static function __callStatic($m, $a) {} }');
        }
    };
    spl_autoload_register($standInLoader);

    // Sólo las rutas que agrega el archivo generado (la app ya trae otras, ej.
    // `sanctum/csrf-cookie`, que no son del scaffolder).
    $routesBefore = app('router')->getRoutes()->getRoutes();
    try {
        require $base.'/app/Modules/'.$args['scope'].'/Http/Routes/api.php';
    } finally {
        spl_autoload_unregister($standInLoader);
    }
    $generatedRoutes = array_filter(
        app('router')->getRoutes()->getRoutes(),
        fn ($route) => ! in_array($route, $routesBefore, true),
    );

    $publicRoutes = [];
    foreach ($generatedRoutes as $route) {
        $middleware = array_filter($route->middleware(), 'is_string');
        $isProtected = (bool) array_filter($middleware, fn ($mw) => str_starts_with($mw, 'mk.auth:'));
        if (! $isProtected) {
            $publicRoutes[$route->uri()] = array_values(array_filter($middleware, fn ($mw) => str_starts_with($mw, 'throttle:')));
        }
    }

    // Contrapruebas: el archivo se leyó (login es pública) y la clasificación
    // ve el `mk.auth` (me es protegida). Sin la segunda, un parseo que no leyera
    // middleware contaría todo como público — o nada.
    expect($publicRoutes)->toHaveKey("api/{$scope}/auth/login");
    expect($publicRoutes)->not->toHaveKey("api/{$scope}/auth/me");

    $prefixes = [];
    foreach ($publicRoutes as $uri => $throttles) {
        expect($throttles)->toHaveCount(1, "{$uri} es pública y no tiene throttle");
        $parts = explode(',', substr($throttles[0], strlen('throttle:')));
        expect($parts)->toHaveCount(3, "{$uri} emite {$throttles[0]} sin prefijo");
        expect($parts[2])->toStartWith("{$scope}-", "{$uri}: el prefijo no nombra al scope");
        $prefixes[] = $parts[2];
    }
    expect(array_unique($prefixes))->toHaveCount(count($prefixes));
})->with([
    'manager sin RBAC, con register y verify' => [['scope' => 'Operator', '--no-crud' => true, '--no-rbac' => true, '--with-register' => true, '--verify-email' => true], 'operator'],
    'manager default (CRUD + RBAC)' => [['scope' => 'Operator', '--verify-email' => true], 'operator'],
    'consumer sin RBAC' => [['scope' => 'Mesero', '--kind' => 'consumer', '--managed-by' => 'Admin', '--no-rbac' => true, '--verify-email' => true], 'mesero'],
]);

test('refresh: throttle leído de rate_limits.refresh (default 20,1) y la clave existe en la config', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);

    $throttles = routeThrottles($base.'/app/Modules/Operator/Http/Routes/api.php');
    expect($throttles['api/operator/auth/refresh'] ?? null)->toBe('throttle:20,1,operator-refresh');

    $config = require dirname(__DIR__, 2).'/config/mk_director.php';
    expect($config['auth']['rate_limits'])->toHaveKey('refresh');
});
