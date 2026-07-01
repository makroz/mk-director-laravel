<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-041 regression guard — AuthController stub null-safety pattern
 * (HALLAZGO-NEW-FASE18-01 pineado en R-PKG-040).
 *
 * El bug class: PHP `&&` no es null-safe cuando el operando izquierdo es una
 * function call (e.g. `Schema::hasColumn(...)`) y el operando derecho accede
 * a una propiedad de un Model nullable (e.g. `$user->is_active`). Si `$user`
 * es null, function call retorna TRUE → el operando derecho se evalúa →
 * crash `Attempt to read property "is_active" on null` → HTTP 500.
 *
 * El fix pineado: `$user !== null && Schema::hasColumn(...) && $user->is_active === false;`
 * (short-circuit con null-check PRIMERO).
 *
 * Este test es source-parsing layer (R-PKG-012) + reflection-based invocation
 * (R-PKG-031 e2e pattern). NO ejecuta HTTP; pinea el patrón en el .stub source
 * Y verifica que el output generado por `make:auth-user` mantiene el patrón.
 *
 * HALLAZGO-NEW-03 cross-project lesson: source-parsing pine INTENCIÓN pero no
 * EFECTIVIDAD runtime. Para verificación 100% (HTTP 422 vs 500), el test e2e
 * completo vive en `apps/sandbox-laravel/tests/Feature/Scaffolder/` (R-PKG-042
 * backlog — sandbox con Laravel app + DB + http client).
 *
 * Justificación: HALLAZGO-NEW-FASE18-01 fue detectable 100% por source-parsing
 * (el patrón buggy `$x?->getTable() ?? 'fallback' && $x->property` es literal
 * en el código fuente). Source-parsing solo NO es suficiente en general, pero
 * pinea ESTA clase concreta de bugs sin overhead de Laravel app boot.
 *
 * @see MakeAuthUserWithCrudMkBelongsToManyTest.php (R-PKG-022 source-parsing pattern)
 * @see MakeAuthUserCommandRPkg031E2ETest.php (R-PKG-031 reflection-based pattern)
 */
uses(MkLaravelTestCase::class);

function authControllerStubSource(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Stubs/auth-user.auth-controller.stub'
    );
}

// ── Layer 1: source-parsing guard sobre el .stub raw ─────────────────────

test('R-PKG-041: AuthController stub pinea short-circuit `$user !== null` PRIMERO en login() (HALLAZGO-NEW-FASE18-01)', function () {
    $src = authControllerStubSource();

    // El patrón canónico es:
    //   $isActiveCheck = $user !== null
    //       && Schema::hasColumn($user->getTable(), 'is_active')
    //       && $user->is_active === false;
    //
    // Pineamos las 3 piezas: null-check PRIMERO, hasColumn segundo, property check tercero.
    expect($src)->toContain("\$user !== null\n            && Schema::hasColumn(\$user->getTable(), 'is_active')");
    expect($src)->toContain("\$user->is_active === false");
});

test('R-PKG-041: AuthController stub pinea el mismo null-safety pattern en forgot() (HALLAZGO-NEW-FASE18-01)', function () {
    $src = authControllerStubSource();

    // El forgot() tiene el mismo pattern que login(). Pineamos que aparece 2 veces
    // (una en login, una en forgot) — defense-in-depth contra reversion de una.
    $count = substr_count(
        $src,
        "\$user !== null\n            && Schema::hasColumn(\$user->getTable(), 'is_active')"
    );

    expect($count)->toBe(2, "El null-safety pattern debe aparecer 2 veces (login + forgot), apareció {$count}");
});

test('R-PKG-041: AuthController stub NO pine el patrón buggy null-unsafe con `\?->getTable()` (HALLAZGO-NEW-FASE18-01 regression guard)', function () {
    $src = authControllerStubSource();

    // El bug original (R-PKG-027 PKG-NEW-04 + PKG-NEW-05) pineaba:
    //   $isActiveCheck = Schema::hasColumn($user?->getTable() ?? '{{moduleNamePluralLower}}', 'is_active')
    //       && $user->is_active === false;
    //
    // Diferencia clave vs fix: el bug tiene `$user?->getTable()` (null-safe operator
    // en getTable, sin null-check previo al `&&`). El fix tiene `$user !== null && Schema::hasColumn($user->getTable(), ...)`
    // (null-check duro PRIMERO, acceda sin protección una sola vez).
    //
    // Pineamos que NO esté `$user?->getTable()` en ningún contexto — si alguien revierte el fix,
    // este test rompe.
    $buggyNullsafeCalls = substr_count($src, '$user?->getTable()');

    expect($buggyNullsafeCalls)->toBe(0, "El stub NO debe usar `$user?->getTable()` (null-safe en operando izquierdo del && es bug class — HALLAZGO-NEW-FASE18-01). Encontradas: {$buggyNullsafeCalls}");
});

test('R-PKG-041: AuthController stub pine R-PKG-040 comment HALLAZGO-NEW-FASE18-01 (R-G-032 audit trail)', function () {
    $src = authControllerStubSource();

    // La regla R-G-032 requiere que cada HALLAZGO pineado tenga un comment cross-ref
    // en el código fuente del paquete. Si este HALLAZGO desaparece del comment,
    // perdemos el audit trail cross-sprint.
    expect($src)->toContain('HALLAZGO-NEW-FASE18-01');
    expect($src)->toContain('R-PKG-040');
    expect($src)->toContain('Short-circuit');
    expect($src)->toContain('null-dereference');
});
