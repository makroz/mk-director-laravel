<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-014 — `mk:make:auth-user` feedback fixes (v1.6.0-rc4).
 *
 * Pinea cada bug crítico del feedback RETO v1.1:
 *   - BUG-02: model docblock completo (slash-star-star ... star-slash).
 *   - BUG-03: profile fields default validation nullable + --profile-fields-required.
 *   - BUG-04: register() incluye password en validation.
 *   - BUG-09: --profile-fields prefijo bang marca unique.
 *   - BUG-10: storage:link check.
 *
 * **R-PKG-047 D1 (2026-07-09)**: el AuthController scaffoldeado pasó de ~500
 * LOC a un thin wrapper. Los métodos `login()`, `refresh()`, `logout()`,
 * `me()`, `forgot()`, `reset()` viven en `BaseAuthController` (SSoT canónico).
 *
 * **ELIMINADOS post-D1** (estos bugs viven ahora en BaseAuthController, no en
 * el stub scaffoldeado):
 *   - BUG-01: logout() lookup order (la lógica vive en `BaseAuthController::logout()`).
 *   - BUG-05: login() response shape (`BaseAuthController::login()`).
 *   - BUG-06: me() loadMissing (`BaseAuthController::me()`).
 *   - BUG-07: refresh() + reset() + forgot() implementación completa (en
 *     `BaseAuthController::refresh/reset/forgot()`).
 *
 * Los tests pinean que el thin wrapper NO contiene esos métodos (SSoT
 * migrada a BaseAuthController). La EFECTIVIDAD runtime se valida en RETO
 * e2e + tests de integración del BaseAuthController.
 *
 * Lección R-PKG-012: pinear strings críticos evita que bugs runtime se escapen.
 */
uses(MkLaravelTestCase::class);

function packageRoot014(): string
{
    return dirname(__DIR__, 3);
}

function commandSource014(): string
{
    $path = packageRoot014().'/src/Console/Commands/MakeAuthUserCommand.php';

    return (string) file_get_contents($path);
}

function authControllerStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.auth-controller.stub';

    return (string) file_get_contents($path);
}

function baseAuthControllerSource014(): string
{
    $path = packageRoot014().'/src/Auth/Controllers/BaseAuthController.php';

    expect(file_exists($path))->toBeTrue("BaseAuthController must exist at $path (R-PKG-047 D1 SSoT)");

    return (string) file_get_contents($path);
}

function modelStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.model.stub';

    return (string) file_get_contents($path);
}

function migrationStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.migration.stub';

    return (string) file_get_contents($path);
}

// ── BUG-01..07 ELIMINADOS post-R-PKG-047 D1 ──────────────────────────────
//
// Los 7 tests pre-D1 (BUG-01 logout lookup, BUG-05 login shape, BUG-06 me
// loadMissing, BUG-07 refresh/reset/forgot) pineaban features del stub
// VIEJO (~500 LOC). Post-D1, esos métodos viven en `BaseAuthController`
// (SSoT canónico) y el stub es un thin wrapper. El test pineando el SSoT
// vive al final de este archivo (`R-PKG-047 D1: BaseAuthController expone
// los 6 métodos canónicos...`).
//
//
// ── BUG-02 + R-PKG-015 BUG-NEW-11: model docblock completo ─────────────

// ── BUG-02 + R-PKG-015 BUG-NEW-11: model docblock completo ─────────────

test('BUG-02 + R-PKG-015 BUG-NEW-11: model docblock se emite como bloque /** ... */ completo con header', function () {
    $stub = modelStub014();

    // El stub tiene el placeholder {{profileFieldsDocblock}}.
    expect($stub)->toContain('{{profileFieldsDocblock}}');

    // R-PKG-015 BUG-NEW-11: el command ahora emite un bloque docblock con
    //   - header "Profile fields per-scope (R-PKG-011)."
    //   - líneas de @property indentadas con 5 espacios (alineadas con `     *`)
    //   - cierre con `\n     */\n` (newline antes del */)
    $command = commandSource014();

    // Header descriptivo agregado.
    expect($command)->toContain('Profile fields per-scope (R-PKG-011)')
        // Cierre correcto con `     */` + literal `\n` (2 chars) al final del string.
        ->and($command)->toContain('     */\\n')
        // @property lines con 5 espacios de indentación (alineadas con `     *`).
        ->and($command)->toContain('     * @property');
});

// ── BUG-03: profile fields nullable default + --profile-fields-required ──

test('BUG-03 fix: PROFILE_FIELD_TYPES validation default es nullable', function () {
    $command = commandSource014();

    // Buscamos la línea exacta de la validation del tipo 'string'.
    // En el código debería estar: 'validation' => ['nullable', 'string', 'max:255'],
    expect($command)->toContain("'validation' => ['nullable', 'string', 'max:255']");
});

