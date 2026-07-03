<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * PKG-NEW-17 source-parsing tests (RETO fase 12 feedback 2026-06-28).
 *
 * Source: `FEEDBACK-TO-MK-DIRECTOR-fase12.md` § PKG-NEW-17.
 *
 * What this sprint pines:
 *   - The scaffolder `MakeAuthUserCommand` no longer emits the
 *     `{{moduleNameLower}}` / `{{moduleNamePluralLower}}` placeholders
 *     LITERALLY in the `$registerRoute` string. They are now interpolated
 *     PHP-side as `{$scopeLower}` and `{$scopePlural}`.
 *   - A trailing `\n` is added after the `;` of the register route so
 *     the next `Route::post('forgot', ...)` in the stub
 *     `auth-user.routes.stub` starts on a new line (cosmético).
 *
 * Pre-fix runtime symptom (RETO feedback):
 *   - `POST /api/{scope}/auth/register` → HTTP 500
 *     `Auth guard [{{moduleNameLower}}] is not defined.`
 *     (InvalidArgumentException).
 *   - Cosmetic: `]);Route::post('forgot',` got glued together in the
 *     generated `routes/api.php`.
 *
 * This file is the source-parsing INTENCIÓN side. The e2e side (running
 * the scaffolder and inspecting the generated routes file) lives in
 * `tests/Feature/MakeAuthUserCommandPKgNew17E2ETest.php`.
 *
 * Spec: R-PKG-031 PKG-NEW-17.
 *
 * @see MakeAuthUserCommand::handle()
 */
uses(MkLaravelTestCase::class);

function readMakeAuthUserCommandSourcePKgNew17(): string
{
    $fullPath = dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($fullPath))->toBeTrue("MakeAuthUserCommand must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readAuthUserRoutesStubPKgNew17(): string
{
    $fullPath = dirname(__DIR__, 3).'/src/Stubs/auth-user.routes.stub';
    expect(file_exists($fullPath))->toBeTrue("auth-user.routes.stub must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('PKG-NEW-17 — registerRoute uses PHP interpolation, not literal placeholders', function (): void {
    $source = readMakeAuthUserCommandSourcePKgNew17();

    test('registerRoute line does NOT contain literal {{moduleNameLower}} placeholder (regression guard)', function () use ($source): void {
        // The bug was: the scaffolder emitted `mk.auth:{{moduleNameLower}}`
        // literally, and the placeholders leaked into the generated routes
        // file because the dynamic PHP string never passed through
        // generateStub()'s str_replace().

        // Pin the exact pattern that was buggy: `->middleware(['mk.auth:{{moduleNameLower}}', ...]`.
        // If a future refactor reintroduces the literal placeholder, this
        // assertion catches it.
        expect($source)->not->toContain("'mk.auth:{{moduleNameLower}}'");
        expect($source)->not->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.create'");
    });

    test('registerRoute line with --with-crud uses PHP interpolation {$scopeLower} and {$scopePlural}', function () use ($source): void {
        // The fix uses `{$scopeLower}` and `{$scopePlural}` (PHP interpolation
        // syntax) so the values are resolved at scaffolder execution time,
        // not by str_replace at stub-write time.

        expect($source)->toContain("'mk.auth:{\$scopeLower}'");
        expect($source)->toContain("'mk.ability:{\$scopeLower}.{\$scopePlural}.create'");
    });

    test('registerRoute ends with `;\\n` (trailing newline for the next Route::post in the stub)', function () use ($source): void {
        // Cosmetic fix: the stub `auth-user.routes.stub` línea 42 has
        // `{{registerRoute}}Route::post('forgot', ...)` with no newline
        // between them. The fix adds a trailing `\n` after the `;` so
        // the next `Route::post(...)` starts on a new line.

        expect($source)->toContain('$registerRoute .= ";\n";');
    });

    test('PKG-NEW-17 reference is documented in source comments (drift trazable per R-G-032)', function () use ($source): void {
        // Drift trazable: any future refactor that removes the PKG-NEW-17
        // comment block will trigger a PR review comment.

        expect($source)->toContain('PKG-NEW-17');
        expect($source)->toContain('R-PKG-031');
    });
});

describe('PKG-NEW-17 — auth-user.routes.stub still references {{registerRoute}} placeholder (no schema change)', function (): void {
    $stub = readAuthUserRoutesStubPKgNew17();

    test('auth-user.routes.stub still uses {{registerRoute}} placeholder', function () use ($stub): void {
        // The stub's contract didn't change — only the dynamic value that
        // gets injected into {{registerRoute}} changed. This is a sanity
        // check that the scaffolder integration is intact.

        expect($stub)->toContain('{{registerRoute}}');
    });

    test('stub has {{registerRoute}} glued to Route::post(\'forgot\', ...) (scaffolder injects the trailing newline)', function () use ($stub): void {
        // The stub line 42 is: `{{registerRoute}}Route::post('forgot', ...)`.
        // The scaffolder's $registerRoute now ends with `;\n` so the
        // concatenation produces: `\n    Route::post('register', ...);\nRoute::post('forgot', ...)`.
        // This test pins the stub contract; if it changes, the scaffolder's
        // trailing newline logic must be re-evaluated.

        // Use string containment instead of regex (regex with `(` `)` `,` `'` is fragile).
        expect($stub)->toContain("{{registerRoute}}Route::post('forgot'");
    });
});
