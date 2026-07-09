<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests para ARCH-01 / FEEDBACK6 — `mk:make:auth-user --managed-by`.
 *
 * El flag genera un recurso admin-scoped para que OTRO scope (el manager)
 * administre users de ESTE scope con su propio token, sin romper la defensa
 * anti cross-scope del CRUD self-service.
 */
uses(MkLaravelTestCase::class);

function managedByCmdSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php');
}

function managedByStub(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/src/Stubs/auth-user/'.$name);
}

// ── Signature ─────────────────────────────────────────────────────────────

test('command signature incluye --managed-by option', function () {
    expect(managedByCmdSource())->toContain('--managed-by=');
});

// ── Validaciones en handle() ────────────────────────────────────────────────

test('handle() valida que --managed-by requiere --with-crud', function () {
    $src = managedByCmdSource();

    expect($src)->toContain('--managed-by requiere --with-crud');
    expect($src)->toMatch('/if \(! \$withCrud\)\s*\{/');
});

test('handle() rechaza --managed-by igual al propio scope', function () {
    expect(managedByCmdSource())->toContain('--managed-by no puede ser el mismo scope');
});

test('handle() invoca generateManagedResource cuando --managed-by está presente', function () {
    $src = managedByCmdSource();

    expect($src)->toContain('protected function generateManagedResource(');
    expect($src)->toMatch('/if \(\$managedBy !== null\)\s*\{[\s\S]{0,120}?\$this->generateManagedResource/');
});

// ── Stub de rutas managed ────────────────────────────────────────────────────

test('managed-routes stub existe y namespacea por el scope manager', function () {
    $stub = managedByStub('managed-routes.stub');

    // Prefijo bajo el manager, NO el global ni el self-service.
    expect($stub)->toContain("Route::prefix('api/{{managerNameLower}}/{{moduleNamePluralLower}}')");
    // Guard + abilities del MANAGER.
    expect($stub)->toContain('mk.auth:{{managerNameLower}}');
    expect($stub)->toContain('mk.ability:{{managerNameLower}}.{{moduleNamePluralLower}}.viewAny');
    expect($stub)->toContain('mk.ability:{{managerNameLower}}.{{moduleNamePluralLower}}.delete');
    // Reusa el controller del scope gestionado (MME-safe).
    expect($stub)->toContain('{{ModuleName}}Controller::class');
    // Documenta explícitamente quién administra y qué scope afecta el permiso.
    expect($stub)->toContain('GESTIONADAS POR el scope');
});

// ── Stub del seeder managed ──────────────────────────────────────────────────

test('managed-seeder stub concede abilities del manager a sus roles', function () {
    $stub = managedByStub('managed-seeder.stub');

    expect($stub)->toContain('class {{ModuleName}}ManagedBy{{managerName}}Seeder extends Seeder');
    // Resuelve roles por (name, guard) del manager — F6-03 isolation.
    expect($stub)->toContain("['name' => 'admin', 'guard' => \$managerGuard]");
    expect($stub)->toContain("['name' => 'viewer', 'guard' => \$managerGuard]");
    // Abilities namespaceadas por el manager.
    expect($stub)->toContain('{$managerGuard}.{$resource}.{$action}');
});

// ── Registro en el ServiceProvider ───────────────────────────────────────────

test('generateManagedResource registra managed.php en el ServiceProvider', function () {
    $src = managedByCmdSource();

    expect($src)->toContain('protected function extendServiceProviderWithManagedRoutes(');
    expect($src)->toContain("Http/Routes/managed.php");
});
