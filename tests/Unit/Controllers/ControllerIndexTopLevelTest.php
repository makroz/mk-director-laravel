<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (v1.7.0 GA) — `Controller::index()` (legacy template-method
 * controller, parent of pre-1.3.0 controllers) passes the `$paginator`
 * directly to `sendResponse()`. BaseController auto-extracts items to `data`
 * and pagination metadata to `__extraData` top-level. NO legacy nested shape.
 *
 * Migration from rc12: the flag `mk_director.response.top_level_extra_data` is
 * REMOVED in v1.7.0. The legacy nested shape is gone.
 *
 * Implementation note: source-parsing. The legacy Controller class is `abstract`
 * and the method is non-trivial. Source-parsing is the right level of abstraction.
 *
 * @see R-PKG-024 (binding rule, rules_orchestration.md)
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

test('Controller::index() exists and delegates to sendResponse() (single call, R-PKG-024)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('function index(');
    // Single canonical sendResponse call — the rc12 if/else is gone.
    expect($body)->toContain('sendResponse($paginator, \'\', 200, $extra)');
});

test('Controller::index() passes the paginator directly to sendResponse() (R-PKG-024 GA delegates flattening)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('return $this->sendResponse($paginator, \'\', 200, $extra)');
});

test('Controller::index() does NOT branch on a config flag (R-PKG-024 GA removes the rc12 flag)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->not->toContain('response.top_level_extra_data');
    expect($body)->not->toContain('top_level_extra_data');
});

test('Controller::index() does NOT wrap response in legacy nested array shape (R-PKG-024 GA removes the legacy path)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->not->toContain("'data' => \$data");
    expect($body)->not->toContain("'__extraData' => \$extra");
    expect($body)->not->toContain('sendResponse([');
});

test('Controller::index() still builds $extra via afterList hook (regression guard)', function () {
    $body = indexMethodSource();
    expect($body)->not->toBeEmpty();

    // The pre-GA logic to call afterList hook (consumer custom metadata) is preserved.
    expect($body)->toContain('afterList(');
});