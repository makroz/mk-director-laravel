<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkAuthenticate eager-loads roles.abilities and
 * directAbilities on the resolved user. This kills the N+1 in the
 * authz path documented as audit R4-002.
 *
 * Implementation note: we parse the source rather than executing the
 * middleware because MkAuthenticate depends on laravel/sanctum
 * (via AuthScopeResolver) which is not in composer.json.
 *
 * @see audit-2026-06-17-R4-002
 */
uses(MkLaravelTestCase::class);

function mkAuthenticateSourcePath(): string
{
    return __DIR__ . '/../../../src/Auth/Middleware/MkAuthenticate.php';
}

function mkAuthenticateSource(): string
{
    $path = mkAuthenticateSourcePath();
    expect(file_exists($path))->toBeTrue("MkAuthenticate must exist at $path");

    return (string) file_get_contents($path);
}

test('MkAuthenticate calls loadMissing() on the resolved user with roles.abilities', function () {
    $src = mkAuthenticateSource();

    expect($src)->toContain("loadMissing");
    expect($src)->toContain("'roles.abilities'");
});

test('MkAuthenticate also eager-loads directAbilities alongside roles.abilities', function () {
    $src = mkAuthenticateSource();

    expect($src)->toContain("'directAbilities'");
});

test('MkAuthenticate calls loadMissing AFTER resolver validation (so failures short-circuit without an extra query)', function () {
    $src = mkAuthenticateSource();

    $resolverPos = strpos($src, '$this->resolver->resolve');
    $loadMissingPos = strpos($src, 'loadMissing');

    expect($resolverPos)->toBeGreaterThan(0);
    expect($loadMissingPos)->toBeGreaterThan(0);
    expect($loadMissingPos)->toBeGreaterThan($resolverPos);
});

test('MkAuthenticate uses loadMissing (not load) so it is idempotent', function () {
    $src = mkAuthenticateSource();

    // load() always re-queries; loadMissing() is a no-op if already loaded.
    // We require loadMissing so re-entrant middleware chains do not re-fetch.
    expect($src)->toContain('loadMissing');
    expect($src)->not->toContain("\$user->load(['roles.abilities']");
});