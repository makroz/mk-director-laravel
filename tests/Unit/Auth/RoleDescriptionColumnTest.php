<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Models\Role;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK10 F10-B07 — la tabla `roles` NUNCA tuvo columna `description`
 * (solo `id/name/guard/is_fixed/timestamps`, ver
 * `2026_06_10_000002_create_roles_table.php`), pero `Role::$fillable` Y
 * `RoleResource` la exponían/aceptaban. Cualquier create/update de role
 * con `description` en el payload disparaba
 * `SQLSTATE[42703] column "description" does not exist`.
 *
 * `Ability` SÍ tiene `description` (columna real en su migration) — el fix
 * NO la toca, solo Role.
 */
uses(MkLaravelTestCase::class);

function packageRootRoleDesc(): string
{
    return dirname(__DIR__, 3);
}

test('F10-B07: Role::$fillable NO contiene description (columna inexistente)', function () {
    expect((new Role)->getFillable())->not->toContain('description');
    expect((new Role)->getFillable())->toBe(['name', 'guard', 'is_fixed']);
});

test('F10-B07: role-resource.stub NO expone description', function () {
    $stub = (string) file_get_contents(packageRootRoleDesc().'/src/Stubs/auth-user/role-resource.stub');

    expect($stub)->not->toMatch('/[\'"]description[\'"]\s*=>\s*\$this->description/');
});

test('F10-B07: la migration de roles confirma que NO hay columna description (regression guard)', function () {
    $src = (string) file_get_contents(
        packageRootRoleDesc().'/src/Auth/Database/Migrations/2026_06_10_000002_create_roles_table.php',
    );

    expect($src)->not->toContain("\$table->string('description')");
    expect($src)->not->toContain("\$table->text('description')");
});
