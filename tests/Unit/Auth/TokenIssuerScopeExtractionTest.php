<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B06 — TokenIssuer::extractScopeFromAbilities soporta {key: bool} de Sanctum 4.x.
 *
 * **Bug pineado** (FEEDBACK9, RETO corrida 9):
 * Sanctum 4.x guarda abilities como objeto JSON `{key: bool}` en
 * `personal_access_tokens.abilities`. El método pre-fix esperaba flat array
 * de strings:
 *
 *   ['refresh', 'auth_scope:admin', '*']  ← pre-fix OK
 *   ['refresh' => true, 'auth_scope:admin' => true]  ← Sanctum 4.x REAL
 *
 * Con `{key: bool}`, `foreach` lee los values (true/false), no las keys.
 * El scope nunca se extraía. Tests directos con
 * `createToken(['auth_scope:admin' => true])` rompían (silenciosamente el
 * scope quedaba null).
 *
 * **Fix**: normalizar assoc array a flat list de strings antes de iterar.
 * - Si key es string (assoc) → tomar la key.
 * - Si key es int (flat) → tomar el value (si string).
 *
 * Production sigue funcionando porque `issueAccessToken()` pine array plano
 * de strings. Solo el path de testing directo está pineado por este fix.
 */
uses(MkLaravelTestCase::class);

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() extrae scope de flat array (BC)', function () {
    // Pre-fix pineado: flat array de strings.
    expect(TokenIssuer::extractScopeFromAbilities(['refresh', 'auth_scope:admin', '*']))->toBe('admin');
    expect(TokenIssuer::extractScopeFromAbilities(['auth_scope:member']))->toBe('member');
    expect(TokenIssuer::extractScopeFromAbilities(['refresh']))->toBeNull();
    expect(TokenIssuer::extractScopeFromAbilities([]))->toBeNull();
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() extrae scope de assoc array Sanctum 4.x {key: bool}', function () {
    // Post-fix pineado: assoc array {key: bool} de Sanctum 4.x.
    expect(TokenIssuer::extractScopeFromAbilities(['auth_scope:admin' => true]))->toBe('admin');
    expect(TokenIssuer::extractScopeFromAbilities(['refresh' => true, 'auth_scope:member' => true]))->toBe('member');
    expect(TokenIssuer::extractScopeFromAbilities(['*' => true, 'auth_scope:admin' => true]))->toBe('admin');
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() extrae scope de mixed array (flat + assoc)', function () {
    // Edge case: array mixto (raro pero legal).
    $mixed = ['auth_scope:admin', 'refresh' => true];
    expect(TokenIssuer::extractScopeFromAbilities($mixed))->toBe('admin');
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() maneja scope vacío como null', function () {
    expect(TokenIssuer::extractScopeFromAbilities(['auth_scope:' => true]))->toBeNull();
    expect(TokenIssuer::extractScopeFromAbilities(['auth_scope:']))->toBeNull();
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() filtra values no-string', function () {
    // Edge case: values que no son string no se procesan.
    expect(TokenIssuer::extractScopeFromAbilities([123, true, 'auth_scope:admin']))->toBe('admin');
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() JSDoc explica Sanctum 4.x BC', function () {
    $src = (string) file_get_contents(__DIR__.'/../../../src/Auth/Services/TokenIssuer.php');

    $methodPos = strpos($src, 'public static function extractScopeFromAbilities');
    expect($methodPos)->not->toBeFalse();

    $docblockPos = strrpos(substr($src, 0, (int) $methodPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $methodPos - (int) $docblockPos);

    expect($docblock)->toContain('R-PKG-046 F9-B06');
    expect($docblock)->toContain('Sanctum 4.x');
    expect($docblock)->toContain('{key: bool}');
});

test('R-PKG-046 F9-B06 — extractScopeFromAbilities() normaliza con is_string check', function () {
    $src = (string) file_get_contents(__DIR__.'/../../../src/Auth/Services/TokenIssuer.php');

    $methodPos = strpos($src, 'public static function extractScopeFromAbilities');
    $methodBody = substr($src, (int) $methodPos);

    // Debe normalizar assoc array antes de iterar.
    expect($methodBody)->toContain('is_string($key)');
    expect($methodBody)->toContain('$flat[] = $key');
    expect($methodBody)->toContain('is_string($value)');
});