test('BUG-03 fix: PROFILE_FIELD_TYPES NO usa `required` como default para ningún tipo', function () {
    $command = commandSource014();

    // Extraemos el bloque PROFILE_FIELD_TYPES completo.
    if (preg_match('/public const PROFILE_FIELD_TYPES\s*=\s*\[(.*?)\];/s', $command, $matches)) {
        $block = $matches[1];

        // Verificamos que NO hay `'required', 'string', 'max:255']` (default anterior).
        expect($block)->not->toContain("'validation' => ['required', 'string', 'max:255']");
    }
});

test('BUG-03 fix: command signature incluye --profile-fields-required option', function () {
    $command = commandSource014();

    expect($command)->toMatch('/--profile-fields-required=\s*:/');
});

test('BUG-03 fix: command tiene resolveRequiredProfileFields() que valida subset de profile fields', function () {
    $command = commandSource014();

    expect($command)->toContain('protected function resolveRequiredProfileFields');
    // Valida que el field existe en profileFields.
    expect($command)->toContain('no existe en --profile-fields');
});

// ── BUG-04: register() incluye password ──────────────────────────────────

test('BUG-04 fix: rulesPhp del register() incluye password => required', function () {
    $command = commandSource014();

    // mergeRulesPhp() agrega password a las rules.
    expect($command)->toContain("'password' => ['required', 'string', 'min:8', 'max:255']");
});

// ── BUG-05..07 ELIMINADOS post-R-PKG-047 D1 ──────────────────────────────
//
// Ver comment al inicio del archivo. Estos tests pineaban los métodos
// `login()`, `me()`, `refresh()`, `reset()`, `forgot()` del stub VIEJO.
// Post-D1, esos métodos viven en `BaseAuthController` (SSoT canónico).
//
// `buildLoginResponseArray()` también se removió del command (era helper
// interno del scaffolder para el stub VIEJO). El shape canónico `$user`
// vive en `BaseAuthController::login()` ahora.
//
//
// ── BUG-09: --profile-fields prefijo `!` marca unique ─────────────────────

// ── BUG-09: --profile-fields prefijo `!` marca unique ─────────────────────

test('BUG-09 fix: resolveProfileFields detecta prefijo !', function () {
    $command = commandSource014();

    expect($command)->toMatch("/str_starts_with\(\\\$item,\s*'!'\)/");
    expect($command)->toMatch("/\\\$unique\s*=\s*true/");
    expect($command)->toContain("'unique' => \$unique");
});

test('BUG-09 fix: buildProfileFieldsReplacements aplica ->unique()->nullable() cuando unique=true', function () {
    $command = commandSource014();

    expect($command)->toContain('->unique()->nullable()');
});

// ── BUG-10: storage:link check ──────────────────────────────────────────

test('BUG-10 fix: handle() invoca checkStorageLink() al final', function () {
    $command = commandSource014();

    expect($command)->toContain('protected function checkStorageLink');
    expect($command)->toMatch('/\$this->checkStorageLink\(\)/');
});

test('BUG-10 fix: checkStorageLink warn si disk=public y storage no linkeado', function () {
    $command = commandSource014();

    expect($command)->toMatch("/\\\$disk\s*=\s*config\(\s*'mk_director\.storage\.disk',\s*'public'\s*\)/");
    expect($command)->toContain('php artisan storage:link');
});

// ── R-PKG-047 D1: BaseAuthController es SSoT para los 6 métodos canónicos ─

test('R-PKG-047 D1: BaseAuthController expone los métodos canónicos de auth', function () {
    $base = baseAuthControllerSource014();

    // Métodos que el thin wrapper AuthController scaffoldeado hereda
    // sin override. Pinean el SSoT canónico de la lógica de auth post-D1.
    // (Nombres canónicos del BaseAuthController: forgotPassword/resetPassword,
    // no forgot/reset — ver R-PKG-047 D1 naming convention.)
    expect($base)->toContain('public function login(');
    expect($base)->toContain('public function refresh(');
    expect($base)->toContain('public function logout(');
    expect($base)->toContain('public function me(');
    expect($base)->toContain('public function forgotPassword(');
    expect($base)->toContain('public function resetPassword(');
});

test('R-PKG-047 D1: stub AuthController es thin wrapper — NO contiene los métodos inline', function () {
    $stub = authControllerStub014();

    // El thin wrapper NO override los métodos de auth (los hereda de
    // BaseAuthController). Pinea el SSoT migration post-D1.
    expect($stub)->not->toContain('public function login(');
    expect($stub)->not->toContain('public function refresh(');
    expect($stub)->not->toContain('public function logout(');
    expect($stub)->not->toContain('public function me(');
    expect($stub)->not->toContain('public function forgotPassword(');
    expect($stub)->not->toContain('public function resetPassword(');
});
