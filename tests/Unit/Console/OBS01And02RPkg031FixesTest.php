<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-031 OBS-01 + OBS-02 — Post-RETO fase 11 feedback.
 *
 * Source: Feedback RETO fase 11 2026-06-28 (`FEEDBACK-TO-MK-DIRECTOR.md`).
 * 2 observaciones pineables → 2 acciones en este sprint:
 *   - OBS-02: `mk:auth:create-super-admin` pinea `is_active=true` explícito
 *     (defense-in-depth, evita admin nuevo con `null` implícito).
 *   - OBS-01: doble envelope en responses paginadas es by-design — pineado
 *     como DOCUMENTED en CHANGELOG + skill + api_contract del consumer.
 *
 * Patrón: source-parsing pinea INTENCIÓN del fix (estructura del command).
 * Para pinear EFECTIVIDAD del OBS-02 (que el comando efectivamente setea
 * `is_active=true` cuando la columna existe), ver test e2e en
 * `MakeAuthUserCommandOBS02E2ETest.php`.
 *
 * Spec: R-PKG-031 OBS-01/02.
 *
 * @see AuthCreateSuperAdminCommand
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg031OBS(): string
{
    return dirname(__DIR__, 3);
}

function readCommandRPkg031OBS(): string
{
    $fullPath = packageRootRPkg031OBS().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    expect(file_exists($fullPath))->toBeTrue("Command must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readSkillRPkg031OBS(): string
{
    $fullPath = '/Users/marioguzman/Desktop/Makromania/.makromania/agency/skills/mk-director-laravel/SKILL.md';
    expect(file_exists($fullPath))->toBeTrue("Skill must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readChangelogRPkg031OBS(): string
{
    $fullPath = packageRootRPkg031OBS().'/CHANGELOG.md';
    expect(file_exists($fullPath))->toBeTrue("CHANGELOG must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('OBS-02 — mk:auth:create-super-admin pinea is_active=true explícito (defense-in-depth)', function (): void {
    $command = readCommandRPkg031OBS();

    test('AuthCreateSuperAdminCommand importa el Schema facade', function () use ($command): void {
        // OBS-02 fix requiere Schema::hasColumn() check para BC con scopes
        // sin la columna is_active. El import debe estar presente.

        expect($command)->toContain('use Illuminate\\Support\\Facades\\Schema;');
    });

    test('AuthCreateSuperAdminCommand pinea Schema::hasColumn check para is_active', function () use ($command): void {
        // El fix pinea el check después del create() del admin.

        expect($command)->toContain("Schema::hasColumn");
        expect($command)->toContain("getTable()");
        expect($command)->toContain("'is_active'");
    });

    test('AuthCreateSuperAdminCommand pinea is_active = true + save()', function () use ($command): void {
        // El fix pinea la asignación explícita + save (bypass fillable).

        expect($command)->toContain("\$admin->is_active = true;");
        expect($command)->toContain("\$admin->save();");
    });

    test('AuthCreateSuperAdminCommand referencia OBS-02 + R-PKG-031 en comentarios (drift trazable)', function () use ($command): void {
        // Drift trazable per R-G-032.

        expect($command)->toContain('OBS-02');
        expect($command)->toContain('R-PKG-031');
    });

    test('AuthCreateSuperAdminCommand referencia defensa del patrón via fillable', function () use ($command): void {
        // Documenta que is_active NO está en fillable del modelo base.

        expect($command)->toContain('fillable');
    });
});

describe('OBS-01 — Doble envelope en paginator es by-design (DOCUMENTED)', function (): void {
    $skill = readSkillRPkg031OBS();
    $changelog = readChangelogRPkg031OBS();

    test('mk-director-laravel skill documenta OBS-01 (doble envelope) en sección Versión actual', function () use ($skill): void {
        // OBS-01 pineado en la skill como "DOCUMENTED BY DESIGN".

        expect($skill)->toContain('OBS-01');
        expect($skill)->toContain('Doble envelope');
        expect($skill)->toContain('data.data');
    });

    test('mk-director-laravel skill referencia R-PKG-023 (origen del envelope)', function () use ($skill): void {
        // El doble envelope es patrón canónico R-PKG-023.

        expect($skill)->toContain('R-PKG-023');
    });

    test('mk-director-laravel skill explica por qué NO se unifica (cross-stack)', function () use ($skill): void {
        // La decisión de NO unificar se basa en cross-stack impact.

        expect($skill)->toContain('cross-stack');
    });

    test('CHANGELOG.md del paquete pinea OBS-01 como Documented', function () use ($changelog): void {
        // CHANGELOG actualizado con OBS-01 en la rama [Unreleased].

        expect($changelog)->toContain('OBS-01');
        expect($changelog)->toContain('### Documented');
        expect($changelog)->toContain('Doble envelope');
        expect($changelog)->toContain('BY DESIGN');
    });

    test('CHANGELOG.md pinea OBS-02 en la rama [Unreleased]', function () use ($changelog): void {
        // CHANGELOG actualizado con OBS-02.

        expect($changelog)->toContain('OBS-02');
        expect($changelog)->toContain('is_active = true');
    });
});