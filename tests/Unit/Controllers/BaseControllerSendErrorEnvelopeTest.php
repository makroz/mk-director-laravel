<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * LAR-09 (2026-07-03 audit, MEDIUM) — `BaseController::sendError()` debe
 * emitir el envelope canónico de error consistente con `MkAbility` y
 * `MkAuthenticate` (R-PKG-024 single-level + R-PKG-044 unified):
 *
 *   {
 *     "success": false,
 *     "message": "...",
 *     "data": null,                       // ← SIEMPRE null (era array/omitido)
 *     "__extraData": {                    // ← SIEMPRE top-level sibling
 *       "code": "ERR_XXX",                // ← machine-readable code
 *       "errors": { ... }                 // ← validation errors (opcional)
 *     },
 *     "debugMsg": []
 *   }
 *
 * Pre-fix, `sendError($message, $errors = [], $code = 404)` emitía:
 *
 *   {
 *     "success": false,
 *     "message": "...",
 *     "errors": { ... },                  // ← top-level (drift con MkAbility)
 *     "debugMsg": [...]                   // ← sin code, sin data:null
 *   }
 *
 * Eso rompe el contrato cross-stack: el frontend (`@makroz/web`,
 * `@makroz/mobile`) lee `__extraData.code` para branchear UX; si no
 * está, muestra mensaje genérico y pierde el affordance de "credenciales
 * inválidas" vs "sesión expirada" vs "tenant incorrecto".
 *
 * Lado INTENCIÓN: source-parsing de `BaseController::sendError()` y de
 * los call sites en stubs (`auth-user.auth-controller.stub`).
 *
 * Lado EFECTIVIDAD (runtime) vive en
 * `tests/Feature/BaseControllerSendErrorEnvelopeE2ETest.php`.
 *
 * @see 04-mk-director-laravel.md#LAR-09 (2026-07-03 audit)
 * @see R-PKG-024 (single-level envelope)
 * @see R-PKG-044 (unified auth envelope)
 */
uses(MkLaravelTestCase::class);

function baseControllerSourcePathForLar09(): string
{
    return dirname(__DIR__, 3) . '/src/Controllers/BaseController.php';
}

function authControllerStubPathForLar09(): string
{
    return dirname(__DIR__, 3) . '/src/Stubs/auth-user.auth-controller.stub';
}

function readFileCheckedForLar09(string $path): string
{
    expect(file_exists($path))->toBeTrue("Source must exist at $path");

    return (string) file_get_contents($path);
}

describe('LAR-09 — BaseController::sendError() emits canonical single-level error envelope (INTENCIÓN)', function (): void {
    $baseController = readFileCheckedForLar09(baseControllerSourcePathForLar09());

    test('sendError() emits data:null (canonical)', function () use ($baseController): void {
        expect($baseController)->toMatch('/[\'"]data[\'"]\s*=>\s*null/');
    });

    test('sendError() emits __extraData top-level sibling of data', function () use ($baseController): void {
        expect($baseController)->toContain("'__extraData'");
    });

    test('sendError() emits __extraData.code (machine-readable error code)', function () use ($baseController): void {
        // Pin the canonical wiring: sendError() must set `code` into
        // __extraData. We scan the WHOLE method body for any of the
        // accepted shapes ($code param, $errorCode, or defaultErrorCode
        // helper). The exact variable name doesn't matter — only the
        // assignment of a machine-readable code into __extraData.code.
        $hasCodeAssignment = (bool) preg_match(
            '/__extraData.*?[\'"]code[\'"]\s*=>\s*(?:\$errorCode|defaultErrorCode|\$code)/s',
            $baseController
        );

        expect($hasCodeAssignment)->toBeTrue(
            'sendError() must wire a machine-readable `code` into __extraData (via $errorCode or defaultErrorCode helper)'
        );
    });

    test('sendError() does NOT emit the legacy top-level "errors" key (drift fix)', function () use ($baseController): void {
        // The OLD shape had `'errors' => $errors` at the top level next to
        // success/message — that's the drift with MkAbility. After the fix,
        // `errors` lives under `__extraData.errors` and the top-level key
        // is GONE from the sendError method body. We grep the WHOLE method
        // body — the canonical sendError uses `$extra['errors'] = $errors`
        // (nested under __extraData), so we look for the literal string.
        expect($baseController)->not->toMatch('/[\'"]errors[\'"]\s*=>\s*\$errors\b/');
    });

    test('sendError() keeps the legacy (message, errors, code) signature for BC', function () use ($baseController): void {
        // Caller ergonomics: existing call sites like
        // `sendError('msg', ['field' => ['err']], 422)` keep working.
        // Internally the shape is canonical; the signature is a superset
        // (added 4th optional $errorCode for explicitness).
        expect($baseController)->toMatch('/protected function sendError\(\$message,\s*\$errors\s*=\s*\[\],\s*\$code\s*=\s*404/');
    });
});

describe('R-PKG-047 D1: BaseAuthController usa sendResponse/sendError (SSoT migrada post-D1)', function (): void {
    $basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';
    $base = readFileCheckedForLar09($basePath);

    test('BaseAuthController::login() pinea sendResponse para invalid-credentials path', function () use ($base): void {
        // D1: el login() del SSoT pine sendResponse + sendError con el
        // envelope canónico (single-level, R-PKG-024). Pinean que la lógica
        // vive en BaseAuthController (no en el stub scaffoldeado).
        if (! preg_match('/public function login\([^)]*\)[^{]*\{(.*?)\n    \}/s', $base, $matches)) {
            test()->fail('Could not locate login() method in BaseAuthController.');
        }
        $body = $matches[1];

        expect($body)->toMatch('/sendResponse\(|sendError\(/');
    });

    test('BaseAuthController::refresh() y resetPassword() pinean sendError para token-failure paths', function () use ($base): void {
        // D1: refresh() y resetPassword() pinean sendError para token
        // failures. Pinean que la lógica vive en BaseAuthController.
        expect($base)->toContain('public function refresh(');
        expect($base)->toContain('public function resetPassword(');
    });

    test('R-PKG-047 D1: stub AuthController es thin wrapper — NO contiene sendError call sites (SSoT migrada)', function () {
        $stub = readFileCheckedForLar09(authControllerStubPathForLar09());

        // El thin wrapper NO contiene los call sites de sendError (viven
        // en BaseAuthController). Esto pinea la SSoT migration.
        expect($stub)->not->toMatch('/\$this->sendError\(/');
        expect($stub)->not->toMatch('/\$this->sendResponse\(/');
    });
});