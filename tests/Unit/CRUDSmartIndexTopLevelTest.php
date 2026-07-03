<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (v1.7.0 GA) — `CRUDSmart::index()` (the recommended SmartController
 * index method, used by 95% of generated controllers) passes the `$paginator`
 * directly to `sendResponse()`. BaseController auto-extracts items to `data`
 * (array) and pagination metadata to `__extraData` top-level. NO `data.data`
 * nesting, NO legacy opt-in flag (R-PKG-023 flag removed in GA).
 *
 * Migration from rc12: the flag `mk_director.response.top_level_extra_data` is
 * REMOVED in v1.7.0. The legacy nested shape is gone — the envelope is always
 * single-level. Tests for the rc12 flag (this file's predecessor) are obsolete.
 *
 * Implementation note: source-parsing. The trait is consumed by `SmartController`
 * which is `abstract`. Runtime instantiation requires a TestConcreteController
 * — overkill for this structural change. Source-parsing the relevant 5-15 lines
 * is the right level of abstraction, same pattern as R2-010 hardening pineo.
 *
 * Plugin hook note: `fireAfterResponse` receives the paginator (so plugins can
 * read total / currentPage / lastPage / perPage directly). No more wrapped
 * array shape. Plugins that need pagination metadata read from the paginator
 * instance methods.
 *
 * @see R-PKG-024 (binding rule, rules_orchestration.md)
 * @see BaseControllerSingleLevelEnvelopeTest (BaseController + package-wide invariants)
 * @see BaseControllerSingleLevelEnvelopeE2ETest (EFECTIVIDAD runtime tests)
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

test('CRUDSmart::index() exists and delegates to sendResponse() (single call, R-PKG-024)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('function index(');
    // Single canonical sendResponse call — the rc12 if/else is gone.
    expect($body)->toContain('sendResponse($paginator, \'\', 200, $extra)');
});

test('CRUDSmart::index() passes the paginator directly to sendResponse() (R-PKG-024 GA delegates flattening to BaseController)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // Single canonical call: sendResponse($paginator, '', 200, $extra)
    // — BaseController auto-extracts items + pagination meta.
    expect($body)->toContain('return $this->sendResponse($paginator, \'\', 200, $extra)');
});

test('CRUDSmart::index() does NOT branch on a config flag (R-PKG-024 GA removes the rc12 flag)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The rc12 opt-in flag check is gone.
    expect($body)->not->toContain('response.top_level_extra_data');
    expect($body)->not->toContain('top_level_extra_data');
});

test('CRUDSmart::index() does NOT wrap response in legacy nested array shape (R-PKG-024 GA removes the legacy path)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The legacy nested shape pattern is GONE.
    expect($body)->not->toContain("'data' => \$items");
    expect($body)->not->toContain("'__extraData' => \$extra");
    expect($body)->not->toContain('sendResponse($response)');
});

test('CRUDSmart::index() still assembles $extra via afterList service hook (regression guard)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The pre-GA logic to call service.afterList (consumer custom hooks) is
    // preserved. R-PKG-024 only changes how $extra is passed to sendResponse,
    // not how it is built (R-PKG-024 refactor: $extra = $service->afterList(...)
    // — the array_merge pattern was only needed when combining with the
    // auto-extracted pagination defaults in CRUDSmart itself; now BaseController
    // handles the merge, so CRUDSmart just assigns the service extras).
    expect($body)->toContain('afterList(');
});

test('CRUDSmart::index() still fires afterResponse plugin hook (regression guard)', function () {
    $body = crudsMartIndexMethodSource();
    expect($body)->not->toBeEmpty();

    // The plugin hook `fireAfterResponse` is still called. R-PKG-024 passes
    // the paginator (not a wrapped array) — plugins can read total /
    // currentPage / lastPage / perPage from the paginator instance.
    expect($body)->toContain('fireAfterResponse($paginator)');
});