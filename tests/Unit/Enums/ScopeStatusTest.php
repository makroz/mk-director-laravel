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

it('ScopeStatus enum tiene 4 casos pineados con valores string canónicos (D4 SSoT)', function () {
    expect(ScopeStatus::Active->value)->toBe('active');
    expect(ScopeStatus::Inactive->value)->toBe('inactive');
    expect(ScopeStatus::Suspended->value)->toBe('suspended');
    expect(ScopeStatus::Pending->value)->toBe('pending');
});

it('ScopeStatus::Active->canAuthenticate() retorna true (gate default de BaseAuthController::userHasValidStatus)', function () {
    expect(ScopeStatus::Active->canAuthenticate())->toBeTrue();
});

it('ScopeStatus::Inactive/Suspended/Pending->canAuthenticate() retornan false (deny-by-default)', function () {
    expect(ScopeStatus::Inactive->canAuthenticate())->toBeFalse();
    expect(ScopeStatus::Suspended->canAuthenticate())->toBeFalse();
    expect(ScopeStatus::Pending->canAuthenticate())->toBeFalse();
});

it('ScopeStatus::default() retorna Active (convención de la agencia — login funcional por default)', function () {
    expect(ScopeStatus::default())->toBe(ScopeStatus::Active);
});

it('ScopeStatus::values() retorna array de 4 strings canónicos en orden de declaración (cross-stack contract)', function () {
    $values = ScopeStatus::values();

    expect($values)
        ->toBeArray()
        ->toHaveCount(4)
        ->toBe(['active', 'inactive', 'suspended', 'pending']);
});
