<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Routing\Router;
use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\TestCase;

/**
 * F1.4 (LAR-04 HIGH) — Gate /mk/openapi.json and /mk/docs by config (default off).
 *
 * Pre-fix, MkServiceProvider::registerOpenApiRoutes() UNCONDITIONALLY
 * registered the OpenAPI spec + Swagger UI routes under `/mk/openapi.json`
 * and `/mk/docs` for every consumer. Two risks:
 *
 *   1. Information disclosure — un Operador anónimo podía descargar el
 *      schema completo de la API (rutas, params, modelos) sin auth. Útil
 *      para fingerprinting antes de un ataque dirigido.
 *   2. Accidental deploy in prod — sandbox/dev tienen esto OK; prod no.
 *      Si el consumer olvidaba commentar el bloque, las rutas salían
 *      públicas en producción.
 *
 * Fix: gatear por `config('mk_director.openapi.enabled', false)` (default
 * OFF — opt-in), y aceptar `config('mk_director.openapi.middleware', [])`
 * como array de middleware que se aplican al Route::group (típicamente
 * `['mk.auth:admin']` o `['auth.basic']` para forzar auth en prod).
 */
uses(TestCase::class);

test('MkServiceProvider::registerOpenApiRoutes gates by mk_director.openapi.enabled (default false)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../src/MkServiceProvider.php');

    // El método debe verificar config('mk_director.openapi.enabled') y
    // early-return si es false. Sin esto, las rutas salen siempre públicas.
    expect($src)->toContain("'mk_director.openapi.enabled'");

    // Default DEBE ser false (defense-in-depth: opt-in).
    expect($src)->toMatch('/config\([\'"]mk_director\.openapi\.enabled[\'"],\s*false\s*\)/');

    // Y debe early-return si no está habilitado, después del cast (bool).
    expect($src)->toContain('(bool) config(\'mk_director.openapi.enabled\'');
    expect($src)->toMatch('/!\s*\(bool\)\s*config\(\'mk_director\.openapi\.enabled\'/');
});

test('MkServiceProvider::registerOpenApiRoutes applies optional middleware from config', function () {
    $src = (string) file_get_contents(__DIR__.'/../../src/MkServiceProvider.php');

    // El consumer pinea `mk_director.openapi.middleware` como array
    // (ej: `['mk.auth:admin']`). El provider debe pasarlo al Route::group.
    expect($src)->toContain("'mk_director.openapi.middleware'");

    // Default array vacío (sin middleware extra).
    expect($src)->toMatch('/config\([\'"]mk_director\.openapi\.middleware[\'"],\s*\[\s*\]\)/');

    // El Route::group debe incluir tanto 'middleware' => \$middleware
    // (pasado desde config) como 'prefix' => 'mk'. Validamos por separado
    // porque el orden en el array PHP no es contractual.
    expect($src)->toContain("'middleware' => \$middleware");
    expect($src)->toContain("'prefix' => 'mk'");
    // Las dos keys deben estar dentro del MISMO Route::group() — capturamos
    // el bloque desde Route::group([ hasta el ]) con un non-greedy match
    // que termina en ],\s*function (cierre del array + signature del callback).
    $pattern = '/Route::group\(\s*\[\s*(?:\'[^\']+\'\s*=>\s*[^,\]]+,?\s*\n\s*){1,}\],\s*function/s';
    expect($src)->toMatch($pattern);
    // Y dentro de ese bloque, las 2 keys deben aparecer (en cualquier orden).
    preg_match('/Route::group\(\s*\[(.+?)\],\s*function/s', $src, $m);
    expect($m)->toHaveCount(2, 'Route::group block must be captured');
    expect($m[1])->toContain("'middleware'");
    expect($m[1])->toContain("'prefix'");
});

test('OpenAPI route registration is a no-op when enabled=false (early-return without touching Route facade)', function () {
    // Verifica el early-return del gate: cuando enabled=false, el método
    // retorna ANTES de tocar el facade Route. Lo verificamos instrumentando
    // el container para que 'config' retorne false, llamando al protected
    // method vía reflection, y comprobando que Route facade NO fue tocado
    // (es decir, no se intentó resolver 'router' desde el container).
    $container = new Container;
    Container::setInstance($container);
    $container->instance('config', new ConfigRepository([
        'mk_director' => [
            'openapi' => [
                'enabled' => false,
                'middleware' => [],
            ],
        ],
    ]));

    $provider = new MkServiceProvider($container);
    $method = new \ReflectionMethod($provider, 'registerOpenApiRoutes');
    $method->setAccessible(true);

    // Si enabled=false, la invocación NO debe tocar Route facade
    // (no requiere resolución de 'router' en el container — early-return).
    // Si enabled=true, intentaría resolver 'router' y dispararía
    // BindingResolutionException en este container minimal.
    $method->invoke($provider);

    expect(true)->toBeTrue('early-return succeeded without touching Route facade');
});

test('mk_director.openapi.enabled config key is documented in mk_director.php', function () {
    // Defense-in-depth: el config key debe existir en mk_director.php (el
    // único archivo pineable) con un comentario explicando el default
    // opt-in. Esto evita que consumers tengan que adivinar el nombre del
    // flag o que olviden pinearlo.
    $src = (string) file_get_contents(__DIR__.'/../../config/mk_director.php');

    expect($src)->toContain("'openapi'");
    expect($src)->toContain("'enabled'");
    expect($src)->toContain('MK_OPENAPI_ENABLED');
    // Default OFF.
    expect($src)->toMatch('/MK_OPENAPI_ENABLED.*false/s');

    expect($src)->toContain("'middleware'");
    expect($src)->toContain('MK_OPENAPI_MIDDLEWARE');
});
