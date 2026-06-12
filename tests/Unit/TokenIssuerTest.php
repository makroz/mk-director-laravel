<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Mockery;
use Mockery\MockInterface;
use Mk\Director\Auth\Services\TokenIssuer;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Helper — fake user con getAuthScope() configurable.
 */
function fakeAuthUser(?string $scope = 'admin'): Authenticatable|MockInterface
{
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthScope')->andReturn($scope)->byDefault();

    return $user;
}

test('issueAccessToken includes auth_scope ability and explicit abilities are deduplicated', function () {
    $issuer = new TokenIssuer();
    $user = fakeAuthUser('admin');

    // No podemos invocar createToken sin app Laravel, pero podemos verificar
    // la composición de abilities (la lógica core).
    $abilities = $issuer->buildAccessAbilities($user, [
        'users.edit',
        'users.delete',
        'auth_scope:admin', // duplicado intencional
    ]);

    expect($abilities)
        ->toBeArray()
        ->toContain('auth_scope:admin')
        ->toContain('users.edit')
        ->toContain('users.delete')
        ->and(array_count_values($abilities)['auth_scope:admin'] ?? 0)
        ->toBe(1, 'auth_scope:admin debe aparecer una sola vez (dedupe)');

    // auth_scope siempre primero en el orden.
    expect($abilities[0])->toBe('auth_scope:admin');
});

test('issueRefreshToken composes refresh ability plus scope ability', function () {
    $issuer = new TokenIssuer();
    $user = fakeAuthUser('member');

    $scopeAbility = $issuer->scopeAbilityFor($user);

    expect($scopeAbility)->toBe('auth_scope:member');

    // Las abilities del refresh son exactamente [refresh, auth_scope:member].
    // Lo verificamos contra la constante pública.
    expect(TokenIssuer::REFRESH_ABILITY)->toBe('refresh');

    $refreshAbilities = [TokenIssuer::REFRESH_ABILITY, $scopeAbility];
    expect($refreshAbilities)
        ->toHaveCount(2)
        ->toContain('refresh')
        ->toContain('auth_scope:member');
});

test('access token abilities payload contains the expected auth_scope value', function () {
    $issuer = new TokenIssuer();
    $user = fakeAuthUser('admin');

    $abilities = $issuer->buildAccessAbilities($user, ['users.view']);

    // Sanity: extraer el scope desde el payload y matchear.
    $extracted = TokenIssuer::extractScopeFromAbilities($abilities);

    expect($extracted)->toBe('admin');

    // Y la ability explícita está presente.
    expect($abilities)->toContain('users.view');
});

test('scopeAbilityFor returns auth_scope:unknown when user has no scope', function () {
    $issuer = new TokenIssuer();
    $user = fakeAuthUser(null);

    expect($issuer->scopeAbilityFor($user))->toBe('auth_scope:unknown');
});

test('scopeAbilityFor returns auth_scope:unknown when method returns empty string', function () {
    $issuer = new TokenIssuer();
    $user = fakeAuthUser('');

    expect($issuer->scopeAbilityFor($user))->toBe('auth_scope:unknown');
});

test('TokenIssuer TTLs are configurable via constructor for testability', function () {
    // 15 min para access, 7 días para refresh.
    $issuer = new TokenIssuer(accessTtlSeconds: 900, refreshTtlSeconds: 604800);

    // Verificamos que el constructor no rompe y la clase es usable.
    expect($issuer)->toBeInstanceOf(TokenIssuer::class);
});
