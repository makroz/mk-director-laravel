<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-009 — `mk:make:auth-user --login-field=<campo>`.
 *
 * Contrato pineado acá:
 *   - El command acepta option `--login-field=<field>` (default `email`).
 *   - El command tiene `resolveLoginField()` que valida el input.
 *   - El command pasa el campo a todos los stubs via `generateStub()`.
 *   - Los stubs tienen los placeholders correctos: `{{loginField}}`,
 *     `{{emailVerifiedAtColumn}}`, `{{emailVerifiedAtCastEntry}}`,
 *     `{{mustVerifyEmailUse}}`, `{{loginFieldValidationRule}}`.
 *   - El stub de model genera `$fillable`, `$loginField` y `$casts` propios
 *     con el campo dinámico (GAP-001 descubierto en design.md).
 *   - BC: sin flag, el comportamiento es idéntico a v1.4.0.
 *
 * Spec: design.md D1-D6.
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot009(): string
{
    return dirname(__DIR__, 3);
}

function commandSource009(): string
{
    $path = packageRoot009().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource009(string $name): string
{
    $path = packageRoot009()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command signature ───────────────────────────────────────────────────

test('command signature includes --login-field option (default email)', function () {
    $source = commandSource009();

    // El option debe estar en la signature con default 'email'.
    expect($source)->toMatch('/--login-field=email\s*:/');
});

test('command has resolveLoginField() that validates input', function () {
    $source = commandSource009();

    expect($source)->toContain('protected function resolveLoginField');
    // Valida caracteres permitidos (PHP identifier style).
    expect($source)->toMatch('/preg_match\(\s*\'\/\\^\[a-zA-Z_\]\[a-zA-Z0-9_\]\*\$\/\'/');
    // Empty fallback a 'email'.
    expect($source)->toMatch('/field === \'\'\s*\)\s*\{\s*return \'email\'/');
});

test('command passes loginField to generateStub via $extraReplacements', function () {
    $source = commandSource009();

    // handle() construye el array con los 5 placeholders condicionales.
    expect($source)->toContain('$extraReplacements');
    expect($source)->toContain("'{{emailVerifiedAtColumn}}'");
    expect($source)->toContain("'{{emailVerifiedAtCastEntry}}'");
    expect($source)->toContain("'{{mustVerifyEmailUse}}'");
    expect($source)->toContain("'{{loginFieldValidationRule}}'");

    // generateStub() acepta $extraReplacements y los aplica via str_replace.
    expect($source)->toContain('array $extraReplacements = []');
    expect($source)->toContain('foreach ($extraReplacements as $placeholder => $value)');
});

// ── Stubs parametrizados ────────────────────────────────────────────────

test('auth-user.model.stub has $fillable + $loginField + $casts parametrized', function () {
    $stub = stubSource009('auth-user.model.stub');

    // GAP-001: el stub debe generar $fillable propio con el campo.
    expect($stub)->toContain("'{{loginField}}'");
    expect($stub)->toContain('protected $fillable = [');

    // $loginField property con placeholder.
    expect($stub)->toContain("protected string \$loginField = '{{loginField}}'");

    // $casts con entry condicional.
    expect($stub)->toContain('{{emailVerifiedAtCastEntry}}');
    expect($stub)->toContain("'password' => 'hashed'");

    // Import condicional de MustVerifyEmail.
    expect($stub)->toContain('{{mustVerifyEmailUse}}');
});

test('auth-user.migration.stub uses {{loginField}} column + conditional email_verified_at', function () {
    $stub = stubSource009('auth-user.migration.stub');

    // Columna principal usa el campo.
    expect($stub)->toContain("\$table->string('{{loginField}}')->unique()");

    // email_verified_at column ahora SIEMPRE se crea (R-PKG-027 PKG-NEW-01 fix).
    // Antes era condicional via {{emailVerifiedAtColumn}} (solo si --verify-email);
    // el cambio evita drift entre el cast del modelo base AuthUser y la migration
    // scaffoldeada. Ver CHANGELOG.md § R-PKG-027 PKG-NEW-01.
    expect($stub)->toContain("\$table->timestamp('email_verified_at')->nullable()");
    expect($stub)->not->toContain('{{emailVerifiedAtColumn}}');

    // Tabla password_reset_tokens usa el campo como primary key.
    expect($stub)->toContain("\$table->string('{{loginField}}')->primary()");
});

test('R-PKG-047 D1: auth-user.auth-controller.stub es thin wrapper que override loginField()', function () {
    $stub = stubSource009('auth-user.auth-controller.stub');

    // Post-D1: el thin wrapper override `loginField()` (abstract del
    // BaseAuthController) retornando el placeholder `{{loginField}}`. El
    // BaseAuthController usa esto dinámicamente en TODOS los endpoints
    // (validation, lookup, error response, etc.) — ver docblock del stub.
    expect($stub)->toMatch('/protected function loginField\(\)\s*:\s*string\s*\{[^}]*return\s*\'\{\{loginField\}\}\'/s');

    // Y pinea el `use AuthUser` que BaseAuthController necesita.
    expect($stub)->toContain('use Mk\\Director\\Auth\\Models\\AuthUser;');
});

test('R-PKG-047 D5: BaseAuthController usa $this->loginField() dinámicamente (no hardcoded)', function () {
    $basePath = packageRoot009().'/src/Auth/Controllers/BaseAuthController.php';
    expect(file_exists($basePath))->toBeTrue();

    $base = (string) file_get_contents($basePath);

    // El BaseAuthController cachea `$loginField = $this->loginField();` al
    // inicio del método y después usa la variable local. Esto pinea que
    // cambiar --login-field=ci regenera correctamente todos los 9 artifacts
    // (D5): la variable se propaga por validation, lookup, error response.
    // En single-quoted PHP, `\$` se mantiene como 2 chars literales
    // (`\` + `$`), y PCRE interpreta `\$` como `$` literal.
    expect($base)->toMatch('/\$loginField\s*=\s*\$this->loginField\(\)/');

    // Y el abstract method está pineado (requerido para que el thin wrapper
    // scaffoldeado lo override).
    expect($base)->toContain('abstract protected function loginField(): string;');
});

// ── BC verification ─────────────────────────────────────────────────────

test('default behavior (sin --login-field) preserva BC con v1.4.0', function () {
    // Verificación estática: el command, sin option, debe pasar 'email'
    // como default y los placeholders condicionales deben resolverse a su
    // versión "email" (no vacía).
    $source = commandSource009();

    // resolveLoginField retorna 'email' cuando input es vacío o ausente.
    expect($source)->toMatch('/\$field === \'\'\s*\)\s*\{\s*return \'email\'/');

    // handle() inicializa $loginField con $this->option('login-field') ?: 'email'
    // (implícito via resolveLoginField default).
    expect($source)->toContain('$this->resolveLoginField((string) $this->option(\'login-field\'))');
});

test('stub files preservan los 5 stubs requeridos (sin romper MakeAuthUserCommand existente)', function () {
    $source = commandSource009();

    // Los 5 stubs deben seguir siendo referenciados (no se eliminó ninguno).
    foreach (['auth-user.model.stub', 'auth-user.migration.stub', 'auth-user.auth-controller.stub', 'auth-user.routes.stub', 'auth-user.service-provider.stub'] as $stub) {
        expect($source)->toContain("'{$stub}'");
    }
});
