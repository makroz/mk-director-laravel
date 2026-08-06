<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tenancy\TenantMembershipGate;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La regla de membresía usuario↔tenant (audit R2-004) vive en
 * {@see TenantMembershipGate}.
 *
 * 🔴 QUÉ MEDÍA ESTE ARCHIVO ANTES, Y POR QUÉ NO ALCANZÓ.
 *
 * Verificaba, leyendo `TenantResolver.php` como STRING, que el literal
 * `ERR_TENANT_MISMATCH` estuviera escrito y que apareciera entre el
 * `ERR_TENANT_MISSING` y el `$this->context->set`. Todo eso estaba, y estuvo
 * siempre — el archivo pasaba en verde mientras un admin del tenant A accedía a
 * los datos del tenant B con un 200.
 *
 * El bug era de ORDEN, no de contenido: `TenantResolver` va en el grupo `api` y
 * corre ANTES que `mk.auth:{scope}`, así que su `$request->user()` devolvía null
 * y la validación entera —correctamente escrita— quedaba adentro de un `if` que
 * nunca se cumplía. Grepear el .php prueba que alguien tipeó el chequeo, no que
 * el chequeo corra.
 *
 * La prueba de que la regla se APLICA es
 * `tests/Feature/Tenancy/TenantIsolationMiddlewareChainTest.php`: cadena de
 * middleware real, token real, 403 real. Este archivo se queda con lo único que
 * el source-parsing sí puede afirmar honestamente: DÓNDE vive la regla y quién
 * la llama — que es la parte que un refactor puede romper en silencio.
 */
uses(MkLaravelTestCase::class);

function tenancySource(string $file): string
{
    $path = dirname(__DIR__, 3).'/src/'.$file;

    expect(file_exists($path))->toBeTrue("{$path} debe existir");

    return (string) file_get_contents($path);
}

test('la regla de membresía vive en TenantMembershipGate (los dos códigos de error y el getTenantId)', function () {
    $src = tenancySource('Tenancy/TenantMembershipGate.php');

    expect($src)->toContain('ERR_TENANT_MISMATCH');
    expect($src)->toContain('ERR_TENANT_MEMBERSHIP_REQUIRED');
    expect($src)->toContain('getTenantId');
    expect($src)->toMatch('/errorResponse\(\s*403\s*,\s*[\'"]ERR_TENANT_MISMATCH[\'"]/s');
});

test('🔴 MkAuthenticate llama al gate — es la garantía de que el camino LLEGA a la validación', function () {
    $src = tenancySource('Auth/Middleware/MkAuthenticate.php');

    // Sin esta llamada volvemos exactamente al bug: la validación existe, está
    // bien escrita, y no corre nunca en el cableado por defecto.
    expect($src)->toContain('TenantMembershipGate');
    expect($src)->toContain('$this->tenantGate->check(');
});

test('🔴 el gate se llama ANTES de $next() — un 403 posterior llegaría con la escritura ya hecha', function () {
    $src = tenancySource('Auth/Middleware/MkAuthenticate.php');

    $checkPos = strpos($src, '$this->tenantGate->check(');
    $nextPos = strpos($src, 'return $next($request);');

    expect($checkPos)->toBeGreaterThan(0);
    expect($nextPos)->toBeGreaterThan(0);
    expect($checkPos)->toBeLessThan($nextPos);
});

test('TenantResolver sigue llamando al gate (cableado alternativo: resolver como middleware de ruta)', function () {
    $src = tenancySource('Tenancy/TenantResolver.php');

    expect($src)->toContain('$this->gate->check(');
});

test('TenantResolver sigue devolviendo 400 cuando falta el tenant (regresión)', function () {
    $src = tenancySource('Tenancy/TenantResolver.php');

    expect($src)->toContain('ERR_TENANT_MISSING');
    expect($src)->toMatch('/canonicalErrorResponse\(\s*400\s*,\s*[\'"]ERR_TENANT_MISSING[\'"]/s');
});
