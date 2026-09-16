<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Lo que emite `mk:make:auth-user --two-factor`, medido sobre los archivos
 * GENERADOS (no sobre los stubs: los placeholders se expanden distinto según la
 * combinación de flags).
 *
 * Las cuatro cosas que tienen que viajar juntas, porque si falta una el scope
 * queda roto de una forma que no da error:
 *
 *  1. Las COLUMNAS en la migración. El paquete no puede migrarlas: no conoce el
 *     nombre de la tabla del scope.
 *  2. Los CASTS en el modelo del scope. 🔴 El modelo generado override `$casts`
 *     ENTERO, así que lo que esté sólo en `AuthUser` no se aplica: sin el cast
 *     `encrypted` el secreto queda en claro en la base.
 *  3. Las RUTAS, las seis. Emitir el portón del login sin los endpoints deja al
 *     usuario con un desafío y ningún lugar donde contestarlo — la cuenta queda
 *     inaccesible.
 *  4. El OVERRIDE `twoFactorPolicy()`. Sin él la política es `off` y el login
 *     ignora el 2FA de todo el mundo, en silencio.
 *
 * Y el caso que más importa: SIN el flag, nada de esto aparece.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

const TWO_FACTOR_COLUMN_NAMES = [
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
    'two_factor_last_step',
];

/** @return array<string, string> uri => método, de las rutas del archivo generado */
function generatedRouteUris(string $routesFile): array
{
    Route::getRoutes();
    require $routesFile;

    $uris = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $uris[$route->uri()] = implode('|', $route->methods());
    }

    return $uris;
}

test('--two-factor=required emite columnas, casts, rutas y el override de la política', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
        '--two-factor' => 'required',
    ]);
    expect($exit)->toBe(0, $output);

    $migration = (string) file_get_contents(
        (string) current(glob($base.'/app/Modules/Operator/Database/Migrations/*.php') ?: []),
    );
    $model = (string) file_get_contents($base.'/app/Modules/Operator/Models/Operator.php');
    $controller = (string) file_get_contents($base.'/app/Modules/Operator/Http/Controllers/AuthController.php');
    if (getenv('MK_DUMP_GEN')) {
        @mkdir('/tmp/gen2fa', 0777, true);
        file_put_contents('/tmp/gen2fa/AuthController.php', $controller);
        file_put_contents('/tmp/gen2fa/api.php', (string) file_get_contents($base.'/app/Modules/Operator/Http/Routes/api.php'));
        file_put_contents('/tmp/gen2fa/migration.php', $migration);
        file_put_contents('/tmp/gen2fa/Operator.php', $model);
    }

    // 1. Columnas.
    expect($migration)->toContain("\$table->text('two_factor_secret')->nullable();");
    expect($migration)->toContain("\$table->text('two_factor_recovery_codes')->nullable();");
    expect($migration)->toContain("\$table->timestamp('two_factor_confirmed_at')->nullable();");
    expect($migration)->toContain("\$table->unsignedBigInteger('two_factor_last_step')->nullable();");

    // 2. Casts, en el modelo del scope.
    expect($model)->toContain("'two_factor_secret' => 'encrypted'");
    expect($model)->toContain("'two_factor_recovery_codes' => 'array'");
    expect($model)->toContain("'two_factor_confirmed_at' => 'datetime'");
    expect($model)->toContain("'two_factor_last_step' => 'integer'");

    // 3. La política, con su import.
    expect($controller)->toContain('use Mk\\Director\\Auth\\Enums\\TwoFactorPolicy;');
    expect($controller)->toContain('protected function twoFactorPolicy(): TwoFactorPolicy');
    expect($controller)->toContain('return TwoFactorPolicy::Required;');

    // 4. Las seis rutas.
    $routes = generatedRouteUris($base.'/app/Modules/Operator/Http/Routes/api.php');
    expect(array_keys($routes))->toContain(
        'api/operator/auth/two-factor/challenge',
        'api/operator/auth/two-factor/setup/confirm',
        'api/operator/auth/two-factor/enable',
        'api/operator/auth/two-factor/confirm',
        'api/operator/auth/two-factor/recovery-codes',
        'api/operator/auth/two-factor/disable',
    );
});

