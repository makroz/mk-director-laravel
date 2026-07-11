<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-027 scaffolder hardening + auth flow defaults.
 *
 * Source: Code Review 4R post-merge audit 2026-06-28 sobre `mariogfos/reto`.
 * Feedback: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md`.
 * 9 hallazgos pineables → 7 fixes en este sprint (PKG-NEW-01 a PKG-NEW-07),
 * 2 diferidos (PKG-NEW-08 helper opcional, PKG-NEW-09 docs).
 *
 * Patrón: source-parsing pinea INTENCIÓN del fix (estructura del stub).
 * Para pinear EFECTIVIDAD (que el scaffolder emite código que efectivamente
 * funciona), ver audit e2e en sandbox consumer — ver `apps/sandbox-laravel/`.
 *
 * Spec: R-PKG-027.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg027(): string
{
    return dirname(__DIR__, 3);
}

function readStubRPkg027(string $path): string
{
    $fullPath = packageRootRPkg027().'/'.$path;
    expect(file_exists($fullPath))->toBeTrue("Stub must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('PKG-NEW-01 — migration stub siempre crea email_verified_at', function (): void {
    test('migration stub tiene columna email_verified_at hardcoded (sin placeholder condicional)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user.migration.stub');

        expect($stub)
            ->toContain("\$table->timestamp('email_verified_at')->nullable()");

        expect($stub)
            ->not->toContain('{{emailVerifiedAtColumn}}');
    });
});

describe('PKG-NEW-02 — admin-service stub: mutateData + sin Hash::make manual', function (): void {
    test('admin-service stub usa mutateData (no beforeCreate)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/admin-service.stub');

        expect($stub)->toContain('protected function mutateData(array $data): array');
        expect($stub)->not->toContain('protected function beforeCreate(');
    });

    test('admin-service stub NO hashea password manualmente (delega al cast)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/admin-service.stub');

        expect($stub)->not->toContain('Hash::make($data[\'password\'])');
        expect($stub)->not->toContain("str_starts_with(\$data['password'], '$2y$')");
    });

    test('admin-service stub update() llama mutateData (no beforeCreate)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/admin-service.stub');

        expect($stub)->toContain('$data = $this->mutateData($data);');
        expect($stub)->not->toContain('$data = $this->beforeCreate($data);');
    });
});

describe('PKG-NEW-04 + PKG-NEW-05 — status check (R-PKG-047 D4 ScopeStatus enum)', function (): void {
    // **ELIMINADOS post-R-PKG-047 D1+D4**: los 4 tests originales pineaban
    // el `is_active` boolean check en el stub VIEJO (~500 LOC). Post-D1, el
    // stub es thin wrapper; post-D4, el status check se hace vía enum
    // `ScopeStatus` (no `is_active` boolean). La lógica vive en
    // `BaseAuthController::userHasValidStatus()` con BC fallback a
    // `is_active` para scopes pre-D4.

    test('R-PKG-047 D1+D4: BaseAuthController::userHasValidStatus() SSoT del status check', function (): void {
        $base = readStubRPkg027('src/Auth/Controllers/BaseAuthController.php');

        // SSoT: el status check vive en BaseAuthController.
        expect($base)->toContain('protected function userHasValidStatus(');
    });

    test('R-PKG-047 D1: auth-controller stub NO contiene Schema::hasColumn (lógica en BaseAuthController)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user.auth-controller.stub');

        // El thin wrapper NO tiene el check de `is_active` inline. Lo
        // hereda de BaseAuthController::userHasValidStatus() (que SÍ
        // consulta Schema::hasColumn internamente, con BC fallback).
        expect($stub)->not->toContain('use Illuminate\\Support\\Facades\\Schema;');
        expect($stub)->not->toContain("Schema::hasColumn(");
        expect($stub)->not->toContain("'is_active'");
    });
});

describe('PKG-NEW-06 — admin-data-dto stub: mapea todos los profile fields', function (): void {
    test('admin-data-dto stub tiene placeholders nuevos en fromRequest, fromArray, toArray', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/admin-data-dto.stub');

        expect($stub)->toContain('{{profileFieldsFromRequest}}');
        expect($stub)->toContain('{{profileFieldsFromArray}}');
        expect($stub)->toContain('{{profileFieldsToArray}}');
    });

    $command = readStubRPkg027('src/Console/Commands/MakeAuthUserCommand.php');

    test('MakeAuthUserCommand tiene helpers buildProfileFieldsFromRequest/FromArray/ToArray', function () use ($command): void {
        expect($command)->toContain('protected function buildProfileFieldsFromRequest(');
        expect($command)->toContain('protected function buildProfileFieldsFromArray(');
        expect($command)->toContain('protected function buildProfileFieldsToArray(');
    });

    test('MakeAuthUserCommand wirea los nuevos mappings en $crudReplacements', function () use ($command): void {
        expect($command)->toContain("'{{profileFieldsFromRequest}}'");
        expect($command)->toContain("'{{profileFieldsFromArray}}'");
        expect($command)->toContain("'{{profileFieldsToArray}}'");
    });
});

describe('PKG-NEW-07 — sync-role-abilities-request stub', function (): void {
    test('sync-role-abilities-request.stub existe', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/sync-role-abilities-request.stub');

        expect($stub)->toContain('class SyncRoleAbilitiesRequest extends FormRequest');
        expect($stub)->toContain('public function rules(): array');
        expect($stub)->toContain("'abilities'");
        expect($stub)->toContain('Ability::query()->pluck(\'name\')->all()');
    });

    test('role-controller stub usa SyncRoleAbilitiesRequest (no validación inline)', function (): void {
        $stub = readStubRPkg027('src/Stubs/auth-user/role-controller.stub');

        expect($stub)->toContain('SyncRoleAbilitiesRequest $request');
        expect($stub)->not->toContain("\$request->validate([");
    });

    $command = readStubRPkg027('src/Console/Commands/MakeAuthUserCommand.php');

    test('MakeAuthUserCommand genera el SyncRoleAbilitiesRequest stub', function () use ($command): void {
        expect($command)->toContain("'auth-user/sync-role-abilities-request.stub'");
        expect($command)->toContain("'SyncRoleAbilitiesRequest.php'");
    });
});