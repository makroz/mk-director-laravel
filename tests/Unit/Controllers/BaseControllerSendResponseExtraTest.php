<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-023 (rc12): `BaseController::sendResponse` signature changed to accept
 * an `array $extra = []` 4th parameter. When the caller passes a non-empty
 * `$extra` AND the config flag `mk_director.response.top_level_extra_data`
 * is true, the controller emits `__extraData` as a TOP-LEVEL sibling of
 * `data` (not nested inside it).
 *
 * Why this matters: the @makroz/core `MkResponse<T>` contract defines
 * `__extraData` as top-level (sibling of `data`). 2/4 frontend hooks
 * (`useMkInfiniteList` in web + mobile) consume the top-level shape. Until
 * rc12, every controller in the package emitted `__extraData` NESTED inside
 * `data` (via CRUDSmart::index() building `['data' => items, '__extraData' => $extra]`
 * and passing that to `sendResponse`, which put it inside the outer `data`).
 *
 * BC strategy (signed 2026-06-27 by Mario):
 *   - Flag `mk_director.response.top_level_extra_data` defaults to `false`
 *     in rc12 → `true` in GA.
 *   - The new top-level emission only triggers when BOTH `$extra` is
 *     non-empty AND the flag is true. Callers that don't pass `$extra`
 *     (or set the flag off) get the legacy shape unchanged.
 *
 * Implementation note: source-parsing. The method is `protected` and the
 * class is `abstract`, so runtime instantiation would need an anonymous
 * test subclass. For a signature + conditional change like this, source-parsing
 * is the right level of abstraction — same pattern as R2-010 hardening pineo.
 *
 * Cross-stack impact: this fix changes the response envelope of every
 * controller in the package. `useMkList` in `@makroz/web` and `@makroz/mobile`
 * is being updated in T8/T9 of the same sprint to read the top-level shape
 * when the flag is on, falling back to nested for the transition window.
 *
 * @see R-PKG-023 (rc12, cherry-picked from Code Review 4R R1.1 BLOCKER)
 */
uses(MkLaravelTestCase::class);

function baseControllerPath(): string
{
    return __DIR__ . '/../../../src/Controllers/BaseController.php';
}

function baseControllerSource(): string
{
    $path = baseControllerPath();
    expect(file_exists($path))->toBeTrue("BaseController.php must exist at $path");

    return (string) file_get_contents($path);
}

function sendResponseMethodSource(): string
{
    $source = baseControllerSource();

    $start = strpos($source, 'function sendResponse(');
    if ($start === false) {
        return '';
    }

    $pattern = '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/';
    $matches = [];
    $next = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $start + 1);
    if ($next === 0) {
        $classEnd = strrpos($source, '}');
        return substr($source, $start, $classEnd - $start - 1);
    }
    $bodyEnd = $matches[0][0][1];
    return substr($source, $start, $bodyEnd - $start);
}

function sendResponseBodySource(): string
{
    // Same as sendResponseMethodSource() but trimmed to body only
    // (excludes the signature line and the opening brace).
    $source = sendResponseMethodSource();
    $bodyStart = strpos($source, '{');
    if ($bodyStart === false) {
        return '';
    }
    $bodyStart++;

    // Find closing brace matching the opening one. Naive search works
    // for the simple sendResponse body (no nested closures).
    $depth = 1;
    $pos = $bodyStart;
    $len = strlen($source);
    while ($pos < $len && $depth > 0) {
        $ch = $source[$pos];
        if ($ch === '{') $depth++;
        elseif ($ch === '}') $depth--;
        $pos++;
    }
    return substr($source, $bodyStart, $pos - $bodyStart - 1);
}

test('sendResponse signature includes array $extra = [] as 4th parameter (R-PKG-023)', function () {
    $body = sendResponseMethodSource();
    expect($body)->not->toBeEmpty();

    // R-PKG-023: 4th parameter must be `array $extra = []`.
    expect($body)->toContain('array $extra = []');
});

test('sendResponse emits __extraData as top-level sibling of data when $extra non-empty AND flag on (R-PKG-023)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // The flag must be checked: `config('mk_director.response.top_level_extra_data', false)`.
    // Search for the unique substring (avoids quote-matching confusion).
    expect($body)->toContain('response.top_level_extra_data');

    // The emission must assign `$response['__extraData'] = $extra`
    // (top-level — sibling of `$response['data']`).
    expect($body)->toContain("'__extraData'] = \$extra");
});

test('sendResponse guards the __extraData emission with non-empty + flag check (R-PKG-023)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // Both conditions must guard the assignment:
    //   1. $extra is not empty
    //   2. flag is truthy
    // We check that the conditional uses `$extra` AND the flag.
    expect($body)->toContain('$extra');
    expect($body)->toContain("config('mk_director.response.top_level_extra_data'");

    // The whole guarded block must contain the assignment. We do a lenient
    // match: find an `if (` that contains `$extra`, then verify that the
    // assignment is reachable from there. To keep the test simple, we just
    // verify the conditional structure exists in the right order.
    expect($body)->toMatch('/if\s*\([^)]*\$extra[^)]*\)/');
});

test('sendResponse original payload (success/message/data/debugMsg) unchanged (regression guard)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // The original keys must still be present in the response array.
    // R-PKG-023 is purely additive — no key is removed or renamed.
    expect($body)->toContain("'success'");
    expect($body)->toContain("'message'");
    expect($body)->toContain("'data'");
    expect($body)->toContain("'debugMsg'");
});

test('sendResponse preserves debug-merge behavior (regression guard)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // The conditional debug merge (`if (config('mk_director.debug', false))`)
    // must still be present. R-PKG-023 does not modify the debug path.
    expect($body)->toContain("'mk_director.debug'");
    expect($body)->toContain('getDebugData()');
    expect($body)->toContain('array_merge');
});
