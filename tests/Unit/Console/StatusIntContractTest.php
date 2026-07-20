<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Tests\TestCase;

uses(TestCase::class);

/**
 * Guard-rail de COHERENCIA del contrato `status` int-backed (revert 2026-07-19).
 *
 * POR QUÉ EXISTE
 * --------------
 * El revert de R-PKG-047 D4 se hizo en dos pasadas y la primera quedó
 * incompleta: se cambió la columna de la migración a `unsignedTinyInteger`
 * pero `buildStatusCrudReplacements()` siguió emitiendo `?string $status`,
 * casts `(string)` y la regla de validación `'string'`. El scaffolder habría
 * generado un scope inconsistente CONSIGO MISMO — columna int, DTO string,
 * y un `Rule::enum` que nunca matchea. Ningún test lo agarró.
 *
 * El problema de fondo es que el contrato de `status` vive repartido en
 * CINCO lugares (enum canónico, stub del thin wrapper, columna, DTO, regla)
 * y nada obligaba a que coincidieran. Este archivo es esa obligación.
 *
 * SOBRE EL ESTILO
 * ---------------
 * Estos SÍ son source-parsing tests, y acá es lo correcto: lo que se está
 * verificando es el TEXTO que el scaffolder emite, que es literalmente el
 * artefacto. Distinto del caso de una constraint UNIQUE o una transacción,
 * donde grepear el source no prueba nada y hace falta una base de datos
 * (ver tests/Concerns/UsesDatabase.php).
 */
function statusContractPackageRoot(): string
{
    return dirname(__DIR__, 3);
}

test('el enum canónico ScopeStatus es int-backed desde 1', function () {
    expect(ScopeStatus::Active->value)->toBe(1);
    expect(ScopeStatus::Inactive->value)->toBe(2);
    expect(ScopeStatus::Blocked->value)->toBe(3);
    expect(ScopeStatus::Pending->value)->toBe(4);

    // El 0 nunca debe ser un value válido: es indistinguible de null/false
    // en casts flojos, en empty() y en un query string.
    expect(ScopeStatus::values())->not->toContain(0);
});

test('el thin wrapper del scaffolder declara el MISMO contrato que el canónico', function () {
    $stub = file_get_contents(statusContractPackageRoot().'/src/Stubs/auth-user/enum-status.stub');

    expect($stub)->toContain('enum {{ModuleName}}Status: int');

    foreach (ScopeStatus::cases() as $case) {
        expect($stub)->toContain("case {$case->name} = {$case->value};");
    }

    // Si el stub volviera a string, este test cae. Se apunta a la DECLARACIÓN
    // del enum y no a `: string` suelto, que también matchearía el return type
    // legítimo de `label(): string`.
    expect($stub)->not->toContain('enum {{ModuleName}}Status: string');
});

test('la columna que emite el scaffolder es entera y su default es el del enum', function () {
    $src = file_get_contents(statusContractPackageRoot().'/src/Console/Commands/MakeAuthUserCommand.php');
    $default = ScopeStatus::default()->value;

    // OJO con el escapado: el archivo NO contiene `$table->...` sino
    // `\$table->...`, porque es una string de PHP que GENERA código PHP.
    // Con comillas dobles, `\$` se resuelve a `$` y la aserción compara
    // contra algo que no existe en el archivo — y en los `not->toContain`
    // eso da un verde falso.
    expect($src)->toContain('\$table->unsignedTinyInteger(\'status\')->default('.$default.')->index();');

    // La forma vieja no debe volver: el ENUM string además no lo soporta
    // mysql < 5.7, y su literal incluía 'suspended', un estado que el enum
    // PHP ya no define.
    expect($src)->not->toContain('\$table->enum(\'status\'');
});

test('EL QUE FALTABA: el DTO y la regla de validación son int, no string', function () {
    // Éste es exactamente el agujero que dejó la primera pasada del revert.
    $src = file_get_contents(statusContractPackageRoot().'/src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain('public ?int \$status = null,');

    // Se ancla a la ASIGNACIÓN (`$ruleStore = `) y no al fragmento suelto de
    // la regla: hay comentarios en el archivo que documentan la forma vieja
    // como parte de la historia de F10-B18, y un test que salta por texto en
    // un comentario es un test que la gente aprende a ignorar.
    expect($src)->toContain('$ruleStore = "            \'status\' => [\'sometimes\', \'nullable\', \'integer\'');
    expect($src)->not->toContain('$ruleStore = "            \'status\' => [\'sometimes\', \'nullable\', \'string\'');

    // Las formas string NO deben existir en ningún lado del scaffolder.
    expect($src)->not->toContain('public ?string \$status = null,');
    expect($src)->not->toContain('(string) \$request->input(\'status\')');
    expect($src)->not->toContain('(string) \$data[\'status\']');
});

test('el campo de perfil status se tipa int para el resto del codegen', function () {
    $src = file_get_contents(statusContractPackageRoot().'/src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain("\$defaults['status'] = ['type' => 'int', 'unique' => false];");
    expect($src)->not->toContain("\$defaults['status'] = ['type' => 'string', 'unique' => false];");
});

test('el Resource sigue exponiendo status_label junto al value numérico', function () {
    // Con status numérico, el label deja de ser cosmético: es lo ÚNICO que
    // le permite a la UI mostrar algo legible sin conocer los números.
    $src = file_get_contents(statusContractPackageRoot().'/src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain('\'status\' => \$this->status?->value,');
    expect($src)->toContain('\'status_label\' => \$this->status?->label(),');
});
