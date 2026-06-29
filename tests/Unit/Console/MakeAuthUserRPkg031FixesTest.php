<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-031 scaffolder hardening — post-RETO fase 11 feedback.
 *
 * Source: Feedback RETO fase 11 2026-06-28 (`FEEDBACK-TO-MK-DIRECTOR.md`).
 * 2 hallazgos pineables → 2 fixes en este sprint:
 *   - PKG-NEW-16: register() scaffoldeado NO valida `name` ni `email` (NOT NULL en runtime).
 *   - PKG-NEW-09: register() scaffoldeado queda PÚBLICO por default cuando --with-crud
 *     está activo sin --with-auth-rbac. Cualquiera puede crear admins (escalación).
 *
 * Patrón: source-parsing pinea INTENCIÓN del fix (estructura del command).
 * Para pinear EFECTIVIDAD (que el scaffolder emite código que efectivamente
 * funciona runtime), ver audit e2e en sandbox consumer — `apps/sandbox-laravel/`
 * y RETO fase 12+ (clean rebuild que valida los 2 fixes end-to-end).
 *
 * Spec: R-PKG-031.
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg031(): string
{
    return dirname(__DIR__, 3);
}

function readCommandRPkg031(): string
{
    $fullPath = packageRootRPkg031().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($fullPath))->toBeTrue("Command must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('PKG-NEW-16 — register() scaffoldeado valida name + loginField (no rompe con NOT NULL)', function (): void {
    $command = readCommandRPkg031();

    test('MakeAuthUserCommand pinea regla `name` required (always) en register()', function () use ($command): void {
        // PKG-NEW-16 fix: el register() scaffoldeado debe validar `name` (NOT NULL
        // heredado del AuthUser base). Antes solo validaba profile fields + password,
        // causando SQLSTATE 500 `NOT NULL constraint failed: {scope}.name` en runtime.

        expect($command)->toContain("'name' => ['required', 'string', 'max:255']");
    });

    test('MakeAuthUserCommand pinea regla `loginField` (rama email) en register()', function () use ($command): void {
        // Cuando loginField=email (default), register() debe validar `email`
        // con regla `['required', 'email', 'unique:...]`. Esto evita SQLSTATE
        // `NOT NULL constraint failed: admins.email` cuando el consumer trata
        // de registrar sin email.

        expect($command)->toContain("['required', 'email', 'unique:");
    });

    test('MakeAuthUserCommand pinea regla `loginField` (rama no-email) en register()', function () use ($command): void {
        // Cuando loginField=ci (RETO Bolivia), register() debe validar `ci`
        // con regla `['required', 'string', 'unique:...]` (NO `email` validation
        // — no es un email).

        expect($command)->toContain("['required', 'string', 'unique:");
    });

    test('MakeAuthUserCommand referencia PKG-NEW-16 en comentarios (drift de feedback trazable)', function () use ($command): void {
        // La fix debe estar documentada en el command source para que un dev
        // pueda trazarla al feedback original.

        expect($command)->toContain('PKG-NEW-16');
    });
});

describe('PKG-NEW-09 — register() scaffoldeado pinea middleware cuando --with-crud está activo', function (): void {
    $command = readCommandRPkg031();

    test('MakeAuthUserCommand pinea middleware mk.auth:{scope} en registerRoute cuando --with-crud activo', function () use ($command): void {
        // PKG-NEW-09 fix: cuando --with-crud está activo, el registerRoute debe
        // incluir middleware `mk.auth:{scope}` + `mk.ability:{scope}.{table}.create`.
        // Defense-in-depth: register es público por default (BC) pero si hay CRUD
        // scaffoldeado significa que las abilities `*.create` existen y deben
        // proteger también register.
        //
        // R-PKG-031 PKG-NEW-17 fix (v1.7.1-rc1): el placeholder `{{moduleNameLower}}`
        // se reemplazó por PHP interpolation `{$scopeLower}` (PHP-side) para evitar
        // literales no-interpolados en el stub generado (R-AD-020 workaround
        // absorbido).
        //
        // Escape `\$scopeLower`/`\$scopePlural` para que PHP no interpole las variables
        // (que están undefined en este scope del test) — pineamos el STRING LITERAL
        // que el comando source contiene.

        expect($command)->toContain("'mk.auth:{\$scopeLower}', 'mk.ability:{\$scopeLower}.{\$scopePlural}.create'");
    });

    test('MakeAuthUserCommand gate el middleware en $withCrud (BC preserved sin --with-crud)', function () use ($command): void {
        // El middleware solo se pinea cuando $withCrud es true. Sin --with-crud,
        // register sigue público (BC con v1.5.0-rc4).
        //
        // Adaptado al v1.7.1+ PKG-NEW-17 (PHP interpolation en lugar de literal).

        expect($command)->toMatch('/\$registerRoute\s*=\s*"\s*\\\\n\s+Route::post\(\'register\'/s');

        // Verificar que el bloque del middleware está dentro de un `if ($withCrud)`.
        // Usa [\s\S] para match multi-línea (la versión `[^}]*` original fallaba
        // porque había comentarios entre `{` y el `->middleware(...)`).
        expect($command)->toMatch('/if\s*\(\$withCrud\)\s*\{[\s\S]*?->middleware\(\[\s*\'mk\.auth/s');
    });

    test('MakeAuthUserCommand referencia PKG-NEW-09 en comentarios (drift de feedback trazable)', function () use ($command): void {
        expect($command)->toContain('PKG-NEW-09');
    });

    test('MakeAuthUserCommand referencia R-PKG-031 (sprint ID del package)', function () use ($command): void {
        // R-PKG-031 es el ID del sprint del paquete para estos 2 fixes.
        expect($command)->toContain('R-PKG-031');
    });
});