test('--two-factor sin valor es `optional`', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
        '--two-factor' => null,
    ]);
    expect($exit)->toBe(0, $output);

    expect((string) file_get_contents($base.'/app/Modules/Operator/Http/Controllers/AuthController.php'))
        ->toContain('return TwoFactorPolicy::Optional;');
});

test('un valor inventado FALLA, no cae en off en silencio', function () {
    [$exit, $output] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
        '--two-factor' => 'obligatorio',
    ]);

    expect($exit)->toBe(1);
    expect($output)->toContain('--two-factor');
});

test('🔴 BC: SIN el flag, el scope generado no tiene una sola huella del segundo factor', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator']);
    expect($exit)->toBe(0, $output);

    // Se mide sobre TODO lo generado, no sobre tres archivos elegidos: un
    // placeholder que se expandiera en un stub del pack CRUD también contaría.
    $everything = $this->allGeneratedContent($base.'/app/Modules');

    foreach (TWO_FACTOR_COLUMN_NAMES as $column) {
        expect($everything)->not->toContain($column);
    }
    expect($everything)->not->toContain('TwoFactorPolicy');
    expect($everything)->not->toContain('two-factor');
    expect($everything)->not->toContain('twoFactor');

    // Contraprueba de que el barrido leyó algo: el scope se generó de verdad.
    expect($everything)->toContain('class AuthController extends BaseAuthController');
});

test('las seis rutas nuevas llevan throttle con prefijo `{scope}-2fa-*` propio y único', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operator',
        '--no-crud' => true,
        '--two-factor' => 'required',
    ]);
    expect($exit)->toBe(0, $output);

    Route::getRoutes();
    require $base.'/app/Modules/Operator/Http/Routes/api.php';

    $prefixes = [];
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (! str_contains($route->uri(), 'two-factor')) {
            continue;
        }

        $throttles = array_values(array_filter(
            array_filter($route->middleware(), 'is_string'),
            fn (string $mw) => str_starts_with($mw, 'throttle:'),
        ));

        expect($throttles)->toHaveCount(1, $route->uri().' sin throttle');
        $parts = explode(',', substr($throttles[0], strlen('throttle:')));
        expect($parts)->toHaveCount(3, $route->uri().' emite '.$throttles[0].' sin prefijo');
        expect($parts[2])->toStartWith('operator-2fa-', $route->uri());
        $prefixes[] = $parts[2];
    }

    expect($prefixes)->toHaveCount(6);
    expect(array_unique($prefixes))->toHaveCount(6);
});

test('un scope consumer también recibe los endpoints: el portón sin ellos lo dejaría afuera', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Mesero',
        '--kind' => 'consumer',
        '--managed-by' => 'Admin',
        '--two-factor' => 'required',
    ]);
    expect($exit)->toBe(0, $output);

    $routes = generatedRouteUris($base.'/app/Modules/Mesero/Http/Routes/api.php');

    expect(array_keys($routes))->toContain(
        'api/mesero/auth/two-factor/challenge',
        'api/mesero/auth/two-factor/setup/confirm',
        'api/mesero/auth/two-factor/enable',
        'api/mesero/auth/two-factor/disable',
    );
});

test('las claves de rate limit que citan las rutas generadas existen en la config del paquete', function () {
    // Una clave mal escrita no rompe: `config()` devuelve el default del
    // segundo argumento y el límite queda distinto del que dice la doc.
    //
    // El archivo de config llama `app_path()`, así que necesita una app booteada.
    $this->bootHttpApp(stdClass::class);
    $config = require dirname(__DIR__, 2).'/config/mk_director.php';

    expect($config['auth']['rate_limits'])->toHaveKeys([
        'two_factor_challenge',
        'two_factor_setup',
        'two_factor_manage',
    ]);
    expect($config['auth']['two_factor'])->toHaveKeys([
        'issuer',
        'challenge_ttl_seconds',
        'setup_ttl_seconds',
        'max_attempts',
    ]);
});
