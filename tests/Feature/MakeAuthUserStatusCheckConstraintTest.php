<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La columna `status` generada no tenía CHECK.
 *
 * Encontrado por los gates de NetPizza sobre el `Operator` recién generado:
 * `unsignedTinyInteger('status')` para un enum int-backed de 4 casos. Nada en la
 * base impide `status = 99`, y al leer la fila el cast al enum tira
 * `ValueError`: la fila queda INCARGABLE y el login de ese usuario da 500. El
 * dato malo no se ve roto al escribirlo, se ve roto después, lejos.
 *
 * El CHECK va con nombre `{tabla}_status_check`, con los valores de los MISMOS
 * casos que pinea el enum generado, y sólo en los drivers que lo soportan por
 * `ALTER TABLE` (pgsql, mysql, mariadb). En sqlite no: no admite agregar un
 * constraint a una tabla existente.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

function generatedMigrationFor(string $base, string $scope, string $table): string
{
    $files = glob("{$base}/app/Modules/{$scope}/Database/Migrations/*_create_{$table}_table.php") ?: [];
    expect($files)->toHaveCount(1);

    return $files[0];
}

test('la migración generada agrega {tabla}_status_check con los valores del enum, sólo en pgsql/mysql/mariadb', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);

    $migration = file_get_contents(generatedMigrationFor($base, 'Operator', 'operators'));

    $values = implode(', ', array_map(fn (ScopeStatus $case) => $case->value, ScopeStatus::cases()));
    expect($migration)->toContain("ALTER TABLE operators ADD CONSTRAINT operators_status_check CHECK (status IN ({$values}))");
    expect($migration)->toContain("in_array(\\Illuminate\\Support\\Facades\\DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)");

    // El CHECK va DESPUÉS del Schema::create de la tabla del scope.
    expect(strpos($migration, 'operators_status_check'))->toBeGreaterThan(strpos($migration, "Schema::create('operators'"));

    // Y compila.
    exec('php -l '.escapeshellarg(generatedMigrationFor($base, 'Operator', 'operators')).' 2>&1', $lint, $code);
    expect($code)->toBe(0, implode("\n", $lint));
});

test('los valores del CHECK son los casos del enum que genera el scaffolder (una sola fuente)', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true]);
    expect($exit)->toBe(0, $output);

    preg_match_all('/case \w+ = (\d+);/', file_get_contents($base.'/app/Modules/Operator/Enums/OperatorStatus.php'), $m);
    $enumValues = implode(', ', $m[1]);

    expect($enumValues)->not->toBe('');
    expect(file_get_contents(generatedMigrationFor($base, 'Operator', 'operators')))->toContain("CHECK (status IN ({$enumValues}))");
});

test('--no-status: sin columna, sin CHECK', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--no-status' => true]);
    expect($exit)->toBe(0, $output);

    $migration = file_get_contents(generatedMigrationFor($base, 'Operator', 'operators'));
    expect($migration)->not->toContain('status_check');
    expect($migration)->not->toContain("'status'");
});

test('sqlite: la migración generada corre up() y down() (el CHECK se saltea)', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Operator', '--no-crud' => true, '--plural' => 'operadores']);
    expect($exit)->toBe(0, $output);

    $migration = require generatedMigrationFor($base, 'Operator', 'operadores');
    $migration->up();
    expect(Schema::hasColumn('operadores', 'status'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('operadores'))->toBeFalse();
});
