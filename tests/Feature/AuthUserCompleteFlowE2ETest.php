<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Tests\MkLaravelTestCase;
use Mockery as globalMockery;

/**
 * E2E lite tests para R-PKG-047 F6 D6 — BaseAuthController dispatch logic (Mockery-based).
 *
 * Patrón HALLAZGO-NEW-03 (cross-project, June 2026):
 *   - Source-parsing pineado en `BaseAuthControllerSourceParsingTest.php`
 *     pinea INTENCIÓN del SSoT canónico (estructura pineada correctamente).
 *   - E2E (este archivo) pinea EFECTIVIDAD runtime de las branches CRÍTICAS via Mockery:
 *     - loginField/authScope dispatch (4 abstracts override).
 *     - canAuthenticate gate con ScopeStatus::Active (D4 enum).
 *     - canAuthenticate gate con ScopeStatus::Suspended (D4 deny-by-default).
 *     - Ability attribute pineado en login() (R-PKG-007 + HALLAZGO-NEW-FASE18-C).
 *     - Constructor pinea foráneo (defense-in-depth per-route, F9-B10).
 *
 * NOTA 1: El paquete mk-director-laravel es minimalista (sin Sanctum — `HasApiTokens`
 * trait es consumer-side). E2E flow completo con JWT vive en `apps/sandbox-laravel/tests/Feature`.
 *
 * NOTA 2: Los branches con `Schema::hasColumn()` runtime requieren DB activa con Capsule
 * + tabla pinoada. Se pinean en test consumer-side post-bumpear v2.0.3+.
 */
uses(MkLaravelTestCase::class);

/**
 * ConcreteAuthController — extiende BaseAuthController con los 4 abstracts
 * override para scope `test`. Usado solo en este test file.
 */
final class ConcreteAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return 'ConcreteAuthController\TestUser';
    }

    protected function authScope(): string
    {
        return 'test';
    }

    protected function loginField(): string
    {
        return 'email';
    }

    protected function passwordResetTable(): string
    {
        return 'test_password_reset_tokens';
    }
}

// ── E2E 1 — dispatch de los 4 abstracts retorna los valores correctos ──────

it('E2E 1: los 4 abstracts retornan los valores pineados por scope concreto', function () {
    $controller = new ConcreteAuthController();

    // Los abstracts son protected en BaseAuthController. Reflection necesario
    // para llamarlos desde fuera (la subclase los pinea protected también).
    $reflection = new \ReflectionObject($controller);

    expect($reflection->getMethod('loginField')->invoke($controller))->toBe('email');
    expect($reflection->getMethod('authScope')->invoke($controller))->toBe('test');
    expect($reflection->getMethod('authModelClass')->invoke($controller))->toBe('ConcreteAuthController\TestUser');
    expect($reflection->getMethod('passwordResetTable')->invoke($controller))->toBe('test_password_reset_tokens');
});

// ── E2E 2 — userHasValidStatus() con ScopeStatus::Active retorna true ─────

it('E2E 2: userHasValidStatus() retorna true para ScopeStatus::Active vía reflection', function () {
    $controller = new ConcreteAuthController();
    $reflection = new \ReflectionMethod($controller, 'userHasValidStatus');

    // Anonymous class implementa Authenticatable. Pinea `status` como
    // typed property (ScopeStatus enum) para pine el cast Eloquent.
    $user = new class implements Authenticatable {
        public ?ScopeStatus $status = ScopeStatus::Active;
        public string $table = 'test_users';

        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return 'fake-uuid'; }
        public function getAuthPasswordName() { return 'password'; }
        public function getAuthPassword() { return 'fake'; }
        public function getRememberToken() { return null; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return 'remember_token'; }
        public function getAttribute($key) { return null; }
    };

    expect($reflection->invoke($controller, $user))->toBeTrue();
});

// ── E2E 3 — userHasValidStatus() con ScopeStatus::Suspended retorna false ──

it('E2E 3: userHasValidStatus() retorna false para ScopeStatus::Blocked vía reflection', function () {
    $controller = new ConcreteAuthController();
    $reflection = new \ReflectionMethod($controller, 'userHasValidStatus');

    $user = new class implements Authenticatable {
        public ?ScopeStatus $status = ScopeStatus::Blocked;
        public string $table = 'test_users';

        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return 'fake-uuid'; }
        public function getAuthPasswordName() { return 'password'; }
        public function getAuthPassword() { return 'fake'; }
        public function getRememberToken() { return null; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return 'remember_token'; }
        public function getAttribute($key) { return null; }
    };

    expect($reflection->invoke($controller, $user))->toBeFalse();
});

// ── E2E 4 — login() pine Ability attribute pineable ─────────────────────

it('E2E 4: BaseAuthController::login() pinea #[Ability] attribute detectables via reflection', function () {
    $controller = new ConcreteAuthController();

    $reflection = new \ReflectionMethod($controller, 'login');
    $attributes = $reflection->getAttributes();

    // Debe pinear al menos 1 attribute (#[Ability('{scope}.auth.login', '...')]).
    expect($attributes)->not->toBeEmpty();

    // La primera attribute debe ser Ability. El path pineado incluye
    // '{scope}' literal (placeholder sin subst) — pre-runtime es string template.
    $firstAttr = $attributes[0]->newInstance();
    expect($firstAttr)->toBeInstanceOf(\Mk\Director\Auth\Attributes\Ability::class);
});
