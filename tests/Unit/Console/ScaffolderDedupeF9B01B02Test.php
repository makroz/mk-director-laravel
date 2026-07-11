<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B01 / F9-B02 — Scaffolder dedup contra core fields.
 *
 * **Bug pineado** (FEEDBACK9, RETO corrida 9):
 * El scaffolder `mk:make:auth-user X --with-crud --profile-fields="!email,phone"`
 * pineaba reglas para `email` DOS veces en el array `rules()` del
 * `Store{Scope}Request` (una hardcoded en el stub + una vía
 * `{{profileFieldsUniqueRules}}`). PHP array merge con key duplicada descarta
 * el primero — el `email` rule + `max:255` se perdían, dejando solo la versión
 * genérica `['required', 'string', 'unique:...,email']`. Resultado: emails
 * inválidos pasaban validación.
 *
 * **Idem para `toArray()` del Resource** (F9-B02): `'email' => $this->email`
 * pineado 2 veces. Output funcionalmente OK (mismo valor), pero código
 * generado inconsistente.
 *
 * **Fix**: `buildProfileFieldRules()` y `buildProfileFieldsToArray()` ahora
 * SKIP los core fields que ya están pineados hardcoded en los stubs. Solo
 * pine reglas para profile fields custom.
 *
 * **FEEDBACK10 (R-PKG-050, Mario 2026-07-10)**: `photo` y `photo_path` ya NO
 * son core fields. Pre-FEEDBACK10, `photo` (request field) y `photo_path`
 * (column/accessor) eran hardcoded en stubs. Post-FEEDBACK10, se generan
 * dinámicamente desde `:file` suffix via `{{fileFields*}}` placeholders.
 *
 * Per HALLAZGO-NEW-03, pinea INTENCIÓN (source-parsing). El package no bootea
 * full Laravel app en unit tests, así que EFECTIVIDAD se valida en el consumer
 * piloto RETO post-merge (smoke test E2E confirma email inválido → 422).
 */
uses(MkLaravelTestCase::class);

function makeAuthUserCommandSource(): string
{
    $path = __DIR__.'/../../../src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('R-PKG-046 F9-B01 — buildProfileFieldRules() SKIP core fields (name, email, password, status)', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldRules(');
    expect($helperPos)->not->toBeFalse();

    $helperBody = substr($src, (int) $helperPos);

    // F10-B18 (R-PKG-050): la dedup incluye 'status' además de los
    // core fields F9-B01 (name, email, password). Pre-fix, con
    // --with-status default ON, el helper pineaba 'status' => ['nullable',
    // 'string'] (rule genérica) Y el helper de status pineaba
    // 'status' => ['sometimes', 'nullable', 'string', Rule::enum(...)] —
    // PHP array merge descartaba el primero y el enum check se perdía.
    //
    // FEEDBACK10: `photo` ya NO está en la dedup list (los file fields
    // se pinean via {{fileFieldsValidationStore/Update}} con rule
    // `['nullable', 'file', 'image', ...]`).
    expect($helperBody)->toContain("\$coreFields = ['name', 'email', 'password', 'status']");

    // Y debe skip esos fields con in_array check.
    expect($helperBody)->toContain("if (in_array(\$key, \$coreFields, true))");
    expect($helperBody)->toContain('continue;');
});

test('R-PKG-046 F9-B02 — buildProfileFieldsToArray() SKIP core fields (id, name, loginField, auth_scope)', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldsToArray(');
    expect($helperPos)->not->toBeFalse();

    $helperBody = substr($src, (int) $helperPos);

    // FEEDBACK10: `photo_path` y `photo_url` ya NO son core fields. El
    // resource stub los genera dinámicamente via {{fileFieldsResourceEntry}}.
    // El nuevo core list es `id, name, loginField, auth_scope` (4 fields
    // pineados hardcoded en el stub).
    expect($helperBody)->toContain("\$coreFields = ['id', 'name', \$loginField, 'auth_scope']");

    // Y debe skip esos fields con in_array check.
    expect($helperBody)->toContain("if (in_array(\$key, \$coreFields, true))");
    expect($helperBody)->toContain('continue;');
});

test('R-PKG-046 F9-B01 — JSDoc de buildProfileFieldRules() explica el bug que pineamos', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldRules(');
    expect($helperPos)->not->toBeFalse();

    $docblockPos = strrpos(substr($src, 0, (int) $helperPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $helperPos - (int) $docblockPos);

    expect($docblock)->toContain('R-PKG-046 F9-B01');
    expect($docblock)->toContain('dedup');
    expect($docblock)->toContain('core fields');
});

test('R-PKG-046 F9-B02 — JSDoc de buildProfileFieldsToArray() explica el bug que pineamos', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldsToArray(');
    expect($helperPos)->not->toBeFalse();

    $docblockPos = strrpos(substr($src, 0, (int) $helperPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $helperPos - (int) $docblockPos);

    expect($docblock)->toContain('R-PKG-046 F9-B02');
    expect($docblock)->toContain('dedup');
    expect($docblock)->toContain('core fields');
});

test('R-PKG-046 F9-B01 + R-PKG-047 D5 — store-admin-request.stub usa {{loginField}} placeholder (1 línea, no hardcoded email)', function () {
    $stubPath = __DIR__.'/../../../src/Stubs/auth-user/store-admin-request.stub';
    expect(file_exists($stubPath))->toBeTrue();

    $stub = (string) file_get_contents($stubPath);

    // R-PKG-046 F9-B01: dedup contra core fields pineados hardcoded.
    // R-PKG-047 D5: el key del login field es `{{loginField}}` placeholder
    // (no 'email' hardcoded). Esto pinea que --login-field=ci regenera
    // correctamente el StoreRequest con key 'ci' en vez de 'email'.
    $loginFieldLines = substr_count($stub, "'{{loginField}}' =>");
    expect($loginFieldLines)->toBe(1);  // BC pineado, sin duplicar.

    // Y NO tiene 'email' hardcoded como key (sería drift vs el placeholder).
    $hardcodedEmailLines = substr_count($stub, "'email' =>");
    expect($hardcodedEmailLines)->toBe(0);
});

test('R-PKG-046 F9-B02 — admin-resource.stub contiene solo 1 línea con key email', function () {
    $stubPath = __DIR__.'/../../../src/Stubs/auth-user/admin-resource.stub';
    expect(file_exists($stubPath))->toBeTrue();

    $stub = (string) file_get_contents($stubPath);

    // El stub tiene 1 línea con 'email' hardcoded.
    $emailLines = substr_count($stub, "'email' => \$this->email");
    expect($emailLines)->toBe(1);  // BC pineado hardcoded, sin duplicar.
});