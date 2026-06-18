<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that the AuthUser docblock declares `id` as a string (not int),
 * matching the migration that uses uuid primary keys.
 *
 * Implementation note: we read the source file and parse the docblock
 * directly. Two reasons:
 *  1. The AuthUser class uses Laravel\Sanctum\HasApiTokens which is NOT a
 *     runtime dependency of the package (see composer.json), so loading
 *     the class in unit context throws a fatal Error.
 *  2. phpdocumentor/reflection-docblock is in vendor/ but is a transitive
 *     dependency that may not be available in every consumer install.
 *     Regex parsing keeps the test self-contained.
 *
 * @see audit-2026-06-17-R3-005
 */
uses(MkLaravelTestCase::class);

function authUserSourcePath(): string
{
    return __DIR__ . '/../../../src/Auth/Models/AuthUser.php';
}

function authUserClassDocblock(): string
{
    $path = authUserSourcePath();
    expect(file_exists($path))->toBeTrue("AuthUser.php must exist at $path");

    $source = (string) file_get_contents($path);

    // Match the leading /** ... */ block that immediately precedes
    // `abstract class AuthUser` (or `class AuthUser`).
    if (! preg_match('#(/\*\*.*?\*/)\s*(?:abstract\s+)?class\s+AuthUser#s', $source, $matches)) {
        return '';
    }

    return $matches[1];
}

test('AuthUser docblock declares $id as string (not int)', function () {
    $doc = authUserClassDocblock();
    expect($doc)->not->toBeEmpty('class docblock for AuthUser must exist');

    // Find the @property tag for $id.
    if (! preg_match('/@property\s+(\S+)\s+\$id\b/', $doc, $matches)) {
        expect()->fail('@property $id tag must exist on AuthUser');
    }

    expect($matches[1])->toBe('string');
});

test('AuthUser docblock still has the rest of the @property tags (regression guard)', function () {
    $doc = authUserClassDocblock();
    expect($doc)->not->toBeEmpty();

    // Ensure we did not accidentally drop other properties when fixing $id.
    expect($doc)->toContain('@property string $name');
    expect($doc)->toContain('@property string $email');
    expect($doc)->toContain('@property string $auth_scope');
});