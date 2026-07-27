<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Models\Role;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `roles.description` — la historia de esta columna se dio vuelta:
 *
 * 1. FEEDBACK10 F10-B07: la tabla `roles` NO tenía columna `description`
 *    (solo `id/name/guard/is_fixed/timestamps`) pero `Role::$fillable` y
 *    `RoleResource` la exponían → cualquier create/update con `description`
 *    en el payload tiraba `SQLSTATE[42703] column "description" does not
 *    exist`. El fix de entonces fue SACARLA, y estos tests se escribieron
 *    como guards de esa ausencia.
 *
 * 2. Después se decidió lo contrario: los roles SÍ deben describir qué
 *    hacen. Se agregó la migration ADITIVA
 *    `2026_07_14_000001_add_description_to_roles_table.php` + fillable + el
 *    campo en el form.
 *
 * Los guards de (1) quedaron obsoletos y hacían fallar la suite: afirmaban
 * la ausencia de algo que ahora existe a propósito. Se reescriben para
 * pinear la realidad de (2).
 *
 * Ojo con la lección: lo que F10-B07 protegía de verdad NO era "description
 * no debe existir" — era **"fillable y la tabla no deben divergir"**. Esa
 * invariante sobrevive al cambio de decisión, y es la del último test.
 *
 * `Ability` tiene su propia `description` (columna real en su migration),
 * independiente de esto.
 */
uses(MkLaravelTestCase::class);

function packageRootRoleDesc(): string
{
    return dirname(__DIR__, 3);
}

test('roles.description: Role::$fillable la incluye', function () {
    expect((new Role)->getFillable())->toContain('description');
});

test('roles.description: la migration aditiva agrega la columna', function () {
    $src = (string) file_get_contents(
        packageRootRoleDesc().'/src/Auth/Database/Migrations/2026_07_14_000001_add_description_to_roles_table.php',
    );

    expect($src)->toMatch("/\\\$table->(string|text)\('description'\)/");
});

/**
 * El shape ya no vive en el stub: se mudó a
 * `Mk\Director\Http\Resources\MkRoleResource` y el stub quedó como subclase
 * fina (una copia por módulo scaffolded era una copia por módulo donde
 * arreglar el mismo bug). El guard sigue siendo el mismo —"`description` viaja
 * en el payload"— pero apuntando a donde el payload se arma de verdad.
 *
 * `MkRoleResourceTest` lo verifica además contra una fila real; esto queda
 * como el guard barato de intención, en la línea del resto del archivo.
 */
test('roles.description: MkRoleResource la expone', function () {
    $src = (string) file_get_contents(
        packageRootRoleDesc().'/src/Http/Resources/MkRoleResource.php',
    );

    expect($src)->toMatch('/[\'"]description[\'"]\s*=>\s*\$this->description/');
});

/**
 * La invariante que de verdad importaba en F10-B07 —"`$fillable` no debe
 * nombrar columnas que la tabla no tiene", o si no el mass-assignment
 * revienta con SQLSTATE[42703]— NO se puede testear acá: comparar contra el
 * esquema real necesita una conexión con las migrations corridas, y
 * `MkLaravelTestCase` bootea un Capsule SIN conexión activa a propósito (ver
 * su docblock y `MkServiceProviderAutoDiscoverAbilitiesRefreshDatabaseTest`,
 * que documenta la misma limitación).
 *
 * Convención del paquete: acá se pinea la INTENCIÓN por source-parsing; la
 * EFECTIVIDAD contra una DB real la valida el consumer (RETO), que sí corre
 * migrations. Los 3 tests de arriba cubren la decisión; el guard de esquema
 * vive del lado del consumer.
 */
