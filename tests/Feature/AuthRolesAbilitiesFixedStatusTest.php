<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Pinea el flag `is_fixed` (FixedStatus enum) sobre las tablas globales
 * `roles`/`abilities` del paquete.
 *
 * Contrato pineado:
 *  - (a) Las migrations `roles` y `abilities` declaran la columna
 *        `is_fixed` (`unsignedTinyInteger` default `0`).
 *  - (b) El seeder scaffoldeado (`admin-roles-seeder.stub`) + el mirror
 *        del sandbox (`AdminRolesSeeder`) marcan el rol `super-admin` y la
 *        ability wildcard `*` como Fixed. El command
 *        `AuthCreateSuperAdminCommand` también los pinea.
 *  - (c) El enum `FixedStatus` existe con `Editable = 0` / `Fixed = 1`.
 *  - (d) Los Resource stubs (role/ability) exponen `is_fixed`.
 *  - (e) Los modelos `Role`/`Ability` castean `is_fixed` a `FixedStatus`.
 *
 * Patrón: source-parsing (convención del paquete, sin ejecutar el command
 * end-to-end) + assertions directas sobre el enum.
 *
 * Spec: MK-LAR is_fixed / FixedStatus.
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: lee un archivo del paquete (relativo a la raíz del repo).
 */
function fixedPkgContents(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/'.$relativePath;

    if (! file_exists($path)) {
        test()->fail("Archivo no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

// ─── (c) El enum FixedStatus existe con Editable=0 / Fixed=1 ─────────────────

test('FixedStatus enum existe con Editable=0 y Fixed=1', function () {
    expect(FixedStatus::Editable->value)->toBe(0);
    expect(FixedStatus::Fixed->value)->toBe(1);
    expect(FixedStatus::default())->toBe(FixedStatus::Editable);
    expect(FixedStatus::Fixed->isFixed())->toBeTrue();
    expect(FixedStatus::Editable->isFixed())->toBeFalse();
    expect(FixedStatus::values())->toBe([0, 1]);
});

// ─── (a) Las migrations declaran is_fixed ────────────────────────────────────

test('migration roles declara is_fixed (unsignedTinyInteger default 0)', function () {
    $src = fixedPkgContents('src/Auth/Database/Migrations/2026_06_10_000002_create_roles_table.php');

    expect($src)->toContain("\$table->unsignedTinyInteger('is_fixed')->default(0);");
});

test('migration abilities declara is_fixed (unsignedTinyInteger default 0)', function () {
    $src = fixedPkgContents('src/Auth/Database/Migrations/2026_06_10_000003_create_abilities_table.php');

    expect($src)->toContain("\$table->unsignedTinyInteger('is_fixed')->default(0);");
});

// ─── (e) Los modelos castean is_fixed a FixedStatus ──────────────────────────

test('Role model tiene is_fixed en fillable y casteado a FixedStatus', function () {
    $src = fixedPkgContents('src/Auth/Models/Role.php');

    expect($src)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');
    expect($src)->toContain("'is_fixed',");
    expect($src)->toContain("'is_fixed' => FixedStatus::class,");
});

test('Ability model tiene is_fixed en fillable y casteado a FixedStatus', function () {
    $src = fixedPkgContents('src/Auth/Models/Ability.php');

    expect($src)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');
    expect($src)->toContain("'is_fixed',");
    expect($src)->toContain("'is_fixed' => FixedStatus::class,");
});

// ─── (b) Seeder stub marca super-admin + wildcard como Fixed ──────────────────

test('admin-roles-seeder stub marca super-admin role y wildcard * como Fixed', function () {
    $stub = fixedPkgContents('src/Stubs/auth-user/admin-roles-seeder.stub');

    // Import del enum.
    expect($stub)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');

    // super-admin role sembrado Fixed.
    expect($stub)->toContain("['guard' => \$scope, 'is_fixed' => FixedStatus::Fixed->value],");

    // Wildcard `*` con el flag isFixed=true (4to arg).
    expect($stub)->toContain("\$this->attachAbility(\$superAdmin, '*', 'Wildcard: todas las abilities.', true);");

    // Firma del helper acepta el flag y lo aplica.
    expect($stub)->toMatch('/function\s+attachAbility\([^)]*bool\s+\$isFixed\s*=\s*false\)/');
    expect($stub)->toContain('$isFixed ? FixedStatus::Fixed->value : FixedStatus::Editable->value');
});

// ─── (b) Command AuthCreateSuperAdmin pinea is_fixed en super-admin + * ───────

test('AuthCreateSuperAdminCommand pinea is_fixed en super-admin role y wildcard *', function () {
    $src = fixedPkgContents('src/Console/Commands/AuthCreateSuperAdminCommand.php');

    expect($src)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');
    expect($src)->toContain('use Mk\Director\Auth\Models\Ability;');
    expect($src)->toContain('use Mk\Director\Auth\Models\Role;');

    expect($src)->toContain("Role::query()->where('name', 'super-admin')->update(['is_fixed' => FixedStatus::Fixed->value]);");
    expect($src)->toContain("Ability::query()->where('name', '*')->update(['is_fixed' => FixedStatus::Fixed->value]);");
});

// ─── (d) Los Resource stubs exponen is_fixed ─────────────────────────────────

test('role-resource stub expone is_fixed como valor numerico', function () {
    $stub = fixedPkgContents('src/Stubs/auth-user/role-resource.stub');

    expect($stub)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');
    expect($stub)->toContain("'is_fixed' => \$this->is_fixed instanceof FixedStatus ? \$this->is_fixed->value : (int) (\$this->is_fixed ?? 0),");
});

test('ability-resource stub expone is_fixed como valor numerico', function () {
    $stub = fixedPkgContents('src/Stubs/auth-user/ability-resource.stub');

    expect($stub)->toContain('use Mk\Director\Auth\Enums\FixedStatus;');
    expect($stub)->toContain("'is_fixed' => \$this->is_fixed instanceof FixedStatus ? \$this->is_fixed->value : (int) (\$this->is_fixed ?? 0),");
});
