<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B05 — mk:auth:create-super-admin soporta login field dinámico.
 *
 * **Bug pineado** (FEEDBACK9, RETO corrida 9):
 * Pre-fix, el command pineaba hardcoded `email` en signature, validación y
 * `where()`. Si el consumer ejecutó `mk:make:auth-user Admin --login-field=ci`,
 * el modelo `Admin::$loginField = 'ci'`, pero el command pedía `--email` y
 * buscaba por `where('email', ...)`. Workaround consumer-side (RETO): tinker
 * manual con `firstOrCreate(['ci' => ...])`.
 *
 * **Fix**: el command detecta `$admin->getLoginField()` (default `'email'`,
 * override `'ci'`, etc.) y:
 *   - `configure()` agrega `--{loginField}` dinámicamente via Symfony
 *     InputOption si difiere del BC `--email`.
 *   - `resolveLoginFieldValue()` lee el flag dinámico o fallback a `--email`.
 *   - Validación dinámica: `FILTER_VALIDATE_EMAIL` solo si `loginField='email'`.
 *   - `where()` dinámico: `where($this->loginField, $loginFieldValue)`.
 *   - `create()` solo pinea el login field value (no email hardcoded).
 *   - Tabla final muestra el campo correcto.
 *   - Output de "Login:" usa `{loginField}`.
 *
 * Per HALLAZGO-NEW-03, pinea INTENCIÓN (source-parsing). EFECTIVIDAD se valida
 * en el consumer piloto RETO post-merge (smoke test E2E con `--ci`).
 */
uses(MkLaravelTestCase::class);

function authCreateSuperAdminSourceF9B05(): string
{
    $path = __DIR__.'/../../../src/Console/Commands/AuthCreateSuperAdminCommand.php';
    expect(file_exists($path))->toBeTrue("AuthCreateSuperAdminCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('R-PKG-046 F9-B05 — command detecta $admin->getLoginField() dinámicamente', function () {
    $src = authCreateSuperAdminSourceF9B05();

    // handle() debe leer el loginField del modelo Admin via getLoginField().
    expect($src)->toContain(
        "\$this->loginField = (new \$adminModel)->getLoginField()",
    );
});

test('R-PKG-046 F9-B05 — configure() agrega --{loginField} dinámicamente via Symfony InputOption', function () {
    $src = authCreateSuperAdminSourceF9B05();

    expect($src)->toContain('protected function configure(): void');

    // configure() debe usar Symfony InputOption para agregar el flag dinámico.
    expect($src)->toContain('Symfony\\Component\\Console\\Input\\InputOption');

    // Solo agregar el flag si loginField != email (BC fallback).
    expect($src)->toContain("if (\$loginField !== 'email')");
});

test('R-PKG-046 F9-B05 — resolveLoginFieldValue() fallback chain dinámico', function () {
    $src = authCreateSuperAdminSourceF9B05();

    expect($src)->toContain('protected function resolveLoginFieldValue(): string');

    // Helper debe buscar --{loginField} primero.
    expect($src)->toContain("\$dynamicFlag = \$this->option(\$this->loginField)");

    // BC fallback para --email cuando loginField='email'.
    expect($src)->toContain("if (\$this->loginField === 'email')");

    // Prompt interactivo pineado con el nombre del login field.
    expect($src)->toContain('ask("{$this->loginField} del super-admin")');
});

test('R-PKG-046 F9-B05 — where() dinámico respeta loginField', function () {
    $src = authCreateSuperAdminSourceF9B05();

    expect($src)->toContain(
        'where($this->loginField, $loginFieldValue)->exists()',
    );

    // Y la advertencia de idempotencia usa el nombre del field correcto.
    expect($src)->toContain('Ya existe un admin con {$this->loginField}');
});

test('R-PKG-046 F9-B05 — create() pine el loginField value dinámicamente (no email hardcoded)', function () {
    $src = authCreateSuperAdminSourceF9B05();

    expect($src)->toContain(
        "\$createAttrs = [",
    );

    // Solo pine el loginField value, NO 'email' hardcoded.
    expect($src)->toContain(
        "\$this->loginField => \$loginFieldValue,",
    );
});

test('R-PKG-046 F9-B05 — validación dinámica según loginField', function () {
    $src = authCreateSuperAdminSourceF9B05();

    // Validación de email solo si loginField='email'.
    expect($src)->toContain(
        "if (\$this->loginField === 'email')",
    );
    expect($src)->toContain('filter_var($loginFieldValue, FILTER_VALIDATE_EMAIL)');

    // Validación string + min para ci, username, etc.
    expect($src)->toContain('strlen($loginFieldValue) < 3');
});

test('R-PKG-046 F9-B05 — output de tabla + Login: usa loginField dinámico', function () {
    $src = authCreateSuperAdminSourceF9B05();

    // Tabla final muestra el field name correcto.
    expect($src)->toContain("\$admin->{\$this->loginField}");

    // Output de Login: usa loginField dinámico en el body del JSON.
    // Source contiene: '  { "'.$this->loginField.'": "'.$loginFieldValue.'", ...'
    expect($src)->toContain("\$loginFieldValue.'\", \"password\"");
});