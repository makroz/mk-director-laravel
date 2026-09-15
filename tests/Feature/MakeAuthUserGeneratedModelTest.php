<?php

declare(strict_types=1);

use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Lo que sale en el modelo, la migración y las rutas generadas, leído del disco.
 *
 * Encontrado generando `Operator --no-crud` en NetPizza:
 *
 *     protected $casts = [
 *         'status' => 'integer',
 *         'status' => \App\Modules\Operator\Enums\OperatorStatus::class,
 *         'password' => 'hashed',
 *     ];
 *
 * PHP se queda con la ÚLTIMA clave sin avisar, así que el enum ganaba — pero el
 * `'integer'` es código muerto que se lee como cierto: el que lo ve asume que
 * `status` es un int. Venía de dos lados: `status` es profile field de base
 * (tipo `int`, cast `integer`) y además `{{statusCastEntry}}` pinea el enum.
 *
 * De paso, dos sangrías rotas del mismo templating (la columna `phone` de la
 * migración y la ruta `password/forgot`): cosmético, pero es lo primero que el
 * consumer lee de su módulo nuevo.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/** Las líneas `'clave' => valor` del array `$casts` del modelo generado. */
function generatedCastLines(string $modelFile): array
{
    $model = (string) file_get_contents($modelFile);
    preg_match('/protected \$casts = \[(.*?)\];/s', $model, $m);

    return array_values(array_filter(array_map('trim', explode("\n", $m[1] ?? ''))));
}

test('$casts: `status` aparece UNA vez y es el enum del scope', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);

    $casts = generatedCastLines($base.'/app/Modules/Operator/Models/Operator.php');
    $statusLines = array_values(array_filter($casts, fn ($l) => str_starts_with($l, "'status'")));

    expect($statusLines)->toBe(["'status' => \\App\\Modules\\Operator\\Enums\\OperatorStatus::class,"]);
});

test('$casts con --no-status: sin ninguna clave `status`', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--no-status' => true]);
    expect($exit)->toBe(0, $output);

    $casts = generatedCastLines($base.'/app/Modules/Operator/Models/Operator.php');

    // Contraprueba de que el parseo leyó el array: `password` siempre está.
    expect($casts)->toContain("'password' => 'hashed',");
    expect(implode("\n", $casts))->not->toContain("'status'");
});

test('sangría: la columna `phone` de la migración y la ruta `password/forgot` alinean con sus vecinas', function (bool $withRegister) {
    $args = ['scope' => 'Operator', '--no-crud' => true];
    if ($withRegister) {
        $args['--with-register'] = true;
    }
    [$exit, $output, $base] = $this->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    $module = $base.'/app/Modules/Operator';
    $migration = (string) file_get_contents((glob($module.'/Database/Migrations/*_create_operators_table.php') ?: [''])[0]);
    $routes = (string) file_get_contents($module.'/Http/Routes/api.php');

    expect($migration)->toMatch("/\n {12}\\\$table->string\\('phone'\\)->nullable\\(\\);\n/");
    expect($migration)->toMatch("/\n {12}\\\$table->string\\('name'\\);\n/"); // la vecina, para comparar
    expect($routes)->toMatch("/\n {4}Route::post\\('password\\/forgot'/");
})->with(['sin register' => [false], 'con register' => [true]]);
