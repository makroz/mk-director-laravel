<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-011 — `mk:make:auth-user --profile-fields=<csv>`.
 *
 * Contrato pineado acá:
 *   - El command acepta option `--profile-fields=<csv>` (default null/empty).
 *   - El command tiene `resolveProfileFields()` que valida el CSV.
 *   - El command pasa los profile fields a todos los stubs via replacements.
 *   - Los stubs tienen los placeholders correctos: `{{profileFieldsColumns}}`,
 *     `{{profileFieldsFillableEntries}}`, `{{profileFieldsCastEntries}}`,
 *     `{{profileFieldsDocblock}}`, `{{registerRoute}}`, `{{registerMethod}}`,
 *     `{{updateProfileRoute}}`, `{{updateProfileMethod}}`.
 *   - BC: sin flag, el comportamiento es idéntico a v1.5.0-rc4.
 *   - Ortogonalidad: --profile-fields es combinable con --login-field,
 *     --with-auth-rbac, y --verify-email en cualquier subconjunto.
 *
 * Spec: design.md D1-D10.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot011Pf(): string
{
    return dirname(__DIR__, 3);
}

function commandSource011Pf(): string
{
    $path = packageRoot011Pf().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource011Pf(string $name): string
{
    $path = packageRoot011Pf()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command signature ───────────────────────────────────────────────────

test('command signature includes --profile-fields option', function () {
    $source = commandSource011Pf();

    expect($source)->toMatch('/--profile-fields=\s*:/');
});

test('command has resolveProfileFields() that validates input', function () {
    $source = commandSource011Pf();

    expect($source)->toContain('protected function resolveProfileFields');
    // Valida caracteres PHP identifier.
    expect($source)->toMatch('/preg_match\(\s*\'\/\\^\[a-zA-Z_\]\[a-zA-Z0-9_]\*\$\/\'/');
    // Empty fallback a [] (BC).
    expect($source)->toMatch('/\$raw === \'\'\s*\)\s*\{\s*return \[\]/');
    // Detecta duplicados.
    expect($source)->toContain('duplicado en --profile-fields');
});

test('command has buildProfileFieldsReplacements() that produces 8 placeholders', function () {
    $source = commandSource011Pf();

    expect($source)->toContain('protected function buildProfileFieldsReplacements');
    // Los 8 placeholders condicionales.
    foreach ([
        '{{profileFieldsFillableEntries}}',
        '{{profileFieldsCastEntries}}',
        '{{profileFieldsColumns}}',
        '{{profileFieldsDocblock}}',
        '{{registerRoute}}',
        '{{registerMethod}}',
        '{{updateProfileRoute}}',
        '{{updateProfileMethod}}',
    ] as $placeholder) {
        expect($source)->toContain($placeholder);
    }
});

test('handle() merges profile fields + rbac + login field + verify email replacements', function () {
    $source = commandSource011Pf();

    // handle() hace array_merge con los 4 sets de replacements.
    expect($source)->toMatch('/array_merge\(\s*\$loginFieldReplacements,\s*\$rbacReplacements,\s*\$profileFieldsReplacements,\s*\$verifyEmailReplacements/');
});

// ── Stubs parametrizados ────────────────────────────────────────────────

test('auth-user.model.stub has profile fields placeholders', function () {
    $stub = stubSource011Pf('auth-user.model.stub');

    // $fillable entries condicionales.
    expect($stub)->toContain('{{profileFieldsFillableEntries}}');
    // $casts entries condicionales.
    expect($stub)->toContain('{{profileFieldsCastEntries}}');
    // Docblock @property entries.
    expect($stub)->toContain('{{profileFieldsDocblock}}');
});

test('auth-user.migration.stub has profile fields columns placeholder', function () {
    $stub = stubSource011Pf('auth-user.migration.stub');

    expect($stub)->toContain('{{profileFieldsColumns}}');
});

test('auth-user.auth-controller.stub has register + updateProfile + verifyEmail placeholders', function () {
    $stub = stubSource011Pf('auth-user.auth-controller.stub');

    expect($stub)->toContain('{{registerMethod}}');
    expect($stub)->toContain('{{updateProfileMethod}}');
    expect($stub)->toContain('{{verifyEmailMethods}}');
});

test('auth-user.routes.stub has register + updateProfile + verify routes placeholders', function () {
    $stub = stubSource011Pf('auth-user.routes.stub');

    expect($stub)->toContain('{{registerRoute}}');
    expect($stub)->toContain('{{updateProfileRoute}}');
    expect($stub)->toContain('{{emailVerifyRoutes}}');
    expect($stub)->toContain('{{verifiedMiddleware}}');
});

// ── Reserved column validation ──────────────────────────────────────────

test('resolveProfileFields rejects reserved columns (id, password, auth_scope, etc.)', function () {
    $source = commandSource011Pf();

    expect($source)->toContain("'id'");
    expect($source)->toContain("'password'");
    expect($source)->toContain("'auth_scope'");
    expect($source)->toContain("'email_verified_at'");
    expect($source)->toContain("'remember_token'");
    // Mensaje específico.
    expect($source)->toContain('colisiona con columna reservada o con --login-field');
});

test('resolveProfileFields rejects collision with login field', function () {
    $source = commandSource011Pf();

    // El método acepta $loginField como segundo param y lo agrega a $reserved.
    expect($source)->toMatch('/protected function resolveProfileFields\(\s*string \$raw,\s*string \$loginField/');
});

// ── BC verification ─────────────────────────────────────────────────────

test('default behavior (sin --profile-fields) preserva BC con v1.5.0-rc4', function () {
    $source = commandSource011Pf();

    // resolveProfileFields retorna [] cuando input es vacío.
    expect($source)->toMatch('/\$raw === \'\'\s*\)\s*\{\s*return \[\]/');
});

test('register() solo se genera si hay --profile-fields o --verify-email', function () {
    $source = commandSource011Pf();

    // handle() tiene la condición explícita.
    expect($source)->toMatch('/if \(\! empty\(\$profileFields\) \|\| \$verifyEmail\)/');
});

test('updateProfile() solo se genera si hay --profile-fields', function () {
    $source = commandSource011Pf();

    // handle() tiene la condición explícita.
    expect($source)->toMatch('/if \(\! empty\(\$profileFields\)\)/');
});

// ── Ortogonalidad con otros flags ───────────────────────────────────────

test('--profile-fields es ortogonal con --login-field y --with-auth-rbac', function () {
    $source = commandSource011Pf();

    // La signature tiene los 4 flags como opciones independientes.
    expect($source)->toContain('--login-field=');
    expect($source)->toContain('--with-auth-rbac');
    expect($source)->toContain('--profile-fields=');
    expect($source)->toContain('--verify-email');
});