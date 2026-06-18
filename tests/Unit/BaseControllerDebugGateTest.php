<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that BaseController::getDebugData was hardened in 1.2.2-hardening
 * to require an authenticated user with `super-admin` or `dev` role before
 * returning EXPLAIN + bindings.
 *
 * Implementation note: source-parsing `getDebugData()`. Three reasons:
 *  1. The method calls `request()->input()`, `DB::getQueryLog()`, and
 *     `DB::select("EXPLAIN ...")` — every one of these needs a booted
 *     Laravel app. Mocking all three in Pest is more fragile than
 *     source-parsing, and matches the audit-driven pattern from sprint
 *     `4r-fixes`.
 *  2. The fix is a STRING contract (3 conditionals in a specific order),
 *     so source-parsing is the right level of abstraction.
 *  3. Source-parsing lets us assert the exact failure modes (no user,
 *     no hasRole method, hasRole returns false) by reading the
 *     conditionals directly.
 *
 * @see audit-2026-06-17-R2-010
 */
uses(MkLaravelTestCase::class);

function baseControllerSourcePath(): string
{
    return __DIR__ . '/../../src/Controllers/BaseController.php';
}

function baseControllerSource(): string
{
    $path = baseControllerSourcePath();
    expect(file_exists($path))->toBeTrue("BaseController.php must exist at $path");

    return (string) file_get_contents($path);
}

function getDebugDataMethodSource(): string
{
    $source = baseControllerSource();

    $start = strpos($source, 'function getDebugData(');
    if ($start === false) {
        return '';
    }
    $bodyStart = strpos($source, '{', $start);
    if ($bodyStart === false) {
        return '';
    }
    $bodyStart++;

    $pattern = '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/';
    $next = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $bodyStart);
    if ($next === 0) {
        $classEnd = strrpos($source, '}');
        return substr($source, $bodyStart, $classEnd - $bodyStart - 1);
    }
    $bodyEnd = $matches[0][0][1];
    return substr($source, $bodyStart, $bodyEnd - $bodyStart);
}

test('getDebugData still gates on _debug=1 query string (regression guard)', function () {
    $body = getDebugDataMethodSource();
    expect($body)->not->toBeEmpty();
    expect($body)->toContain("request()->input('_debug') != 1");
});

test('getDebugData R2-010 gate: returns empty when no user is authenticated', function () {
    $body = getDebugDataMethodSource();
    expect($body)->not->toBeEmpty();

    // The first gate: if `request()->user()` returns null (no auth),
    // getDebugData must return [].
    expect($body)->toContain("request()->user()");
    expect($body)->toMatch('/!\s*\$user/');
    expect($body)->toContain('return []');
});

test('getDebugData R2-010 gate: returns empty when User model has no hasRole method', function () {
    $body = getDebugDataMethodSource();
    expect($body)->not->toBeEmpty();

    // Second gate: if the User model does not implement hasRole, return
    // empty. This is the fail-safe for apps that have not adopted
    // mk-director auth — they get no debug data, not a 500.
    expect($body)->toContain('method_exists($user, \'hasRole\')');
    expect($body)->toContain('return []');
});

test('getDebugData R2-010 gate: returns empty unless user has super-admin or dev role', function () {
    $body = getDebugDataMethodSource();
    expect($body)->not->toBeEmpty();

    // Third gate: even with hasRole available, only `super-admin` and
    // `dev` roles get the debug payload.
    expect($body)->toContain("hasRole('super-admin')");
    expect($body)->toContain("hasRole('dev')");
    expect($body)->toMatch('/!\s*\$\s*user->hasRole\(.super-admin.\)\s*&&\s*!\s*\$\s*user->hasRole\(.dev.\)/');
});

test('getDebugData still returns the debug_queries + debug_summary shape (regression guard)', function () {
    $body = getDebugDataMethodSource();
    expect($body)->not->toBeEmpty();

    // The original payload shape must NOT change — the only thing the
    // hardening sprint adds is the role gate BEFORE the existing logic.
    expect($body)->toContain("'debug_queries'");
    expect($body)->toContain("'debug_summary'");
    expect($body)->toContain("'count'");
    expect($body)->toContain("'total_time_ms'");
});
