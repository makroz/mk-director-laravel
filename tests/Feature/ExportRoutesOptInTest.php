<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Hallazgo 48 de NetPizza: el motor de reportes montaba 7 rutas en
 * `v3/reports` en TODO consumer —`v3` es la versión de API de Condaty, que
 * ni siquiera usa el paquete—. Ahora es opt-in y su prefijo no trae versión.
 *
 * Se mide con la app real (`BootsHttpApp`): el provider bootea contra el
 * `config/mk_director.php` del paquete, así que lo que se afirma es el
 * DEFAULT que recibe un consumer que no publicó la config, no un string del
 * source.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    $this->tearDownHttpApp();
});

/** @return array<int, string> las URIs de las rutas `mk.reports.*` registradas */
function urisDelMotorDeReportes($app): array
{
    $routes = $app['router']->getRoutes();
    $routes->refreshNameLookups();

    return collect($routes->getRoutes())
        ->filter(fn (Route $r) => str_starts_with((string) $r->getName(), 'mk.reports.'))
        ->map(fn (Route $r) => $r->uri())
        ->values()
        ->all();
}

test('por defecto el motor de reportes NO registra ninguna ruta', function () {
    $app = $this->bootHttpApp(stdClass::class);

    expect(urisDelMotorDeReportes($app))->toBe([]);
});

test('activado con la config del paquete, monta las rutas en `reports` sin `v3`', function () {
    // El bloque `export` tal cual lo trae el paquete (el archivo de config usa
    // `app_path()`, así que se lee de una app booteada y no con `require`).
    $export = $this->bootHttpApp(stdClass::class)['config']->get('mk_director.export');
    $this->tearDownHttpApp();
    $export['register_routes'] = true;

    $app = $this->bootHttpApp(stdClass::class, ['export' => $export]);
    $uris = urisDelMotorDeReportes($app);

    expect($uris)->toHaveCount(7)
        ->and($uris)->toContain('reports', 'reports/{type}/export', 'reports/{report}/download');

    foreach ($uris as $uri) {
        expect($uri)->toStartWith('reports')->not->toContain('v3');
    }
});

test('una config publicada vieja, sin `route_prefix`, también cae en `reports`', function () {
    $app = $this->bootHttpApp(stdClass::class, ['export' => ['register_routes' => true]]);

    expect(urisDelMotorDeReportes($app))->toContain('reports', 'reports/{report}/status');
});
