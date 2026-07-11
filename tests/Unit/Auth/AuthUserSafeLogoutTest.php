<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing + reflection tests for R-PKG-027 PKG-NEW-08 helper.
 *
 * Source: Code Review 4R post-merge audit 2026-06-28 sobre `mariogfos/reto`.
 * El reporte 4R pineó que el `AuthController::logout()` scaffoldeado usaba
 * `$token = $user->currentAccessToken(); $token->delete();` que revienta con
 * `Call to a member function delete() on null` cuando `currentAccessToken()`
 * retorna null (cookie-based auth stateful SPA, o token ya revocado).
 *
 * Fix: agregar `safeLogoutCurrentToken()` al modelo base `AuthUser` y
 * actualizar el stub scaffoldeado para usarlo.
 *
 * Spec: R-PKG-027 PKG-NEW-08.
 * @see \Mk\Director\Auth\Models\AuthUser::safeLogoutCurrentToken()
 */
uses(MkLaravelTestCase::class);

function readAuthUserSrc(): string
{
    $path = dirname(__DIR__, 3).'/src/Auth/Models/AuthUser.php';
    expect(file_exists($path))->toBeTrue("AuthUser must exist at $path");

    return file_get_contents($path);
}

function readAuthControllerStub(): string
{
    $path = dirname(__DIR__, 3).'/src/Stubs/auth-user.auth-controller.stub';
    expect(file_exists($path))->toBeTrue("AuthController stub must exist at $path");

    return file_get_contents($path);
}

describe('PKG-NEW-08 — AuthUser::safeLogoutCurrentToken() helper', function (): void {
    test('AuthUser expone método safeLogoutCurrentToken con firma correcta', function (): void {
        $src = readAuthUserSrc();

        // El método existe con la firma exacta.
        expect($src)
            ->toContain('public function safeLogoutCurrentToken(): bool');

        // Implementa null-safety (no rompe si currentAccessToken retorna null).
        expect($src)
            ->toContain('$token = $this->currentAccessToken();');
        expect($src)
            ->toContain('if ($token === null)');
        expect($src)
            ->toContain('return false;');
        expect($src)
            ->toContain('$token->delete();');
        expect($src)
            ->toContain('return true;');
    });

    test('R-PKG-047 D1: BaseAuthController::logout() usa el helper (no patrón naive) — SSoT migrada', function (): void {
        $basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';
        expect(file_exists($basePath))->toBeTrue("BaseAuthController must exist (R-PKG-047 D1 SSoT)");

        $base = (string) file_get_contents($basePath);

        // D1: el patrón logout() se movió al SSoT (BaseAuthController).
        // El helper `safeLogoutCurrentToken()` se llama desde BaseAuthController::logout()
        // (no desde el stub scaffoldeado).
        if (! preg_match('/public function logout\([^)]*\)[^{]*\{(.*?)\n    \}/s', $base, $matches)) {
            test()->fail('Could not locate logout() method in BaseAuthController.');
        }
        $body = $matches[1];

        expect($body)->toContain('safeLogoutCurrentToken()');
    });

    test('R-PKG-047 D1: stub AuthController es thin wrapper — NO contiene el patrón naive de logout (SSoT migrada)', function (): void {
        $stub = readAuthControllerStub();

        // El thin wrapper NO override logout() — no contiene el patrón
        // naive peligroso (SSoT migrada a BaseAuthController).
        expect($stub)
            ->not->toMatch('/public function logout\(/');
        expect($stub)
            ->not->toContain("\$token = \$user->currentAccessToken();\n        \$token->delete();");
    });
});