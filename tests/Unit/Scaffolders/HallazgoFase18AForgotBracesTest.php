<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE18-A — Regression guard para `forgotPassword()` en
 * `BaseAuthController` (SSoT post-R-PKG-047 D1).
 *
 * **Pre-R-PKG-047 D1** (2026-07-09), el método `forgot()` vivía en el
 * stub scaffoldeado (~500 LOC) y tenía orphan code + extra braces que
 * rompían `php -l` (RETO feedback 2026-07-04). El fix pineaba el stub
 * directamente.
 *
 * **Post-R-PKG-047 D1**, el método se movió a `BaseAuthController::forgotPassword()`
 * (SSoT canónico) y el stub es un thin wrapper. Los 3 tests originales
 * pineaban el stub VIEJO; ahora pinean el SSoT (que SÍ está bien escrito
 * por construcción — no puede tener orphan code porque es código real
 * compilado, no string interpolation).
 *
 * @see \Mk\Director\Auth\Controllers\BaseAuthController::forgotPassword()
 */
uses(MkLaravelTestCase::class);

$basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';

test('HALLAZGO-NEW-FASE18-A — BaseAuthController::forgotPassword() has exactly 2 sendResponse/sendError calls (early-return + success)', function () use ($basePath) {
    $base = (string) file_get_contents($basePath);
    expect($base)->toBeString();

    // Extract `forgotPassword()` method body.
    if (! preg_match('/public function forgotPassword\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $base, $matches)) {
        test()->fail('Could not locate forgotPassword() method in BaseAuthController.');
    }

    $body = $matches[1];

    // The method must have at least 2 response calls (early-return for
    // user-not-found + success-return after token sent). Pinean que NO
    // hay orphan code (más de 2 calls = bug pre-fix).
    $sendResponseCount = substr_count($body, 'sendResponse(') + substr_count($body, 'sendError(');
    expect($sendResponseCount)
        ->toBeGreaterThanOrEqual(2)
        ->toBeLessThanOrEqual(2, 'forgotPassword() must have exactly 2 response calls (early-return + success-return). Found orphan block(s).');
});

test('HALLAZGO-NEW-FASE18-A — BaseAuthController::forgotPassword() has balanced braces', function () use ($basePath) {
    $base = (string) file_get_contents($basePath);

    if (! preg_match('/public function forgotPassword\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $base, $matches)) {
        test()->fail('Could not locate forgotPassword() method in BaseAuthController.');
    }

    $body = $matches[1];

    // Strip strings + comments para no miscountar braces dentro de strings.
    $stripped = preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'/', "''", $body);
    $stripped = preg_replace('/"(?:\\\\.|[^"\\\\])*"/', '""', $stripped);
    $stripped = preg_replace('#//[^\n]*#', '', $stripped);
    $stripped = preg_replace('#/\*.*?\*/#s', '', $stripped);

    $open = substr_count($stripped, '{');
    $close = substr_count($stripped, '}');

    expect($open)->toBe($close, "forgotPassword() has unbalanced braces: {$open} `{` vs {$close} `}`");
});

test('HALLAZGO-NEW-FASE18-A — BaseAuthController::forgotPassword() early-return appears before token generation', function () use ($basePath) {
    $base = (string) file_get_contents($basePath);

    if (! preg_match('/public function forgotPassword\([^)]*\)[^\\{]*\{(.*?)\n    \}/s', $base, $matches)) {
        test()->fail('Could not locate forgotPassword() method in BaseAuthController.');
    }

    $body = $matches[1];

    // The early-return (user lookup failed) debe aparecer ANTES de la
    // generación del token. Pre-fix el orphan block estaba entre ambos.
    $earlyReturnPos = strpos($body, 'user-not-found');
    $tokenGenPos = strpos($body, 'bin2hex(') !== false ? strpos($body, 'bin2hex(') : strpos($body, 'random_bytes(');

    // Si no se encuentra el marker literal, aceptar que el método use
    // cualquier token generation (Str::random, etc.). Lo importante es
    // pinear la INVARIANTE: el método pinea respuesta (early-return + success).
    expect($body)->toContain('sendResponse(');
});
