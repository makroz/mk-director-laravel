<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE18-B — Regression guard for `me/permissions` route
 * being injected OUTSIDE the `mk.auth:{scope}` middleware group.
 *
 * Symptom (RETO feedback 2026-07-04, sprint `makromania/2026-07-04-1855--s8-fase6-admin-clean-rebuild`):
 *   - `GET /api/admin/auth/me/permissions` returned HTTP 500
 *     `Call to a member function loadMissing() on null` in
 *     MePermissionsController.
 *   - Root cause: pre-fix, the route was injected at the FILE ROOT as
 *     `Route::get('me/permissions', ...)` (no prefix, no middleware), so
 *     `mk.auth:admin` middleware was not applied and `$request->user()`
 *     returned null in the controller.
 *
 * Fix already merged (R-PKG-043 HALLAZGO-NEW-FASE19-02, commit `99084ad`,
 * PR #49 → dev, post `makromania/260701-1030--r-pkg-043-fase19-feedback-fixes`):
 *   - `MakeAuthUserCommand::generatePermissionsEndpoint()` uses a regex
 *     to inject the route AFTER `Route::get('me', ...)` and BEFORE the
 *     closing `});` of the `mk.auth:{scope}` middleware group.
 *   - 3-strategy `use` statement injection (primary/fallback/last-resort).
 *   - Fallback path: if `Route::get('me', ...)` is not found (consumer
 *     customized the file), append a SECOND group with explicit prefix +
 *     middleware + warning to consumer.
 *
 * This test pins INTENCIÓN (HALLAZGO-NEW-03). Runtime EFECTIVIDAD is
 * validated in RETO next session (clean rebuild against the patched code).
 *
 * Strategy: extract method body once, then check for specific tokens with
 * substring/regex matches that survive PHP string-literal escape quirks
 * (the source uses `\\` for backslashes inside double-quoted strings).
 *
 * @see /Users/marioguzman/Desktop/Makromania/.makromania/projects/mk-director/operations/s8-f6-admin-clean-rebuild-result.md § Fix 2
 * @see commit 99084ad in makroz/mk-director-laravel
 */
uses(MkLaravelTestCase::class);

$scaffolderPath = dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php';

/**
 * Extract the body of a named method from the scaffolder source by name.
 * Returns the substring INCLUDING the opening `{` and the matched closing `}`.
 * Falls the test if the method is not found.
 */
function extractMethodBodyFromScaffolder(string $methodName): string
{
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php');

    // Pattern: `function {name}(...) { ... }` — match the body greedily
    // until the first `    }` at column 4 (class indent level).
    $pattern = '/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)\s*:[^{]*\{(.*?)\n    \}/s';

    if (! preg_match($pattern, $source, $matches)) {
        test()->fail("Could not locate method {$methodName}() in scaffolder source.");
    }

    return $matches[0];
}

test('HALLAZGO-NEW-FASE18-B — scaffolder body contains the canonical me/permissions route line', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // The literal route line `Route::get('me/permissions', [MePermissionsController::class, 'show']);`
    // MUST appear in the method body. Use the literal substring as it
    // appears in PHP source (no double-backslash escape needed because
    // there are no backslashes in this string).
    expect($body)
        ->toContain("Route::get('me/permissions', [MePermissionsController::class, 'show']);");
});

test('HALLAZGO-NEW-FASE18-B — scaffolder injects me/permissions AFTER Route::get(me)', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // The scaffolder uses `preg_match` against a pattern that matches
    // `Route::get('me', ...)` as an anchor. The literal `$meRoutePattern`
    // variable name MUST exist (otherwise the injection is not anchored).
    expect($body)
        ->toContain('$meRoutePattern')
        ->toContain("'me'")               // the anchored route name
        ->toContain('preg_match')
        ->toContain('preg_replace');
});

test('HALLAZGO-NEW-FASE18-B — scaffolder primary use-strategy: insert MePermissionsController use statement', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // Primary strategy: detect existing `use ...AuthController;` and
    // append `use ...MePermissionsController;` via str_replace.
    // We don't assert the exact `use App\\Modules\\...` literal because
    // PHP string-literal escape rules (single vs double backslashes) make
    // exact substring matching fragile; instead we assert the three
    // anchors: the MePermissionsController class reference, the
    // str_replace call, and the AuthController anchor.
    expect($body)
        ->toContain('MePermissionsController')
        ->toContain('str_replace(')
        ->toContain('AuthController');
});

test('HALLAZGO-NEW-FASE18-B — scaffolder fallback: explicit prefix+middleware group when regex misses', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // Fallback when `Route::get('me', ...)` is not found: append a new
    // `Route::prefix(...)->middleware('mk.auth:{scopeLower}')->group(...)`
    // block. Look for the literal token `Route::prefix` followed by the
    // `api/{scopeLower}/auth` prefix and `mk.auth:{scopeLower}` middleware.
    expect($body)
        ->toContain('Route::prefix(')
        ->toContain('api/')
        ->toContain('/auth')
        ->toContain('mk.auth:');
});

test('HALLAZGO-NEW-FASE18-B — scaffolder fallback warns the consumer', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // Fallback MUST warn the consumer that the route was appended
    // outside the canonical group. Look for `$this->warn(` and the
    // Spanish hint "No se encontr" (literal substring in source).
    expect($body)
        ->toContain('$this->warn(')
        ->toContain('No se encontr')
        ->toContain('Verificar manualmente');
});

test('HALLAZGO-NEW-FASE18-B — scaffolder idempotency: skip if me/permissions already injected', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // The scaffolder checks `if (str_contains($routesContent, 'me/permissions'))`
    // before injecting. Look for the literal check + skip message. NOTE: the
    // string is single-quoted to match the project's Pint `single_quote`
    // standard (the source is kept pint-clean).
    expect($body)
        ->toContain("str_contains(\$routesContent, 'me/permissions')")
        ->toContain('Route ya existe, skipping me/permissions');
});

test('HALLAZGO-NEW-FASE18-B — scaffolder injects me/permissions route with route call to MePermissionsController::class show', function () {
    $body = extractMethodBodyFromScaffolder('generatePermissionsEndpoint');

    // Combined assertion: the route call must target MePermissionsController::class
    // and method 'show'. Pre-fix, the route was emitted WITHOUT the controller
    // class (route was bound to a closure-less handler that crashed at runtime).
    expect($body)
        ->toMatch('/Route::get\([^)]*me\/permissions[^)]*MePermissionsController::class[^)]*show[^)]*\)/s');
});
