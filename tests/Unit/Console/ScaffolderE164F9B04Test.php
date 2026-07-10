<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B04 — Scaffolder pinea validación E.164 para phone.
 *
 * **Bug pineado** (FEEDBACK9, RETO corrida 9):
 * El scaffolder pine `'string'` para campos phone-like, sin regex E.164. El
 * frontend pineaba `+59170123456` (E.164 Bolivia), pero un POST con
 * `phone: "70123456"` (formato nacional Bolivia sin prefijo) pasaba la validación
 * del backend. Drift client/server.
 *
 * **Fix**: heurística en `buildProfileFieldRules()` — si el field name matchea
 * `/^(phone|tel|telefono|mobile|cellphone|whatsapp)$/i`, pinear
 * `regex:/^\+[1-9]\d{1,14}$/'` automáticamente (E.164: prefijo `+` + 1-15
 * dígitos, sin espacios/guiones).
 *
 * Per HALLAZGO-NEW-03, pinea INTENCIÓN (source-parsing). EFECTIVIDAD se valida
 * en el consumer piloto RETO post-merge (smoke test E2E).
 */
uses(MkLaravelTestCase::class);

function makeAuthUserCommandSourceF9B04(): string
{
    $path = __DIR__.'/../../../src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('R-PKG-046 F9-B04 — buildProfileFieldRules() pine regex E.164 para phone-like fields', function () {
    $src = makeAuthUserCommandSourceF9B04();

    // La heurística debe matchear phone/tel/telefono/mobile/cellphone/whatsapp.
    expect($src)->toContain(
        "preg_match('/^(phone|tel|telefono|mobile|cellphone|whatsapp)\$/i', \$key)"
    );

    // El regex pineado contiene E.164 pattern markers. Usar búsqueda parcial
    // para evitar lío con backslash escaping (single vs double quote).
    expect($src)->toContain('regex:/^');
    expect($src)->toContain('[1-9]');
    expect($src)->toContain('d{1,14}');

    // pinea tanto store como update.
    expect($src)->toContain("\$storeRules[] = ");
    expect($src)->toContain("\$updateRules[] = ");
});

test('R-PKG-046 F9-B04 — JSDoc explica heurística + casos custom', function () {
    $src = makeAuthUserCommandSourceF9B04();

    // Localizar la heurística y su JSDoc inmediatamente anterior.
    $heurPos = strpos($src, "preg_match('/^(phone|tel|telefono|mobile|cellphone|whatsapp)\$/i', \$key)");
    expect($heurPos)->not->toBeFalse();

    $docblockPos = strrpos(substr($src, 0, (int) $heurPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $heurPos - (int) $docblockPos);

    expect($docblock)->toContain('R-PKG-046 F9-B04');
    expect($docblock)->toContain('E.164');
    expect($docblock)->toContain('Bolivia');
    expect($docblock)->toContain('drift');
});