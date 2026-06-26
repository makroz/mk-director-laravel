<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for pre-existing bugs found during R-PKG-012 e2e audit.
 *
 * Estos bugs estaban silenciosamente en v1.5.0-rc5 (publicado a Packagist).
 * Los tests source-parsing de R-PKG-011 los pineaban como strings en el
 * código, pero NUNCA ejecutaban el command real. El audit e2e los detectó
 * corriendo `php artisan mk:make:auth-user X --flags` en un sandbox.
 *
 * Spec: R-PKG-012 audit fixes (absorbidos en v1.6.0-rc1).
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRootAudit(): string
{
    return dirname(__DIR__, 3);
}

function commandSourceAudit(): string
{
    $path = packageRootAudit().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

// ── Bug #1: Signature parser ────────────────────────────────────────────
// v1.5.0-rc5: --verify-email description contenía /email/verify/{id}/{hash}
// que Laravel interpretaba como argumentos del command.
// Resultado: `php artisan mk:make:auth-user X` fallaba con
//   "Not enough arguments (missing: 'hash')"
// Fix: usar <id>/<hash> en lugar de {id}/{hash} (sin braces).

test('command signature does NOT contain literal {id}/{hash} (would break parser)', function () {
    $source = commandSourceAudit();

    // Extraer el $signature string. El bug era {id} y {hash} en la description
    // de --verify-email DENTRO del signature.
    preg_match('/protected \$signature = \x27(.+?)\x27;/s', $source, $m);
    expect($m)->toHaveCount(2, 'Signature property must exist with single-quoted string');
    $signature = $m[1];

    // Después del fix NO debe haber `/verify/{id}/{hash}` literal en el signature
    // (puede haber `{{id}}/{{hash}}` escapado, o `<id>/<hash>` en angle brackets).
    expect($signature)->not->toMatch('/\/verify\/\{id\}\/\{hash\}/');
    // Y SÍ debe haber la versión segura (con escape {{}} o con angle brackets).
    expect($signature)->toMatch('/\/verify\/(\{\{id\}\}\/\{\{hash\}\}|<id>\/<hash>)/');
});

// ── Bug #2: buildUpdateProfileMethod signature ──────────────────────────
// v1.5.0-rc5: buildUpdateProfileMethod recibía ($scope, $loginField, $rulesPhp)
// pero el template usaba $scopeLower (Undefined variable error).
// Resultado: `php artisan mk:make:auth-user X --profile-fields=Y` fallaba con
//   "Undefined variable $scopeLower"
// Fix: agregar $scopeLower como segundo parámetro.

test('buildUpdateProfileMethod signature includes $scopeLower', function () {
    $source = commandSourceAudit();

    expect($source)->toMatch('/protected function buildUpdateProfileMethod\(\s*string \$scope,\s*string \$scopeLower,\s*string \$loginField/');
});

test('handle() calls buildUpdateProfileMethod with $scopeLower', function () {
    $source = commandSourceAudit();

    // El call site debe pasar $scopeLower como segundo argumento.
    expect($source)->toMatch('/buildUpdateProfileMethod\(\s*\$scope,\s*\$scopeLower,\s*\$loginField/');
});

// ── Bug #3: `name` not in reserved columns ──────────────────────────────
// v1.5.0-rc5: --profile-fields=name causaba duplicate column
//   `$table->string('name')` (base scope) + `$table->string('name')->nullable()`
//   (profile field), rompiendo `php artisan migrate`.
// Fix: agregar 'name' a la lista reserved.

test('resolveProfileFields rejects "name" as reserved column (base scope)', function () {
    $source = commandSourceAudit();

    // 'name' debe estar en la lista reserved.
    expect($source)->toMatch("/reserved\s*=\s*\[[^\]]*'name'/s");
});

test('resolveProfileFields reserved list includes base scope columns', function () {
    $source = commandSourceAudit();

    // Pineamos que la lista reserved incluye TODAS las columnas base del scope.
    // Si falta alguna, será un duplicate column en la migration generada.
    foreach (['id', 'name', 'password', 'auth_scope', 'client_id', 'remember_token'] as $col) {
        expect($source)->toMatch("/reserved\s*=\s*\[[^\]]*'$col'/s");
    }
});
