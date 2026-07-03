<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-023 (rc12): `mk:status` (the `MkCheckCommand` Artisan command)
 * gets a new option `--response-shape` that audits every controller in
 * the consumer project and warns about legacy `sendResponse(['data' => ...,
 * '__extraData' => ...])` calls when the migration to top-level
 * `__extraData` is enabled.
 *
 * Why this matters: when a consumer flips
 * `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA=true` and they have any
 * custom controller that still passes the wrapped array, those
 * endpoints emit the legacy nested shape — the audit command surfaces
 * them so the consumer knows which controllers to migrate.
 *
 * Implementation note: source-parsing. The audit is a
 * consumer-project file scan — it doesn't run in the package's unit
 * test suite (no consumer project to scan). Source-parsing the
 * signature and handler structure is the right level of pineo.
 */
uses(MkLaravelTestCase::class);

function mkCheckCommandPath(): string
{
    return __DIR__ . '/../../../src/Console/Commands/MkCheckCommand.php';
}

function mkCheckCommandSource(): string
{
    $path = mkCheckCommandPath();
    expect(file_exists($path))->toBeTrue("MkCheckCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('mk:status signature includes --response-shape option (R-PKG-023)', function () {
    $source = mkCheckCommandSource();

    // The signature must include the new option.
    expect($source)->toContain('--response-shape');
});

test('mk:status handler branches on --response-shape option to call audit method', function () {
    $source = mkCheckCommandSource();

    // The handle() method must check the option and dispatch to the
    // audit method when present.
    expect($source)->toContain("'response-shape'");
    expect($source)->toContain('auditResponseShape(');
});

test('mk:status has an auditResponseShape() method that scans controllers', function () {
    $source = mkCheckCommandSource();

    // The audit method must exist and walk both app/Http/Controllers
    // and app/Modules (the two locations the existing findSmartControllers
    // walks for SmartController extension).
    expect($source)->toContain('function auditResponseShape(');
    expect($source)->toContain('app_path(');
    expect($source)->toContain('app/Http/Controllers');
    expect($source)->toContain('app/Modules');
});

test('mk:status auditResponseShape() detects legacy nested __extraData sendResponse calls', function () {
    $source = mkCheckCommandSource();

    // The audit must detect the legacy pattern: sendResponse with a
    // single array argument containing both 'data' and '__extraData'
    // keys. The pattern `sendResponse([` followed by `__extraData` is
    // a good signal.
    expect($source)->toContain("'__extraData'");

    // The audit reports warnings (not errors) — consumers can still
    // operate with mixed shapes during the migration window.
    expect($source)->toContain('warning');
});
