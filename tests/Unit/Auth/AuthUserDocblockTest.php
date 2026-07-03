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

    // R-PKG-009: AuthUser es agnóstico al campo de login (default `email`,
    // pero subclases pueden override con `ci`, `phone`, etc.). El docblock
    // NO debe hardcodear un campo específico — solo las props universales.
    expect($doc)->toContain('@property string $name');
    expect($doc)->toContain('@property string $auth_scope');

    // Regression guard: si alguien re-introduce $email hardcoded en el base,
    // este test falla. La subclase concreta (Admin, Member, etc.) es la que
    // declara su propio @property string $loginField vía stub.
    expect($doc)->not->toContain('@property string $email');
    expect($doc)->not->toContain('@property \Illuminate\Support\Carbon|null $email_verified_at');
});

test('AuthUser has $loginField property + scopeWhereLoginField method (R-PKG-009 D6)', function () {
    $source = (string) file_get_contents(authUserSourcePath());

    // Property declaration
    expect($source)->toContain('protected string $loginField = \'email\'');

    // Method getLoginField()
    expect($source)->toMatch('/public function getLoginField\(\)\s*:\s*string/');

    // Local scope scopeWhereLoginField(Builder $query, string $value)
    expect($source)->toMatch('/public function scopeWhereLoginField\(Builder \$query, string \$value\)\s*:\s*Builder/');
});