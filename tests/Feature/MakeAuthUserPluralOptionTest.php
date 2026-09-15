<?php

declare(strict_types=1);

use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk:make:auth-user --plural=` — el plural del scope lo elige el consumer.
 *
 * `Str::plural()` es el inflector INGLÉS: `operador` → `operadors`. El piloto
 * NetPizza necesita un tercer scope con tabla `operadores`, y no había forma de
 * pedirlo. Peor: el plural se derivaba en TRES lugares distintos del comando
 * (`handle()`, el provider de rutas managed y el endpoint de permisos), así que
 * un arreglo que tocara sólo `$scopePlural` dejaba a los otros dos escribiendo
 * `operadors` en silencio.
 *
 * Corre `handle()` entero (ver `RunsAuthUserScaffolder`): un test que recibe el
 * plural ya calculado pasa en verde con este bug, porque el bug ES dónde se
 * calcula.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

test('--plural=operadores: tabla, $table, provider de auth.php, migración y rutas dicen operadores — y NINGÚN archivo dice operadors', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => 'operadores',
        '--with-permissions-endpoint' => true,
    ]);

    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operador';

    // $table del modelo.
    expect(file_get_contents($module.'/Models/Operador.php'))->toContain("protected \$table = 'operadores';");

    // Migración: nombre de archivo y Schema::create.
    $migrations = glob($module.'/Database/Migrations/*.php') ?: [];
    $creates = array_values(array_filter($migrations, fn ($f) => str_ends_with($f, '_create_operadores_table.php')));
    expect($creates)->toHaveCount(1);
    expect(file_get_contents($creates[0]))->toContain("Schema::create('operadores'");

    // config/auth.php: el guard apunta al provider `operadores`, que existe.
    $auth = file_get_contents($base.'/config/auth.php');
    expect($auth)->toContain("'provider' => 'operadores'");
    expect($auth)->toContain("'operadores' => [");

    // Rutas CRUD del scope.
    expect(file_get_contents($module.'/Http/Routes/api.php'))->toContain("Route::prefix('api/operadores')");

    // 🔴 La aserción que importa: ni un solo `operadors` en todo lo generado,
    // incluido auth.php y lo que imprimió el comando.
    expect($this->allGeneratedContent($base))->not->toContain('operadors');
    expect($output)->not->toContain('operadors');
});

/**
 * Un manager generado con `--plural=`: su tabla NO es la que adivina el
 * inflector inglés. El alias hace que `App\Modules\Jefe\Models\Jefe` exista,
 * como existe en un consumer que scaffoldeó el manager primero.
 */
final class JefeConPluralPropio extends AuthUser
{
    protected $table = 'jefes_de_turno';
}

test('--managed-by: la FK apunta a la tabla REAL del manager (su $table), no a Str::plural()', function () {
    if (! class_exists('App\\Modules\\Jefe\\Models\\Jefe')) {
        class_alias(JefeConPluralPropio::class, 'App\\Modules\\Jefe\\Models\\Jefe');
    }

    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => 'operadores',
        '--managed-by' => 'Jefe',
    ]);

    expect($exit)->toBe(0, $output);

    $migrations = glob($base.'/app/Modules/Operador/Database/Migrations/*_create_operadores_table.php') ?: [];
    expect($migrations)->toHaveCount(1);
    expect(file_get_contents($migrations[0]))->toContain("constrained('jefes_de_turno')");

    // Las rutas managed usan el plural del scope administrado.
    expect(file_get_contents($base.'/app/Modules/Operador/Http/Routes/managed.php'))->toContain("Route::prefix('api/jefe/operadores')");
    expect($this->allGeneratedContent($base))->not->toContain('operadors');
});

test('sin --plural el default no cambia (BC): Admin → admins', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Admin',
    ]);

    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Admin';
    expect(file_get_contents($module.'/Models/Admin.php'))->toContain("protected \$table = 'admins';");
    expect(glob($module.'/Database/Migrations/*_create_admins_table.php') ?: [])->toHaveCount(1);
    expect(file_get_contents($base.'/config/auth.php'))->toContain("'provider' => 'admins'");
    expect(file_get_contents($module.'/Http/Routes/api.php'))->toContain("Route::prefix('api/admins')");
});

test('--plural inválido falla con FAILURE y no genera nada', function (string $plural) {
    [$exit, $output, $base] = $this->runScaffolderInTempDir([
        'scope' => 'Operador',
        '--plural' => $plural,
    ]);

    expect($exit)->toBe(1);
    expect($output)->toContain('--plural');
    expect(is_dir($base.'/app/Modules/Operador'))->toBeFalse();
})->with([
    'vacío explícito' => ['   '],
    'mayúsculas' => ['Operadores'],
    'guión' => ['operado-res'],
    'empieza con número' => ['1operadores'],
]);
