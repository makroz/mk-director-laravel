<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing + functional tests para R-PKG-047 D2 — Scaffolder reduction + defaults.
 *
 * HALLAZGO-NEW-03 (cross-project binding): estos tests pinean INTENCIÓN del
 * refactor del scaffolder (6 flags → 3+opt-out). NO pinean EFECTIVIDAD
 * runtime (eso vive en AuthUserCompleteFlowE2ETest.php — Feature con DB).
 *
 * Contrato pineado acá:
 *   - Signature: eliminados --with-crud/--with-auth-rbac/--with-status/--status-values;
 *     agregados --no-crud/--no-rbac/--no-status como opt-out (defaults ON).
 *   - defaultProfileFields() pinea 5 columnas baseline.
 *   - resolveProfileFieldsWithDefaults() fail-fast si user pinea 'name'.
 *   - auth-user.auth-controller.stub reemplazado por thin wrapper ~30 LOC.
 */
uses(MkLaravelTestCase::class);

function packageRoot047(): string
{
    return dirname(__DIR__, 3);
}

function scaffolderSource047(): string
{
    return (string) file_get_contents(packageRoot047() . '/src/Console/Commands/MakeAuthUserCommand.php');
}

function stubSource047(string $name): string
{
    $path = packageRoot047() . "/src/Stubs/{$name}";
    if (! file_exists($path)) {
        throw new RuntimeException("Stub $name NOT FOUND at $path");
    }

    return (string) file_get_contents($path);
}

// ── F2.2 — Signature: --with-crud/--with-auth-rbac/--with-status eliminados ─

it('D2: --with-crud flag está ELIMINADO de la signature (post-D2 default ON)', function () {
    expect(scaffolderSource047())->not->toContain('{--with-crud ');
});

it('D2: --with-auth-rbac flag está ELIMINADO de la signature', function () {
    expect(scaffolderSource047())->not->toContain('{--with-auth-rbac ');
});

it('D2: --with-status flag está ELIMINADO de la signature (4 estados SSoT post-D4)', function () {
    expect(scaffolderSource047())->not->toContain('{--with-status ');
});

it('D2: --no-crud flag pineado en signature (opt-out del default ON)', function () {
    expect(scaffolderSource047())->toContain('{--no-crud ');
});

it('D2: --no-rbac y --no-status flags pineados en signature', function () {
    $src = scaffolderSource047();

    expect($src)->toContain('{--no-rbac ');
    expect($src)->toContain('{--no-status ');
});
