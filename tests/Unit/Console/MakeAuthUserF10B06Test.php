<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK10 F10-B06 — Rutas auth referenciaban métodos inexistentes en
 * `BaseAuthController` → 500.
 *
 * **Root cause #1**: `auth-user.routes.stub` apuntaba a `AuthController::forgot`
 * y `AuthController::reset`, pero `BaseAuthController` (SSoT canónico, R-PKG-047
 * D1) expone `forgotPassword()`/`resetPassword()` — nombres distintos.
 * `Call to undefined method` → 500 en cualquier scope scaffoldeado.
 *
 * **Root cause #2**: faltaban las rutas de `logoutAll()` y `changePassword()`
 * (ya implementados en `BaseAuthController` pero nunca expuestos por el
 * scaffolder).
 *
 * **Root cause #3**: `{{registerMethod}}`/`{{updateProfileMethod}}` — el
 * código PHP de `register()`/`updateProfile()` (construido por
 * `buildRegisterMethod()`/`buildUpdateProfileMethod()`, condicionado a
 * `--profile-fields`/`--verify-email`) se computaba pero NUNCA se insertaba
 * en ningún stub — los placeholders no existían en
 * `auth-user.auth-controller.stub`. Cuando `--profile-fields` estaba activo,
 * `{{updateProfileRoute}}` pineaba `PATCH me → updateProfile`, pero el método
 * jamás se emitía en el controller generado → `Call to undefined method` (500).
 *
 * Fix: (a) alinear routes stub a los nombres reales + paths `password/*` +
 * agregar logout-all/password-change; (b) wire `{{registerMethod}}` +
 * `{{updateProfileMethod}}` al final de `auth-user.auth-controller.stub`.
 */
uses(MkLaravelTestCase::class);

function packageRootF10B06(): string
{
    return dirname(__DIR__, 3);
}

function routesStubF10B06(): string
{
    return (string) file_get_contents(packageRootF10B06().'/src/Stubs/auth-user.routes.stub');
}

function authControllerStubF10B06(): string
{
    return (string) file_get_contents(packageRootF10B06().'/src/Stubs/auth-user.auth-controller.stub');
}

function baseAuthControllerSourceF10B06(): string
{
    return (string) file_get_contents(packageRootF10B06().'/src/Auth/Controllers/BaseAuthController.php');
}

test('F10-B06: auth-user.routes.stub apunta a forgotPassword/resetPassword (NO forgot/reset)', function () {
    $stub = routesStubF10B06();

    expect($stub)->not->toContain("'forgot']");
    expect($stub)->not->toContain("'reset']");
    expect($stub)->toContain("[AuthController::class, 'forgotPassword']");
    expect($stub)->toContain("[AuthController::class, 'resetPassword']");
});

test('F10-B06: cada método referenciado en el routes stub existe en BaseAuthController', function () {
    $stub = routesStubF10B06();
    $base = baseAuthControllerSourceF10B06();

    // Extraer todos los métodos referenciados como [AuthController::class, 'x'].
    preg_match_all('/\[AuthController::class,\s*\'(\w+)\'\]/', $stub, $matches);
    expect($matches[1])->not->toBeEmpty();

    // updateProfile/register son inyectados condicionalmente per-scope
    // (via {{updateProfileMethod}}/{{registerMethod}}), no viven en
    // BaseAuthController — se excluyen de este chequeo.
    $scopeInjected = ['updateProfile', 'register'];

    foreach (array_unique($matches[1]) as $method) {
        if (in_array($method, $scopeInjected, true)) {
            continue;
        }

        expect($base)->toMatch(
            '/function\s+'.preg_quote($method, '/').'\s*\(/',
            "El método '{$method}' referenciado en auth-user.routes.stub NO existe en BaseAuthController",
        );
    }
});

test('F10-B06: routes stub expone logout-all y password/change (previamente faltantes)', function () {
    $stub = routesStubF10B06();

    expect($stub)->toContain("[AuthController::class, 'logoutAll']");
    expect($stub)->toContain("[AuthController::class, 'changePassword']");
});

test('F10-B06: auth-user.auth-controller.stub wirea {{registerMethod}} y {{updateProfileMethod}} (pre-fix: computados pero nunca insertados)', function () {
    $stub = authControllerStubF10B06();

    expect($stub)->toContain('{{registerMethod}}');
    expect($stub)->toContain('{{updateProfileMethod}}');
});
