<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-023 (rc12): `CRUDSmart::index()` (the recommended SmartController
 * index method, used by 95% of generated controllers) now passes `$extra`
 * as the 4th argument to `sendResponse()` when the config flag
 * `mk_director.response.top_level_extra_data` is true. The legacy
 * nested shape is preserved when the flag is off (BC default in rc12).
 *
 * Why this matters: `CRUDSmart::index()` is the THIRD and most-widely-used
 * call site that emits the response shape (the other two are
 * `BaseController::sendResponse` and `Controller::index`). All three
 * must switch to the top-level shape atomically when the flag is on —
 * if any one of them keeps the nested shape, consumers see inconsistent
 * envelopes across endpoints.
 *
 * Implementation note: source-parsing. The trait is consumed by
 * `SmartController` which is `abstract`. Runtime instantiation requires
 * a TestConcreteController — overkill for a 4-line conditional change.
 * Source-parsing the relevant 10-15 lines is the right level of
 * abstraction, same pattern as R2-010 hardening pineo and T2/T3.
 *
 * Plugin hook note: the `fireAfterResponse` hook receives different
 * shapes in legacy vs. new path. The legacy path passes the wrapped
 * array `['data' => items, '__extraData' => $extra]`. The new path
 * passes the items directly (since `__extraData` is emitted as a
 * sibling of `data`, not nested). Plugins that need access to
 * `$extra` should consume the `mk_director.response` config or read
 * from a future API. CHANGELOG notes the plugin contract change.
 */
uses(MkLaravelTestCase::class);

function crudsMartPath(): string
{
    return __DIR__ . '/../../src/Traits/CRUDSmart.php';
}

function crudsMartSource(): string
{
    $path = crudsMartPath();
    expect(file_exists($path))->toBeTrue("CRUDSmart.php must exist at $path");

    return (string) file_get_contents($path);
}

function crudsMartIndexMethodSource(): string
{
    $source = crudsMartSource();

    $start = strpos($source, 'function index(');
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

test('CRUDSmart::index() exists and returns a sendResponse() call', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The body extracted by the helper starts at `function index(` (no `public`
    // because the `public` keyword is part of the line BEFORE the body slice).
    expect($body)->toContain('function index(');
    expect($body)->toContain('sendResponse(');
});

test('CRUDSmart::index() branches on response.top_level_extra_data config flag (R-PKG-023)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The conditional branches on the flag — the same flag that
    // BaseController::sendResponse() and Controller::index() check.
    expect($body)->toContain('response.top_level_extra_data');
});

test('CRUDSmart::index() legacy path preserves the nested __extraData shape (BC default rc12)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The legacy path (flag false) must still build the array with both
    // `data` and `__extraData` keys, and pass it to sendResponse.
    expect($body)->toContain("'data' => \$items");
    expect($body)->toContain("'__extraData' => \$extra");
    expect($body)->toContain('sendResponse($response)');
});

test('CRUDSmart::index() new path passes $extra as 4th arg to sendResponse (top-level, R-PKG-023)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The new path (flag true) must call sendResponse with $extra as
    // a SEPARATE 4th argument. The signature is
    // sendResponse($result, $message, $code, $extra).
    expect($body)->toContain('200, $extra)');
});

test('CRUDSmart::index() still builds $extra via afterList + getExtraData (regression guard)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The pre-rc12 logic to assemble $extra (total, afterList, getExtraData)
    // must still be present. R-PKG-023 only changes how $extra is
    // passed to sendResponse, not how it is built.
    expect($body)->toContain("'total' => \$total");
    expect($body)->toContain('afterList(');
    expect($body)->toContain('getExtraData(');
    expect($body)->toContain('array_merge(');
});

test('CRUDSmart::index() still fires afterResponse plugin hook in both paths', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The plugin hook `fireAfterResponse` must still be called. The
    // argument shape differs between paths (legacy: wrapped array;
    // new: items directly) but the hook is invoked in both.
    expect($body)->toContain('fireAfterResponse(');
});
