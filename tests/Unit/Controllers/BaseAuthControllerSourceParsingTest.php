<?php

declare(strict_types=1);

use Mk\Director\Auth\Controllers\BaseAuthController;

/**
 * Source-parsing tests para BaseAuthController (R-PKG-047 F6 D6).
 *
 * HALLAZGO-NEW-03 (cross-project binding): source-parsing tests pinean INTENCIÓN
 * del fix (estructura pineada correctamente). NO pinean EFECTIVIDAD runtime
 * (eso vive en AuthUserCompleteFlowE2ETest.php — Feature test con DB activa).
 *
 * Patrón: leer el source real del archivo pineado (no el vendor/, sino
 * src/ directo) y verificar que las estructuras pineadas esperadas están
 * presentes via regex / str_contains.
 */

$packageSrc = dirname(__DIR__, 3) . '/src/Auth/Controllers/BaseAuthController.php';

it('BaseAuthController extends BaseController (R-PKG-047 D1)', function () use ($packageSrc) {
    $source = file_get_contents($packageSrc);

    expect($source)
        ->toContain('abstract class BaseAuthController extends BaseController')
        ->not->toContain('class BaseAuthController extends Illuminate\\Routing\\Controller');
});

it('BaseAuthController declares 4 abstract methods (D1 pinea estructura correcta)', function () use ($packageSrc) {
    $source = file_get_contents($packageSrc);
    $expected = [
        'abstract protected function authModelClass(): string;',
        'abstract protected function authScope(): string;',
        'abstract protected function loginField(): string;',
        'abstract protected function passwordResetTable(): string;',
    ];

    foreach ($expected as $signature) {
        expect($source)->toContain($signature);
    }
});

it('BaseAuthController declares 5 hook methods with default implementations (D1 hook contract)', function () use ($packageSrc) {
    $source = file_get_contents($packageSrc);
    $expectedHooks = [
        'protected function beforeLogin(Request $request, array $credentials): ?array',
        'protected function afterLogin(Request $request, Authenticatable $user, array $tokens): void',
        'protected function customizeLoginValidationRules(): array',
        'protected function customizeMePayload(Authenticatable $user): array',
        'protected function shouldSendResetNotification(Authenticatable $user, string $token): bool',
    ];

    foreach ($expectedHooks as $hook) {
        expect($source)->toContain($hook);
    }
});

it('BaseAuthController declares 10 public endpoints (D1 cobertura completa)', function () use ($packageSrc) {
    $source = file_get_contents($packageSrc);

    // 10 endpoints heredados: login, refresh, me, logout, logoutAll,
    // forgotPassword, resetPassword, changePassword, verifyEmail, resendVerification.
    $expectedMethods = [
        'public function login(Request $request): JsonResponse',
        'public function refresh(Request $request): JsonResponse',
        'public function me(Request $request): JsonResponse',
        'public function logout(Request $request): JsonResponse',
        'public function logoutAll(Request $request): JsonResponse',
        'public function forgotPassword(Request $request): JsonResponse',
        'public function resetPassword(Request $request): JsonResponse',
        'public function changePassword(Request $request): JsonResponse',
        'public function verifyEmail(Request $request, string $id, string $hash): JsonResponse',
        'public function resendVerification(Request $request): JsonResponse',
    ];

    foreach ($expectedMethods as $method) {
        expect($source)->toContain($method);
    }
});

it('BaseAuthController pinea Ability attribute en cada método público pineable (R-PKG-007 + HALLAZGO-NEW-FASE18-C)', function () use ($packageSrc) {
    $source = file_get_contents($packageSrc);

    // 10 endpoints pinean `#[Ability('{scope}.auth.{action}', '...')]`
    // para que `mk:discover-abilities` los recolecte pre-bumpear a v2.0.4+.
    expect($source)->toContain("#[Ability('{scope}.auth.login'");
    expect($source)->toContain("#[Ability('{scope}.auth.refresh'");
    expect($source)->toContain("#[Ability('{scope}.auth.me'");
    expect($source)->toContain("#[Ability('{scope}.auth.logout'");
    expect($source)->toContain("#[Ability('{scope}.auth.logout-all'");
    expect($source)->toContain("#[Ability('{scope}.auth.password-forgot'");
    expect($source)->toContain("#[Ability('{scope}.auth.password-reset'");
    expect($source)->toContain("#[Ability('{scope}.auth.password-change'");
    expect($source)->toContain("#[Ability('{scope}.auth.email-verify'");
    expect($source)->toContain("#[Ability('{scope}.auth.email-resend'");
});
