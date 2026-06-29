<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-031 OBS-01 + OBS-02 cleanup — Reverted/Updated post-R-PKG-024 + R-PKG-032 GA.
 *
 * Sprint history:
 *   - 2026-06-28: R-PKG-031 pino OBS-01 ("doble envelope by design", DOCUMENTED) y
 *     OBS-02 (`is_active=true` defense-in-depth). Los tests de R-PKG-031 pineaban
 *     ese estado.
 *   - 2026-06-28 (mismo día): Mario flipió OBS-01 ("by design" → "PROHIBIDO data.data")
 *     y se pineó R-PKG-024 v1.7.0 GA como single-level envelope OBLIGATORIO.
 *   - 2026-06-29: R-PKG-032 v1.8.0 MAJOR pineó grouping `__extraData.pagination`.
 *
 * Este archivo (renombrado de `OBS01And02RPkg031FixesTest.php`) pinea el estado
 * canónico POST-R-PKG-024 + R-PKG-032, NO el estado OBS-01 v1.6.3 pre-revert.
 *
 * El reverso de OBS-01 está documentado en CHANGELOG.md `## [v1.7.0]` (R-PKG-024 GA
 * + OBS-01 REVERTED) y pineado en el § "Cambios en v1.7.0" de la skill.
 *
 * Spec: R-PKG-024 (single-level envelope, v1.7.0 GA) + R-PKG-032 (grouped pagination,
 * v1.8.0 MAJOR).
 *
 * @see AuthCreateSuperAdminCommand (OBS-02 fix pineado — sigue activo)
 * @see BaseController::sendResponse() (R-PKG-024 + R-PKG-032 implementation)
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg031Cleanup(): string
{
    return dirname(__DIR__, 3);
}

