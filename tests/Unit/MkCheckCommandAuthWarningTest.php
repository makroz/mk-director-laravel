<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkCheckCommand was hardened in 1.2.2-hardening to warn when
 * a SmartController subclass has no obvious auth wiring in its source
 * (middleware, MkAuthenticate, MkAbility, or auth in __construct).
 *
 * Implementation note: source-parsing MkCheckCommand for the regex and
 * the warning message. Two reasons:
 *  1. The `mk:status` command is a runtime command that requires booting
 *     a Laravel app + scanning consumer controllers — too heavy for a
 *     unit test in the package.
 *  2. The fix is a STRING contract (a regex + a literal message), so
 *     source-parsing is the right level of abstraction. If a future
 *     refactor drops the warning or tightens the regex incorrectly, this
 *     test catches it.
 *
 * @see audit-2026-06-17 T1.2 (SmartController guarded advisory)
 */
uses(MkLaravelTestCase::class);

function mkCheckCommandSourcePath(): string
{
    return __DIR__ . '/../../src/Console/Commands/MkCheckCommand.php';
}

function mkCheckCommandSource(): string
{
    $path = mkCheckCommandSourcePath();
    expect(file_exists($path))->toBeTrue("MkCheckCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

function mkCheckCommandAuditControllerSource(): string
{
    $source = mkCheckCommandSource();

    // Anchor: from the `auditController(` signature up to the end of the
    // method (next method at 4-space indent, or end of class).
    $start = strpos($source, 'function auditController(');
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

test('MkCheckCommand auditController accepts a $controllerSource parameter', function () {
    // Pin the new signature so a future refactor that drops the source
    // argument (and therefore the auth check) is caught.
    $source = mkCheckCommandSource();
    expect($source)->toMatch('/function\s+auditController\s*\(\s*string\s+\$controllerClass\s*,\s*\??\s*string\s+\$controllerSource/');
});

test('MkCheckCommand auth-detection regex covers middleware, MkAuthenticate, MkAbility, and __construct auth', function () {
    $body = mkCheckCommandAuditControllerSource();
    expect($body)->not->toBeEmpty();

    // The regex must detect at minimum:
    //   - `middleware(` call (Laravel's controller middleware())
    //   - MkAuthenticate / MkAbility classes (mk-director auth middlewares)
    //   - `->middleware(` (fluent call on the route / router)
    //   - `__construct` taking an Auth-related arg (constructor injection)
    expect($body)->toContain('middleware\\s*\\(');
    expect($body)->toContain('MkAuthenticate');
    expect($body)->toContain('MkAbility');
    expect($body)->toContain('->middleware\\(');
    expect($body)->toMatch('/__construct/');
    expect($body)->toMatch('/Auth/');
});

test('MkCheckCommand emits the exact Spanish warning message about SmartController auth', function () {
    $body = mkCheckCommandAuditControllerSource();
    expect($body)->not->toBeEmpty();

    // The warning is in Spanish (matches the rest of the command's UX).
    // We pin the leading fragment so a translation/typo change is
    // intentional, not accidental.
    expect($body)->toContain('SmartController no enforce auth');
    expect($body)->toContain('agregar middleware');
    expect($body)->toContain("'type' => 'warning'");
});

test('MkCheckCommand findSmartControllers returns source content keyed by class name', function () {
    $source = mkCheckCommandSource();
    expect($source)->not->toBeEmpty();

    // The findSmartControllers() method must return an associative array
    // (class => source) so the audit loop can pass the source to
    // auditController. A regression to a sequential array would silently
    // skip the auth check.
    $start = strpos($source, 'function findSmartControllers(');
    expect($start)->not->toBeFalse();

    $bodyStart = strpos($source, '{', $start) + 1;
    $pattern = '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/';
    $next = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE, $bodyStart);
    if ($next === 0) {
        $classEnd = strrpos($source, '}');
        $body = substr($source, $bodyStart, $classEnd - $bodyStart - 1);
    } else {
        $body = substr($source, $bodyStart, $matches[0][0][1] - $bodyStart);
    }

    expect($body)->toContain('$smartControllers[$fullClass] = $content');
});
