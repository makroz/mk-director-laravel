<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-023 (rc12): `Controller::index()` (the legacy template-method
 * controller, parent of pre-1.3.0 controllers) now passes `$extra` as
 * the 4th argument to `sendResponse()` when the config flag
 * `mk_director.response.top_level_extra_data` is true. The legacy
 * nested shape is preserved when the flag is off (BC default in rc12).
 *
 * Why this matters: `Controller::index()` is the second of three call
 * sites that emit the response shape (the third is `CRUDSmart::index()`,
 * covered in T4). All three must switch to the top-level shape atomically
 * — if only one switches, consumers see inconsistent envelopes across
 * endpoints. The opt-in flag lets consumers migrate endpoint-by-endpoint
 * if they want, but the package emits consistent top-level whenever the
 * flag is on.
 *
 * Implementation note: source-parsing. The legacy Controller class is
 * `abstract` and the method is non-trivial (multiple hooks, paginator
 * wrappers). Source-parsing the relevant 5-10 lines is the right level
 * of abstraction for this regression pineo.
 */
uses(MkLaravelTestCase::class);

function controllerPath(): string
{
    return __DIR__ . '/../../../src/Controllers/Controller.php';
}

function controllerSource(): string
{
    $path = controllerPath();
    expect(file_exists($path))->toBeTrue("Controller.php must exist at $path");

    return (string) file_get_contents($path);
}

function indexMethodSource(): string
{
    $source = controllerSource();

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

test('Controller::index() exists and returns a sendResponse() call', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The PHP source declares `function index(...)` (no explicit `public`
    // because it's the default in PHP classes — but the body extracted
    // by the helper starts at `function index(`, so we look for that).
    expect($body)->toContain('function index(');
    expect($body)->toContain('sendResponse(');
});

test('Controller::index() branches on response.top_level_extra_data config flag (R-PKG-023)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The conditional branches on the flag — the same flag that
    // BaseController::sendResponse() checks internally.
    expect($body)->toContain('response.top_level_extra_data');
});

test('Controller::index() legacy path preserves the nested __extraData shape (BC default rc12)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The legacy path (flag false) must still build the array with both
    // `data` and `__extraData` keys, and pass it to sendResponse. This
    // is the BC behavior that consumers on rc11 see.
    expect($body)->toContain("'data' => \$data");
    expect($body)->toContain("'__extraData' => \$extra");
    expect($body)->toContain('sendResponse([');
});

test('Controller::index() new path passes $extra as 4th arg to sendResponse (top-level, R-PKG-023)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The new path (flag true) must call sendResponse with $extra as
    // a SEPARATE 4th argument (not nested in an array). The signature
    // is sendResponse($result, $message, $code, $extra).
    //
    // Look for the 4-arg form: sendResponse($data, ..., 200, $extra).
    // We accept a few variants of the message arg ('', 'Creado con éxito', etc.).
    expect($body)->toContain('200, $extra)');
});

test('Controller::index() still builds $extra via afterList + getExtraData (regression guard)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The pre-rc12 logic to assemble $extra (total, afterList, getExtraData)
    // must still be present. R-PKG-023 only changes how $extra is
    // passed to sendResponse, not how it is built.
    expect($body)->toContain('afterList(');
    expect($body)->toContain('getExtraData(');
    expect($body)->toContain('$extra = array_merge(');
});
