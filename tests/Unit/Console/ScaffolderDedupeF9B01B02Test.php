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
 * SKIP los core fields (name, email, password, photo / id, name, email,
 * photo_path, photo_url, auth_scope) que ya están pineados hardcoded en los
 * stubs. Solo pine reglas para profile fields custom.
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

test('R-PKG-046 F9-B01 — buildProfileFieldRules() SKIP core fields (name, email, password, photo)', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldRules(');
    expect($helperPos)->not->toBeFalse();

    $helperBody = substr($src, (int) $helperPos);

    // Helper debe declarar $coreFields array.
    expect($helperBody)->toContain("\$coreFields = ['name', 'email', 'password', 'photo']");

    // Y debe skip esos fields con in_array check.
    expect($helperBody)->toContain("if (in_array(\$key, \$coreFields, true))");
    expect($helperBody)->toContain('continue;');
});

test('R-PKG-046 F9-B02 — buildProfileFieldsToArray() SKIP core fields (id, name, email, photo_path, photo_url, auth_scope)', function () {
    $src = makeAuthUserCommandSource();

    $helperPos = strpos($src, 'protected function buildProfileFieldsToArray(');
    expect($helperPos)->not->toBeFalse();

    $helperBody = substr($src, (int) $helperPos);

    // Helper debe declarar $coreFields array específico del Resource.
    expect($helperBody)->toContain(
        "\$coreFields = ['id', 'name', 'email', 'photo_path', 'photo_url', 'auth_scope']"
    );

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

test('R-PKG-046 F9-B01 — store-admin-request.stub contiene solo 1 línea con key email', function () {
    $stubPath = __DIR__.'/../../../src/Stubs/auth-user/store-admin-request.stub';
    expect(file_exists($stubPath))->toBeTrue();

    $stub = (string) file_get_contents($stubPath);

    // El stub tiene 1 línea con 'email' hardcoded.
    $emailLines = substr_count($stub, "'email' =>");
    expect($emailLines)->toBe(1);  // BC pineado hardcoded, sin duplicar.
});

test('R-PKG-046 F9-B02 — admin-resource.stub contiene solo 1 línea con key email', function () {
    $stubPath = __DIR__.'/../../../src/Stubs/auth-user/admin-resource.stub';
    expect(file_exists($stubPath))->toBeTrue();

    $stub = (string) file_get_contents($stubPath);

    // El stub tiene 1 línea con 'email' hardcoded.
    $emailLines = substr_count($stub, "'email' => \$this->email");
    expect($emailLines)->toBe(1);  // BC pineado hardcoded, sin duplicar.
});