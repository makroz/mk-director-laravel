<?php

declare(strict_types=1);

use Mk\Director\Auth\Enums\ScopeStatus;

/**
 * Unit tests para ScopeStatus enum (R-PKG-047 F6 D6).
 *
 * Patrón HALLAZGO-NEW-03: source-parsing pineado en
 * BaseAuthControllerSourceParsingTest pinea INTENCIÓN; este test pinea
 * EFECTIVIDAD del enum (se puede instanciar y sus métodos retornan valores
 * correctos). Para mayor profundidad del flow (login → enum check),
 * ver AuthUserCompleteFlowE2ETest.php (Feature test con DB activa).
 */

it('ScopeStatus enum tiene 4 casos pineados con valores int canónicos 1..4 (SSoT)', function () {
    // Revert 2026-07-19: el enum volvió a int-backed. Los values arrancan en
    // 1 (regla de la agencia, FEEDBACK4: el 0 se confunde con null/false).
    expect(ScopeStatus::Active->value)->toBe(1);
    expect(ScopeStatus::Inactive->value)->toBe(2);
    expect(ScopeStatus::Blocked->value)->toBe(3);
    expect(ScopeStatus::Pending->value)->toBe(4);
});

it('ScopeStatus::Active->canAuthenticate() retorna true (gate default de BaseAuthController::userHasValidStatus)', function () {
    expect(ScopeStatus::Active->canAuthenticate())->toBeTrue();
});

it('ScopeStatus::Inactive/Blocked/Pending->canAuthenticate() retornan false (deny-by-default)', function () {
    expect(ScopeStatus::Inactive->canAuthenticate())->toBeFalse();
    expect(ScopeStatus::Blocked->canAuthenticate())->toBeFalse();
    expect(ScopeStatus::Pending->canAuthenticate())->toBeFalse();
});

it('ScopeStatus::default() retorna Active (convención de la agencia — login funcional por default)', function () {
    expect(ScopeStatus::default())->toBe(ScopeStatus::Active);
});

it('ScopeStatus::values() retorna array de 4 ints canónicos en orden de declaración (cross-stack contract)', function () {
    $values = ScopeStatus::values();

    expect($values)
        ->toBeArray()
        ->toHaveCount(4)
        ->toBe([1, 2, 3, 4]);
});
