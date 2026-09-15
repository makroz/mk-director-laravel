<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-021 BUG-NEW-30 (MEDIUM) — tests source-parsing para pinear que
 * `mk:auth:create-super-admin` invoca el `AdminRolesSeeder` con namespace
 * DDD correcto (`App\Modules\Admin\Database\Seeders\`) y emite warning
 * explícito si no existe.
 *
 * El feedback de RETO fase 7 reportó:
 *   - El comando invocaba el seeder con namespace equivocado
 *     (`Database\Seeders\AdminRolesSeeder` en vez de
 *     `App\Modules\Admin\Database\Seeders\AdminRolesSeeder`).
 *   - El error se silenciaba internamente.
 *   - Resultado: `ability_role` quedaba vacío (0 rows en vez de 11).
 *   - Workaround: correr manualmente
 *     `php artisan db:seed "App\\Modules\\Admin\\Database\\Seeders\\AdminRolesSeeder"`.
 */
uses(MkLaravelTestCase::class);

test('AuthCreateSuperAdminCommand has seedAdminRolesIfAvailable helper', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    // Hallazgo #47: el seeder es el del `--scope`, no AdminRolesSeeder fijo.
    expect($source)->toContain('private function seedScopeRolesIfAvailable(): void');
});

test('AuthCreateSuperAdminCommand uses DDD namespace for seeder (R-P-009)', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    expect($source)->toContain('App\\\\Modules\\\\{$studly}\\\\Database\\\\Seeders\\\\{$studly}RolesSeeder');
});

test('AuthCreateSuperAdminCommand uses class_exists() to detect seeder', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    expect($source)->toContain('if (! class_exists($seederClass))');
});

test('AuthCreateSuperAdminCommand emits actionable warning when seeder is missing', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    expect($source)->toContain("Seeder DDD '{\$seederClass}' no existe");
    expect($source)->toContain('ability_role');
    // El comando de la advertencia es el del scope pedido (el viejo pineaba
    // `--with-crud`, un flag que ya no existe, y este pin lo matcheaba en un docblock).
    expect($source)->toContain('php artisan mk:make:auth-user {$studly}');
});

test('AuthCreateSuperAdminCommand invokes seeder via app() container (not global class name)', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    // Debe usar app() para resolver, no `new $seederClass()` (eso no respeta DI).
    expect($source)->toContain('app($seederClass)');
    expect($source)->toContain('$seeder->run()');
});

test('AuthCreateSuperAdminCommand: handle() calls seedAdminRolesIfAvailable after role/ability assignment', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php');
    // Buscar el patrón: asignación de roles+abilities → llamada al seeder.
    expect($source)->toContain('$this->seedScopeRolesIfAvailable()');
});
