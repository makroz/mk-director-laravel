<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-011 — `mk:make:auth-user --verify-email`.
 *
 * Contrato pineado acá:
 *   - El command acepta flag `--verify-email` (boolean, default false).
 *   - El command setea `--verify-email` solo si `--login-field=email`
 *     (R-PKG-011 ADR-009 simplificación). Si loginField != email, ignora
 *     el flag con warning explícito.
 *   - El command pasa la verificación condicional a stubs via replacements:
 *     `{{emailVerifiedAtColumn}}`, `{{emailVerifiedAtCastEntry}}`,
 *     `{{mustVerifyEmailUse}}`, `{{emailVerifyRoutes}}`,
 *     `{{verifiedMiddleware}}`, `{{verifyEmailMethods}}`,
 *     `{{registerVerifyEmailDispatch}}`.
 *   - BC: sin flag, idéntico a v1.5.0-rc4 (sin email_verified_at, sin
 *     verify routes, sin notification).
 *
 * Spec: design.md ADR-009.
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function commandSource011Ve(): string
{
    $path = dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource011Ve(string $name): string
{
    $path = dirname(__DIR__, 3)."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command signature ───────────────────────────────────────────────────

test('command signature includes --verify-email flag', function () {
    $source = commandSource011Ve();

    expect($source)->toMatch('/--verify-email\s*:/');
});

test('handle() implementa ADR-009: --verify-email solo aplica si loginField=email', function () {
    $source = commandSource011Ve();

    // handle() tiene el guard explícito.
    expect($source)->toMatch('/\$verifyEmail = \$verifyEmailRequested && \$isEmail/');
    expect($source)->toContain('--verify-email ignorado: solo aplica cuando --login-field=email');
});

test('handle() construye buildVerifyEmailReplacements condicionalmente', function () {
    $source = commandSource011Ve();

    expect($source)->toContain('protected function buildVerifyEmailReplacements');
    // Los placeholders de verification.
    expect($source)->toContain('{{emailVerifyRoutes}}');
    expect($source)->toContain('{{verifiedMiddleware}}');
    expect($source)->toContain('{{verifyEmailMethods}}');
    expect($source)->toContain('{{registerVerifyEmailDispatch}}');
});

// ── Refactor: email_verified_at ahora depende de --verify-email, no de $isEmail ──

test('{{emailVerifiedAtColumn}} depende de --verify-email (no de login-field=email)', function () {
    $source = commandSource011Ve();

    // En loginFieldReplacements, emailVerifiedAtColumn usa $verifyEmail, no $isEmail.
    expect($source)->toMatch("/'\\{\\{emailVerifiedAtColumn\\}\\}' => \\\$verifyEmail/");
});

test('{{emailVerifiedAtCastEntry}} depende de --verify-email', function () {
    $source = commandSource011Ve();

    expect($source)->toMatch("/'\\{\\{emailVerifiedAtCastEntry\\}\\}' => \\\$verifyEmail/");
});

test('{{mustVerifyEmailUse}} depende de --verify-email', function () {
    $source = commandSource011Ve();

    expect($source)->toMatch("/'\\{\\{mustVerifyEmailUse\\}\\}' => \\\$verifyEmail/");
});

// ── Stubs parametrizados ────────────────────────────────────────────────

test('auth-user.routes.stub tiene placeholder para email verify routes', function () {
    $stub = stubSource011Ve('auth-user.routes.stub');

    expect($stub)->toContain('{{emailVerifyRoutes}}');
    // El placeholder está después de reset y antes del grupo protegido.
    // Verificar orden: "{{rbacResetThrottle}}" seguido de "}}" + newline + "{{emailVerifyRoutes}}".
    $resetPos = strpos($stub, '{{rbacResetThrottle}}');
    $verifyPos = strpos($stub, '{{emailVerifyRoutes}}');
    expect($verifyPos)->toBeGreaterThan($resetPos);
});

test('auth-user.routes.stub tiene placeholder para verified middleware', function () {
    $stub = stubSource011Ve('auth-user.routes.stub');

    expect($stub)->toContain('{{verifiedMiddleware}}');
});

test('auth-user.auth-controller.stub tiene placeholder para verifyEmail methods', function () {
    $stub = stubSource011Ve('auth-user.auth-controller.stub');

    expect($stub)->toContain('{{verifyEmailMethods}}');
});

// ── Verification routes details ─────────────────────────────────────────

test('buildVerifyEmailReplacements genera signed URL para /email/verify/{id}/{hash}', function () {
    $source = commandSource011Ve();

    expect($source)->toContain("->middleware('signed')");
    expect($source)->toContain("'email/verify/{id}/{hash}'");
    // R-PKG-031 PKG-NEW-17 fix (v1.7.1-rc1): PHP interpolation `{$scopeLower}` en lugar
    // del literal `{{moduleNameLower}}` (que el consumer tenía que `sed`-ar con
    // R-AD-020 workaround, ahora absorbido). El stub generado usa `{$scopeLower}`
    // como PHP variable de la heredoc.
    //
    // Escape `\$scopeLower` para pinear el STRING LITERAL del comando source,
    // no la interpolación PHP (variables undefined en este scope del test).
    expect($source)->toContain("name('{\$scopeLower}.auth.verify')");
});

test('buildVerifyEmailReplacements genera throttle 6,1 para /email/resend', function () {
    $source = commandSource011Ve();

    expect($source)->toContain("->middleware('throttle:6,1')");
    expect($source)->toContain("'email/resend'");
});

// ── verifyEmail() method details ────────────────────────────────────────

test('verifyEmail() valida firma con hash_equals (timing-safe)', function () {
    $source = commandSource011Ve();

    expect($source)->toContain('hash_equals');
    expect($source)->toContain('getKey');
    expect($source)->toContain('getEmailForVerification');
    expect($source)->toContain('markEmailAsVerified');
});

test('resendVerification() valida si ya verificado y dispatch notification', function () {
    $source = commandSource011Ve();

    expect($source)->toContain('hasVerifiedEmail');
    expect($source)->toContain('sendEmailVerificationNotification');
});

// ── BC verification ─────────────────────────────────────────────────────

test('default mode (sin --verify-email) tiene verify placeholders como string vacío', function () {
    $source = commandSource011Ve();

    // buildVerifyEmailReplacements retorna array con strings vacíos si !enabled.
    expect($source)->toContain("'{{emailVerifyRoutes}}' => ''");
    expect($source)->toContain("'{{verifiedMiddleware}}' => ''");
    expect($source)->toContain("'{{verifyEmailMethods}}' => ''");
    expect($source)->toContain("'{{registerVerifyEmailDispatch}}' => ''");
});