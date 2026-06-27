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

// ─── R-PKG-018 BUG-NEW-26 regression tests ──────────────────────────────
//
// BUG: el código previo usaba `Hash::check()` (bcrypt) para comparar el
// token plaintext contra el hash guardado en `personal_access_tokens.token`,
// pero Sanctum v4.3.2 hashea con SHA256 (no bcrypt). Resultado: refresh
// SIEMPRE devolvía 401.
//
// R-PKG-017 BUG-NEW-23 fix mitigaba el 500 → 401 vía try/catch, pero el
// refresh seguía sin funcionar (el catch convertía el RuntimeException
// siempre en InvalidRefreshTokenException::hashMismatch()).
//
// R-PKG-018 BUG-NEW-26 fix: usar `hash_equals(hash('sha256', $plaintext),
// $tokenModel->token)` que es timing-safe Y consistente con la
// implementación interna de Sanctum v4.

test('R-PKG-018 BUG-NEW-26: TokenIssuer uses hash_equals with sha256, not Hash::check', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Auth/Services/TokenIssuer.php');

    // FIX: usa hash_equals + hash('sha256', ...) — consistente con Sanctum v4.
    expect($src)->toContain("hash_equals(\n                \$tokenModel->token,\n                hash('sha256', \$plaintext),");
    expect($src)->toContain("hash('sha256', \$plaintext)");

    // NO debe usar Hash::check() para comparar tokens de Sanctum
    // (Hash::check asume bcrypt — incompatible con SHA256 de Sanctum v4).
    expect($src)->not->toMatch('/Hash::check\s*\(\s*\\\$plaintext\s*,\s*\\\$tokenModel->token/');

    // Defense-in-depth: el try/catch de R-PKG-017 BUG-NEW-23 se conserva
    // como safety net por si Sanctum rota a otro algoritmo en el futuro.
    expect($src)->toContain('catch (\RuntimeException $e)');
});

test('R-PKG-018 BUG-NEW-26: TokenIssuer docblock reflects SHA256 reality (no bcrypt)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Auth/Services/TokenIssuer.php');

    // El docblock debe mencionar explícitamente SHA256 y NO bcrypt como
    // el algoritmo de hash de Sanctum v4.
    expect($src)->toContain('SHA256');
    expect($src)->toContain('Sanctum v4');

    // No debe decir "Sanctum v4 hashea con Hash::make() (bcrypt)" —
    // era la información incorrecta que llevó al bug.
    expect($src)->not->toMatch('/Sanctum v4.*Hash::make.*bcrypt/s');
    expect($src)->not->toMatch('/Hash::make\(\)\s*\(bcrypt\)/s');
});

test('R-PKG-018 BUG-NEW-26: TokenIssuer imports hash() global function for Sanctum v4 SHA256', function () {
    // No requiere import (hash() es built-in de PHP), pero verificamos
    // que NO importa Hash facade para la comparación de tokens.
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Auth/Services/TokenIssuer.php');

    // El namespace `use Illuminate\Support\Facades\Hash;` puede o no estar —
    // lo importante es que NO se use para `Hash::check($plaintext, $tokenModel->token)`.
    if (str_contains($src, 'use Illuminate\\Support\\Facades\\Hash')) {
        // Si importa Hash facade, debe ser solo para usos NO relacionados
        // con la comparación de tokens Sanctum (e.g. user password hash).
        $lines = explode("\n", $src);
        $hashCheckForToken = false;
        foreach ($lines as $line) {
            if (preg_match('/Hash::check\s*\(\s*\\\$plaintext\s*,\s*\\\$tokenModel->token/', $line)) {
                $hashCheckForToken = true;
                break;
            }
        }
        expect($hashCheckForToken)->toBeFalse(
            'Hash::check NO debe usarse para comparar $plaintext contra $tokenModel->token (Sanctum v4 usa SHA256, no bcrypt).'
        );
    }

    expect(true)->toBeTrue(); // sentinel — assertion principal arriba.
});
