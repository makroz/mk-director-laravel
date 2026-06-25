<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-009 — `AuthUser` login-field agnostic.
 *
 * Contrato pineado acá:
 *   - AuthUser tiene property `$loginField` (default 'email').
 *   - AuthUser expone método `getLoginField(): string`.
 *   - AuthUser expone local scope `scopeWhereLoginField(Builder, string): Builder`.
 *   - Docblock ya no hardcodea `@property string $email` (regresión cubierta
 *     por AuthUserDocblockTest).
 *
 * Spec: design.md D6 — `scopeWhereLoginField()` API agnóstica al campo.
 * @see AuthUser
 */
uses(MkLaravelTestCase::class);

function authUserSource009(): string
{
    $path = dirname(__DIR__, 3).'/src/Auth/Models/AuthUser.php';
    expect(file_exists($path))->toBeTrue("AuthUser must exist at $path");

    return (string) file_get_contents($path);
}

test('AuthUser declares $loginField property with default email', function () {
    $source = authUserSource009();

    // Property con default 'email' para BC con v1.4.0.
    expect($source)->toMatch('/protected\s+string\s+\$loginField\s*=\s*\'email\'\s*;/');
});

test('AuthUser exposes getLoginField(): string method', function () {
    $source = authUserSource009();

    expect($source)->toMatch('/public\s+function\s+getLoginField\s*\(\s*\)\s*:\s*string/');
    // Debe retornar $this->loginField.
    expect($source)->toContain('return $this->loginField');
});

test('AuthUser exposes scopeWhereLoginField(Builder $query, string $value): Builder', function () {
    $source = authUserSource009();

    // Firma exacta con tipos.
    expect($source)->toMatch('/public\s+function\s+scopeWhereLoginField\s*\(\s*Builder\s+\$query\s*,\s*string\s+\$value\s*\)\s*:\s*Builder/');

    // Builder use statement.
    expect($source)->toContain('use Illuminate\\Database\\Eloquent\\Builder');

    // Implementación: where($this->loginField, $value).
    expect($source)->toContain('return $query->where($this->loginField, $value)');
});

test('AuthUser base preserva BC: $fillable + $casts mantienen email (subclase override via stub)', function () {
    $source = authUserSource009();

    // BC: $fillable base tiene 'email' (subclases con --login-field != email
    // override $fillable via stub, pero el base mantiene email para consumers
    // existentes).
    expect($source)->toContain("'email'");

    // BC: $casts base tiene email_verified_at (MustVerifyEmail interface).
    expect($source)->toContain("'email_verified_at' => 'datetime'");

    // BC: AuthUser sigue implementando MustVerifyEmail (interface heredada).
    expect($source)->toContain('implements AuthenticatableContract, MustVerifyEmail');
});

test('AuthUser docblock NO hardcodea $email (agnóstico al campo)', function () {
    // Cubierto en AuthUserDocblockTest, pero duplicamos acá para que el
    // contrato de R-PKG-009 sea explícito en un solo archivo.
    $source = authUserSource009();

    // El docblock de clase (justo antes de `abstract class AuthUser`).
    if (! preg_match('#(/\*\*.*?\*/)\s*(?:abstract\s+)?class\s+AuthUser#s', $source, $matches)) {
        expect()->fail('class docblock for AuthUser must exist');
    }
    $doc = $matches[1];

    // No debe tener @property string $email hardcoded (sería agnóstico roto).
    expect($doc)->not->toContain('@property string $email');
    expect($doc)->not->toContain('@property \Illuminate\Support\Carbon|null $email_verified_at');
});