function readCommandRPkg031Cleanup(): string
{
    $fullPath = packageRootRPkg031Cleanup().'/src/Console/Commands/AuthCreateSuperAdminCommand.php';
    expect(file_exists($fullPath))->toBeTrue("Command must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readSkillRPkg031Cleanup(): string
{
    $fullPath = '/Users/marioguzman/Desktop/Makromania/.makromania/agency/skills/mk-director-laravel/SKILL.md';
    expect(file_exists($fullPath))->toBeTrue("Skill must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readChangelogRPkg031Cleanup(): string
{
    $fullPath = packageRootRPkg031Cleanup().'/CHANGELOG.md';
    expect(file_exists($fullPath))->toBeTrue("CHANGELOG must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('OBS-02 (R-PKG-031) — mk:auth:create-super-admin pinea is_active=true (defense-in-depth, sigue válido)', function (): void {
    $command = readCommandRPkg031Cleanup();

    test('AuthCreateSuperAdminCommand importa el Schema facade', function () use ($command): void {
        // OBS-02 fix requiere Schema::hasColumn() check para BC con scopes
        // sin la columna is_active. El import debe estar presente.

        expect($command)->toContain('use Illuminate\\Support\\Facades\\Schema;');
    });

    test('AuthCreateSuperAdminCommand pinea Schema::hasColumn check para is_active', function () use ($command): void {
        // El fix pinea el check después del create() del admin.

        expect($command)->toContain('Schema::hasColumn');
        expect($command)->toContain('getTable()');
        expect($command)->toContain("'is_active'");
    });

    test('AuthCreateSuperAdminCommand pinea is_active = true + save()', function () use ($command): void {
        // El fix pinea la asignación explícita + save (bypass fillable).

        expect($command)->toContain("\$admin->is_active = true;");
        expect($command)->toContain("\$admin->save();");
    });

    test('AuthCreateSuperAdminCommand referencia OBS-02 + R-PKG-031 en comentarios (drift trazable)', function () use ($command): void {
        expect($command)->toContain('OBS-02');
        expect($command)->toContain('R-PKG-031');
    });

    test('AuthCreateSuperAdminCommand referencia defensa del patrón via fillable', function () use ($command): void {
        expect($command)->toContain('fillable');
    });
});

describe('R-PKG-024 v1.7.0 GA — Single-level envelope (OBS-01 reverso pineado)', function (): void {
    // OBS-01 ("doble envelope by design") fue REVERTIDO por Mario el 2026-06-28.
    // R-PKG-024 v1.7.0 GA pineó single-level envelope OBLIGATORIO + PROHIBIDO data.data.
    // Estos tests reemplazan los pre-existentes que pineaban el state pre-revert.

    $skill = readSkillRPkg031Cleanup();
    $changelog = readChangelogRPkg031Cleanup();

    test('Skill documenta R-PKG-024 (single-level envelope, regla de oro)', function () use ($skill): void {
        // R-PKG-024 vigente post-v1.7.0. La skill debe pinear el nombre del rule.

        expect($skill)->toContain('R-PKG-024');
        expect($skill)->toContain('Single-level envelope');
        expect($skill)->toContain('regla de oro');
    });

    test('Skill PROHIBE data.data (R-PKG-024 — invariante post-revert)', function () use ($skill): void {
        // El state actual: data.data NO está permitido. La skill debe decirlo
        // explícitamente como PROHIBIDO.

        expect($skill)->toContain('PROHIBIDO');
        expect($skill)->toContain('data.data');
    });

    test('Skill documenta el REVERT de OBS-01 (by design → single-level)', function () use ($skill): void {
        // Drift trazable per R-G-032. La skill debe referenciar explícitamente
        // que OBS-01 ("by design") fue revertido por Mario en favor de R-PKG-024.

        expect($skill)->toContain('OBS-01');
        expect($skill)->toContain('Mario flipió');
        expect($skill)->toContain('R-PKG-031');
    });

    test('Skill NO documenta "doble envelope by design" como canonical (revertido)', function () use ($skill): void {
        // Defense-in-depth: pinea que el texto obsoleto NO está presente en
        // secciones canónicas (post-revert). Si en el futuro alguien vuelve a
        // pinear "by design", este test fallará y forzará la conversación.

        // "BY DESIGN" pineado SOLO dentro del contexto del R-PKG-031 reverso
        // (drift history), NO como descripción de comportamiento actual.
        // Búsqueda: si el comportamiento canónico NO menciona "BY DESIGN"
        // fuera del § de R-PKG-031 revert. Aproximamos con: no más de 1 match.

        $matches = substr_count($skill, 'BY DESIGN');
        expect($matches)->toBeLessThanOrEqual(1);

        // Y "Doble envelope" como título/descripción canónica: NO debe estar.
        expect($skill)->not->toContain('Doble envelope');
    });

    test('Skill documenta R-PKG-024 seguimiento cross-stack (mk-core + mk-web + mk-mobile)', function () use ($skill): void {
        // R-G-032 audit cross-stack: el rule R-PKG-024 también pineá en mk-core/web/mobile.

        expect($skill)->toContain('mk-core');
        expect($skill)->toContain('web');
        expect($skill)->toContain('mobile');
    });
});

describe('R-PKG-032 v1.8.0 MAJOR — Pagination envelope grouped (post-OBS-01-revert)', function (): void {
    // R-PKG-032 es la consecuencia lógica de R-PKG-024 aplicada al pagination grouping.
    // Pineada el 2026-06-29 (este mismo sprint de cleanup absorbido).

    $skill = readSkillRPkg031Cleanup();
    $changelog = readChangelogRPkg031Cleanup();

    test('Skill documenta R-PKG-032 (pagination envelope grouping)', function () use ($skill): void {
        expect($skill)->toContain('R-PKG-032');
        expect($skill)->toContain('Pagination envelope');
        expect($skill)->toContain('grouping');
    });

    test('Skill pinea shape CANÓNICO grouped (5 LA keys en sub-object pagination)', function () use ($skill): void {
        // JSON example en la skill debe mostrar __extraData.pagination anidado
        // (no flat al top-level).

        expect($skill)->toContain('"pagination":');
        expect($skill)->toContain('"current_page"');
        expect($skill)->toContain('"has_more_pages"');
        // R-PKG-032 invariant: keys NO están al top-level plano.
        expect($skill)->toContain('R-PKG-032');
    });

    test('Skill explica BC break + custom keys flat (no agrupadas)', function () use ($skill): void {
        // Pinear que la skill documenta el BC break y la coexistencia de custom keys
        // flat (no dentro de pagination).

        expect($skill)->toContain('BC break');
        expect($skill)->toContain('audit_checked');
        expect($skill)->toContain('request_id');
    });

    test('CHANGELOG documenta v1.8.0 con R-PKG-032 grouping', function () use ($changelog): void {
        expect($changelog)->toContain('[v1.8.0]');
        expect($changelog)->toContain('R-PKG-032');
        expect($changelog)->toContain('__extraData.pagination');
    });
});

describe('R-PKG-031 OBS-01 reverso — CHANGELOG documenta el pivot', function (): void {
    // OBS-01 fue revertido por R-PKG-024 v1.7.0 GA. El CHANGELOG debe documentar
    // este giro (Mario flipió la decisión).

    $changelog = readChangelogRPkg031Cleanup();

    test('CHANGELOG sección v1.7.0 GA pinea OBS-01 REVERTED + R-PKG-024', function () use ($changelog): void {
        // El CHANGELOG `## [v1.7.0]` debe mencionar la OBS-01 reversion.

        expect($changelog)->toContain('[v1.7.0]');
        expect($changelog)->toContain('R-PKG-024');
        expect($changelog)->toContain('Single-level envelope');
        expect($changelog)->toContain('OBS-01');
        // Mario flipió la decisión.
        expect($changelog)->toContain('Mario flipió');
    });
});
