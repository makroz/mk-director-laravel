<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-016 BUG-NEW-20 / LAR-01 (2026-07-03 audit, CRITICAL) — IDOR
 * cross-tenant via `CRUDSmart::update()` and `CRUDSmart::destroy()`.
 *
 * When tenant isolation is provided by `MkMultiTenantPlugin` (a
 * `beforeQuery` plugin), the plugin adds the `where tenant_id = ?`
 * filter to the Eloquent query. If the controller does not build a
 * Builder and call `fireBeforeQuery()` BEFORE the `findOrFail($id)`,
 * the plugin hook never runs and the record is loaded globally — any
 * authenticated tenant can write or delete any other tenant's row.
 *
 * `CRUDSmart::show()` already follows the safe pattern (build query,
 * eager-load, fire `beforeQuery`, then `findOrFail`). This test pins
 * the same contract for `update()` and `destroy()` so the IDOR cannot
 * regress silently.
 *
 * Note: `index()` is also pinned (pre-existing, defense-in-depth).
 *
 * @see 04-mk-director-laravel.md#LAR-01 (2026-07-03 audit)
 * @see 00-INFORME-CONSOLIDADO.md § "Los 8 problemas" #1 (IDOR)
 */
uses(MkLaravelTestCase::class);

function tenantIsolationSource(): string
{
    $path = __DIR__.'/../../src/Traits/CRUDSmart.php';
    expect(file_exists($path))->toBeTrue("CRUDSmart.php must exist at $path");

    return (string) file_get_contents($path);
}

/**
 * Extract the body of a method whose signature matches the given regex
 * (e.g. `/function update\(/`). Returns the substring from the
 * signature through the next `function` declaration or the closing
 * brace of the trait, whichever comes first.
 */
function tenantIsolationMethodBody(string $signatureRegex): string
{
    $source = tenantIsolationSource();

    $start = preg_match($signatureRegex, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($start === 0) {
        return '';
    }
    $offset = $matches[0][1];

    $nextFn = preg_match(
        '/\n    (?:protected|public|private)?\s*(?:static\s+)?function\s+\w+/',
        $source,
        $nextMatches,
        PREG_OFFSET_CAPTURE,
        $offset + 1,
    );

    if ($nextFn === 0) {
        $classEnd = strrpos($source, '}');

        return substr($source, $offset, $classEnd - $offset - 1);
    }

    return substr($source, $offset, $nextMatches[0][1] - $offset);
}

test('CRUDSmart::update() builds an Eloquent query before findOrFail (LAR-01 IDOR fix)', function () {
    $body = tenantIsolationMethodBody('/function update\(/');
    expect($body)->not->toBeEmpty();

    // The unsafe pattern that caused IDOR — `Model::findOrFail($id)` directly,
    // bypassing the `beforeQuery` plugin hook. Must NOT appear after the fix.
    expect($body)->not->toContain('$modelClass::findOrFail($id)');
});

test('CRUDSmart::update() fires beforeQuery on the query builder (LAR-01 IDOR fix)', function () {
    $body = tenantIsolationMethodBody('/function update\(/');
    expect($body)->not->toBeEmpty();

    // The MkMultiTenantPlugin (and any other beforeQuery plugin) MUST run before
    // findOrFail so the tenant filter is applied to the lookup.
    expect($body)->toContain('fireBeforeQuery(');

    // And the order matters: fireBeforeQuery must appear BEFORE the findOrFail call.
    $firePos = strpos($body, 'fireBeforeQuery(');
    $findPos = strpos($body, 'findOrFail(');
    expect($firePos)->not->toBeFalse();
    expect($findPos)->not->toBeFalse();
    expect($firePos)->toBeLessThan($findPos);
});

test('CRUDSmart::destroy() builds an Eloquent query before findOrFail (LAR-01 IDOR fix)', function () {
    $body = tenantIsolationMethodBody('/function destroy\(/');
    expect($body)->not->toBeEmpty();

    expect($body)->not->toContain('$modelClass::findOrFail($id)');
});

test('CRUDSmart::destroy() fires beforeQuery on the query builder (LAR-01 IDOR fix)', function () {
    $body = tenantIsolationMethodBody('/function destroy\(/');
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('fireBeforeQuery(');

    $firePos = strpos($body, 'fireBeforeQuery(');
    $findPos = strpos($body, 'findOrFail(');
    expect($firePos)->not->toBeFalse();
    expect($findPos)->not->toBeFalse();
    expect($firePos)->toBeLessThan($findPos);
});

test('CRUDSmart::show() still fires beforeQuery before findOrFail (regression guard for LAR-01 sibling)', function () {
    // show() was the reference implementation that already had the safe
    // pattern. Pin it here so a future refactor cannot silently drop the
    // hook on show() too.
    $body = tenantIsolationMethodBody('/function show\(/');
    expect($body)->not->toBeEmpty();

    expect($body)->toContain('fireBeforeQuery(');
    $firePos = strpos($body, 'fireBeforeQuery(');
    $findPos = strpos($body, 'findOrFail(');
    expect($firePos)->toBeLessThan($findPos);
});
