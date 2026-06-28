<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (v1.7.0 GA) — `BaseController::sendResponse` signature change.
 *
 * The 4th parameter `array $extra = []` was added in R-PKG-023 (rc12). In
 * R-PKG-024 (v1.7.0 GA), the rc12 opt-in flag is REMOVED — `__extraData` is
 * ALWAYS emitted as a TOP-LEVEL sibling of `data` whenever:
 *   (a) the caller passes a non-empty `$extra`, OR
 *   (b) the input `$result` is a Laravel paginator (auto-extracted via
 *       `extractPaginationMetadata()`).
 *
 * The legacy `data: { data: [...], links, meta }` nested shape is REMOVED
 * in v1.7.0. Tests for the rc12 flag-based branch (this file's predecessor)
 * are obsolete.
 *
 * Why this matters: the @makroz/core `MkResponse<T>` contract defines
 * `__extraData` as top-level (sibling of `data`). `useMkInfiniteList` in
 * web + mobile consume the top-level shape.
 *
 * Implementation note: source-parsing. The method is `protected` and the
 * class is `abstract`, so runtime instantiation would need an anonymous
 * test subclass. For a signature + paginator-handling change like this,
 * source-parsing is the right level of abstraction.
 *
 * @see R-PKG-024 (binding rule, rules_orchestration.md)
 * @see BaseControllerSingleLevelEnvelopeE2ETest (EFECTIVIDAD runtime tests)
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
    $source = sendResponseMethodSource();
    $bodyStart = strpos($source, '{');
    if ($bodyStart === false) {
        return '';
    }

    $bodyStart++;
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

test('sendResponse signature includes array $extra = [] as 4th parameter (R-PKG-023 → R-PKG-024)', function () {
    $body = sendResponseMethodSource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('array $extra = []');
});

test('sendResponse detects paginator and extracts items + metadata (R-PKG-024 GA replaces rc12 flag check)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // R-PKG-024: paginator detection replaces the rc12 flag check.
    expect($body)->toContain('instanceof AbstractPaginator');
    expect($body)->toContain('instanceof CursorPaginator');
    expect($body)->toContain('extractPaginationMetadata');

    // R-PKG-023 flag is GONE.
    expect($body)->not->toContain('response.top_level_extra_data');
});

test('sendResponse emits __extraData as top-level sibling of data (single-level envelope, R-PKG-024)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    // Top-level emission (sibling of $response['data']).
    expect($body)->toContain("\$response['__extraData'] = \$extra");
    // Gate: paginator OR non-empty $extra.
    expect($body)->toContain('if ($isPaginator || $extra !== [])');
});

test('sendResponse original payload (success/message/data/debugMsg) unchanged (regression guard)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain("'success'");
    expect($body)->toContain("'message'");
    expect($body)->toContain("'data'");
    expect($body)->toContain("'debugMsg'");
});

test('sendResponse preserves debug-merge behavior (regression guard)', function () {
    $body = sendResponseBodySource();
    expect($body)->not->toBeEmpty();

    expect($body)->toContain("'mk_director.debug'");
    expect($body)->toContain('getDebugData()');
    expect($body)->toContain('array_merge');
});