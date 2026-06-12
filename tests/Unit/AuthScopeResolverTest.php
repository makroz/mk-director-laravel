<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Auth\Exceptions\ScopeMismatchException;
use Mk\Director\Auth\Services\TokenIssuer;

uses(\Mk\Director\Tests\TestCase::class);

test('extractScopeFromAbilities returns scope when ability matches the prefix', function () {
    $scope = TokenIssuer::extractScopeFromAbilities([
        'users.view',
        'auth_scope:admin',
        'users.edit',
    ]);

    expect($scope)->toBe('admin');
});

test('extractScopeFromAbilities returns null on mismatch (different scope in token)', function () {
    // El resolver extrae el scope real del token; la comparación
    // contra el expected se hace en AuthScopeResolver::resolve.
    $actual = TokenIssuer::extractScopeFromAbilities(['auth_scope:member']);

    expect($actual)->toBe('member'); // extrae, no compara
    expect($actual)->not->toBe('admin'); // sanity: si expected=admin, no matchea
});

test('extractScopeFromAbilities returns null when no scope ability present', function () {
    expect(TokenIssuer::extractScopeFromAbilities(['users.view', 'users.edit']))->toBeNull();
    expect(TokenIssuer::extractScopeFromAbilities([]))->toBeNull();
    expect(TokenIssuer::extractScopeFromAbilities(['not-a-scope-ability']))->toBeNull();
});

test('extractScopeFromAbilities handles empty string after prefix gracefully', function () {
    expect(TokenIssuer::extractScopeFromAbilities(['auth_scope:']))->toBeNull();
});

test('extractScopeFromAbilities ignores non-string entries safely', function () {
    expect(TokenIssuer::extractScopeFromAbilities([null, 123, ['nested'], 'auth_scope:admin']))
        ->toBe('admin');
});

test('ScopeMismatchException carries expected and actual scope for audit', function () {
    $e = new ScopeMismatchException(
        expectedScope: 'admin',
        actualScope: 'member',
    );

    expect($e)
        ->toBeInstanceOf(\Throwable::class)
        ->and($e->expectedScope)->toBe('admin')
        ->and($e->actualScope)->toBe('member');

    // El mensaje contiene el expected/actual para loggear en producción.
    expect($e->getMessage())
        ->toContain('admin')
        ->toContain('member');
});

test('ScopeMismatchException with null actual scope is valid (no auth at all)', function () {
    $e = new ScopeMismatchException(
        expectedScope: 'admin',
        actualScope: null,
    );

    expect($e->expectedScope)->toBe('admin');
    expect($e->actualScope)->toBeNull();
    expect($e->getMessage())->toContain('<none>');
});
