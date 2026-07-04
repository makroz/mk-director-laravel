<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE18-A — Regression guard for orphan-code + extra-brace bug
 * in the scaffolder-generated AuthController.stub's `forgot()` method.
 *
 * Symptom (RETO feedback 2026-07-04, sprint `makromania/2026-07-04-1855--s8-fase6-admin-clean-rebuild`):
 *   - `php artisan route:list` (and `php -l` on the generated AuthController)
 *     fails with `ParseError: syntax error, unexpected variable "$token",
 *     expecting "function"` at line ~370.
 *   - Root cause: `forgot()` had 5 orphan lines + 1 extra `}` between the
 *     early-return block and the `$token = bin2hex(...)` line. The orphan
 *     block was a near-duplicate of the early-return (same return body),
 *     introduced during R-PKG-014 BUG-07 fix → R-PKG-010 RBAC merge.
 *
 * Fix (2026-07-04, R-PKG-NEW proposed):
 *   - Delete the orphan block + extra `}` in the stub.
 *   - Stub: `src/Stubs/auth-user.auth-controller.stub` lines 329-333 removed.
 *
 * This test pins INTENCIÓN (HALLAZGO-NEW-03): the stub MUST NOT contain
 * the orphan pattern. Runtime EFECTIVIDAD is validated in RETO next session
 * (clean rebuild against the patched stub).
 *
 * @see /Users/marioguzman/Desktop/Makromania/.makromania/projects/mk-director/operations/s8-f6-admin-clean-rebuild-result.md § Fix 1
 */
uses(MkLaravelTestCase::class);

$stubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user.auth-controller.stub';

test('HALLAZGO-NEW-FASE18-A — stub forgot() does not contain orphan sendResponse block', function () use ($stubPath) {
    $stub = (string) file_get_contents($stubPath);
    expect($stub)->toBeString();

    // Extract the `forgot()` method body: between `public function forgot(` and
    // the next `public function ` or end-of-class. The regex is tolerant to
    // multi-line signatures.
    if (! preg_match('/public function forgot\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $stub, $matches)) {
        test()->fail('Could not locate forgot() method in stub.');
    }

    $forgotBody = $matches[1];

    // The stub pineá the early-return (when user is null OR scope mismatch
    // OR is_active check fails) and the success-return (after token
    // generation). Each appears exactly ONCE. Pre-fix: the early-return
    // appeared TWICE (the orphan block).
    $sendResponseCount = substr_count($forgotBody, '$this->sendResponse(');

    // We expect at least 2 sendResponse calls: the early-return (user not
    // found) and the success-return (token sent). If the count is higher,
    // the orphan block is back.
    expect($sendResponseCount)
        ->toBeGreaterThanOrEqual(2)
        ->toBeLessThanOrEqual(2, 'forgot() must have exactly 2 sendResponse calls (early-return + success-return). Found orphan block(s).');
});

test('HALLAZGO-NEW-FASE18-A — stub forgot() has balanced braces', function () use ($stubPath) {
    $stub = (string) file_get_contents($stubPath);
    expect($stub)->toBeString();

    if (! preg_match('/public function forgot\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $stub, $matches)) {
        test()->fail('Could not locate forgot() method in stub.');
    }

    $forgotBody = $matches[1];

    // Strip strings to avoid miscount (e.g. `'Si el {loginField} ...'`
    // should not contribute to brace count). Regex approach: remove single-
    // quoted strings and double-quoted strings. This is a heuristic — for
    // the stub we control, the only strings are short.
    $stripped = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/', "''", $forgotBody);
    $stripped = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $stripped);

    // Strip comments (`// ...` and `/* ... */`).
    $stripped = preg_replace('#//[^\n]*#', '', $stripped);
    $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped);

    $open = substr_count($stripped, '{');
    $close = substr_count($stripped, '}');

    expect($open)->toBe($close, "forgot() has unbalanced braces: {$open} `{` vs {$close} `}`");
});

test('HALLAZGO-NEW-FASE18-A — stub forgot() early-return appears before token generation', function () use ($stubPath) {
    $stub = (string) file_get_contents($stubPath);
    expect($stub)->toBeString();

    if (! preg_match('/public function forgot\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $stub, $matches)) {
        test()->fail('Could not locate forgot() method in stub.');
    }

    $forgotBody = $matches[1];

    // The early-return must appear BEFORE the token generation
    // (`$token = bin2hex(random_bytes(32))`). Pre-fix: the orphan block
    // appeared AFTER the early-return AND BEFORE the token generation,
    // creating dead code.
    $earlyReturnPos = strpos($forgotBody, 'Si el {{loginField}} existe');
    $tokenGenPos = strpos($forgotBody, 'bin2hex(random_bytes(32))');

    expect($earlyReturnPos)->not->toBeFalse('Could not find early-return in forgot()');
    expect($tokenGenPos)->not->toBeFalse('Could not find token generation in forgot()');
    expect($earlyReturnPos)
        ->toBeLessThan($tokenGenPos, 'Early-return must appear before token generation in forgot().');
});
