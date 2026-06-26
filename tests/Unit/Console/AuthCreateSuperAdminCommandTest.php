<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\AuthCreateSuperAdminCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for `mk:auth:create-super-admin` added in
 * sprint 2026-06-24.
 *
 * Pinned contract:
 *   - Command exists with the expected signature + flags
 *   - Fails fast (FAILURE) when App\Modules\Admin\Models\Admin does
 *     not exist, with an actionable message pointing to mk:make:auth-user
 *   - Validates email format
 *   - Requires password confirmation
 *   - Requires password >= 8 chars
 *   - Is idempotent: re-running with the same email is a no-op (not an error)
 *   - Assigns the "super-admin" role (auto-created if missing)
 *   - Grants the "*" ability as a direct grant
 *
 * @see AuthCreateSuperAdminCommand
 */
uses(MkLaravelTestCase::class);

function createSuperAdminSource(): string
{
    $path = dirname(__DIR__, 3).'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    expect(file_exists($path))->toBeTrue("AuthCreateSuperAdminCommand must exist at $path");

    return (string) file_get_contents($path);
}

test('mk:auth:create-super-admin command exists with the expected signature', function () {
    $source = createSuperAdminSource();

    expect($source)->toContain('class AuthCreateSuperAdminCommand extends Command');
    expect($source)->toContain("'mk:auth:create-super-admin");
    expect($source)->toContain('--email=');
    expect($source)->toContain('--name=');
    expect($source)->toContain('--password=');
});

test('mk:auth:create-super-admin fails when App\\Modules\\Admin\\Models\\Admin does not exist', function () {
    $source = createSuperAdminSource();

    expect($source)->toContain("'App\\\\Modules\\\\Admin\\\\Models\\\\Admin'");
    expect($source)->toContain('class_exists($adminModel)');
    // The error message points to mk:make:auth-user so the dev knows
    // exactly what to run.
    expect($source)->toContain('mk:make:auth-user Admin');
});

test('mk:auth:create-super-admin validates email format', function () {
    $source = createSuperAdminSource();

    expect($source)->toContain('FILTER_VALIDATE_EMAIL');
    expect($source)->toContain('Email inválido');
});

test('mk:auth:create-super-admin requires password confirmation and minimum 8 chars', function () {
    $source = createSuperAdminSource();

    expect($source)->toContain('Confirmá el password');
    expect($source)->toContain('Los passwords no coinciden');
    expect($source)->toContain('mínimo 8 caracteres');
    expect($source)->toContain('strlen($password) < 8');
});

test('mk:auth:create-super-admin is idempotent on duplicate email', function () {
    $source = createSuperAdminSource();

    expect($source)->toContain('where(\'email\', $email)->exists()');
    expect($source)->toContain('No se creó nada');
});

test('mk:auth:create-super-admin assigns role via iteration loop (R-PKG-014 MEJORA-04)', function () {
    $source = createSuperAdminSource();

    // R-PKG-014 MEJORA-04: ahora itera sobre $rolesToSeed para soportar --roles=csv.
    // Default BC: solo super-admin. Verificamos que llama assignRole dinámicamente.
    expect($source)->toMatch('/\$admin->assignRole\(\$roleName\)/');
    // The role's guard is the user's auth_scope ('admin'), enforced by
    // HasRoles::assignRole when the role doesn't exist yet.
    expect($source)->toContain('guard');
});

test('mk:auth:create-super-admin grants the * ability as a direct grant', function () {
    $source = createSuperAdminSource();

    // R-PKG-014 MEJORA-04: itera sobre roleAbilitiesMap y otorga cada ability.
    expect($source)->toMatch('/foreach\s*\(\s*\$roleAbilitiesMap\[\$roleName\]\s+as\s+\$ability\)/');
    expect($source)->toMatch('/\$admin->giveAbilityTo\(\$ability\)/');
    // It prints a canMk('*') check in the success table so the dev
    // sees that the super-admin contract holds.
    expect($source)->toContain("canMk('*')");
});
