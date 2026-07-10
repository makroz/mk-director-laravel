<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B03 — Migration respeta --profile-fields-required.
 *
 * **Bug pineado** (FEEDBACK9, RETO corrida 9):
 * El scaffolder pine `->nullable()` INCONDICIONALMENTE en la columna del
 * profile field, ignorando el flag `--profile-fields-required=<csv>` que el
 * consumer pasó. Resultado: si el consumer ejecutaba
 * `--profile-fields="!email" --profile-fields-required="email"`, la migration
 * pineaba `$table->string('email')->unique()->nullable()` aunque el FormRequest
 * validaba `required`. Un POST sin email fallaba con
 * `Integrity constraint violation: NOT NULL constraint failed: admins.email`
 * (500 SQL) en vez de `ValidationException` (422).
 *
 * **Fix**: la lógica del chain ahora respeta `$requiredFields`:
 *   - field en requiredFields → NO `->nullable()` (NOT NULL constraint).
 *   - field NO en requiredFields → `->nullable()` (BC default).
 *
 * Per HALLAZGO-NEW-03, pinea INTENCIÓN (source-parsing). EFECTIVIDAD se valida
 * en el consumer piloto RETO post-merge (smoke test E2E).
 */
uses(MkLaravelTestCase::class);

function makeAuthUserCommandSourceF9B03(): string
{
    $path = __DIR__.'/../../../src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('R-PKG-046 F9-B03 — migration column chain respeta --profile-fields-required', function () {
    $src = makeAuthUserCommandSourceF9B03();

    // El chain NO debe pinear ->nullable() incondicionalmente.
    // Pre-fix pineaba: `$chain = $unique ? '->unique()->nullable()' : '->nullable();`
    // Post-fix pinea: lógica condicional basada en $requiredFields.
    expect($src)->not->toContain(
        "\$chain = \$unique ? '->unique()->nullable()' : '->nullable();",
    );

    // Post-fix pinea explícitamente:
    expect($src)->toContain("\$isRequired = isset(\$requiredFields[\$key])");
    expect($src)->toContain("\$shouldBeNullable = ! \$isRequired");
});

test('R-PKG-046 F9-B03 — JSDoc explica el bug pineado', function () {
    $src = makeAuthUserCommandSourceF9B03();

    // JSDoc en el foreach que genera columns debe mencionar F9-B03.
    $columnsPos = strpos($src, '$columns .= "        \$table->');
    expect($columnsPos)->not->toBeFalse();

    // Buscar el docblock inmediatamente anterior.
    $docblockPos = strrpos(substr($src, 0, (int) $columnsPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $columnsPos - (int) $docblockPos);

    expect($docblock)->toContain('R-PKG-046 F9-B03');
    expect($docblock)->toContain('--profile-fields-required');
    expect($docblock)->toContain('NOT NULL constraint failed');
});

test('R-PKG-046 F9-B03 — chain logic: nullable solo si NO en requiredFields', function () {
    $src = makeAuthUserCommandSourceF9B03();

    // Pinear la lógica exacta del chain.
    expect($src)->toContain('if ($unique) {');
    expect($src)->toContain("\$chain .= '->unique()'");

    expect($src)->toContain('if ($shouldBeNullable) {');
    expect($src)->toContain("\$chain .= '->nullable()'");
});