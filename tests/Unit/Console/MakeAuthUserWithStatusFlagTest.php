<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK A8 — `mk:make:auth-user --with-status`.
 *
 * Pinea el contrato del flag (source-parsing, sin bootear un app):
 *   - la signature expone `--with-status`
 *   - existe `enum-status.stub`, int-backed (Active=1, Inactive=0) + default()
 *   - la migración/modelo tienen los placeholders condicionales
 *   - el comando cablea los 3 placeholders y genera el enum del status
 *
 * Antes de A8 no había enum de status ni flag: cada scope tenía que crear
 * el enum + la columna a mano.
 */
uses(MkLaravelTestCase::class);

function packageRootStatus(): string
{
    return dirname(__DIR__, 3);
}

test('command signature incluye --with-status option', function () {
    $path = packageRootStatus().'/src/Console/Commands/MakeAuthUserCommand.php';

    expect((string) file_get_contents($path))->toContain('--with-status :');
});

test('enum-status.stub existe y es int-backed con Active=1/Inactive=0', function () {
    $stub = packageRootStatus().'/src/Stubs/auth-user/enum-status.stub';

    expect(file_exists($stub))->toBeTrue("enum-status.stub debe existir en {$stub}");

    $src = (string) file_get_contents($stub);
    // Backing NUMÉRICO (convención de la org), no string.
    expect($src)->toContain('enum {{ModuleName}}Status: int');
    expect($src)->toContain('case Active = 1;');
    expect($src)->toContain('case Inactive = 0;');
    // default() es la fuente de verdad del valor por defecto de la columna.
    expect($src)->toContain('public static function default(): self');
});

test('migration + model stubs tienen los placeholders condicionales de status', function () {
    $migration = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user.migration.stub');
    $model = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user.model.stub');

    expect($migration)->toContain('{{statusColumn}}');
    expect($model)->toContain('{{statusFillableEntry}}');
    expect($model)->toContain('{{statusCastEntry}}');
});

test('el comando cablea los placeholders de status y genera el enum', function () {
    $src = (string) file_get_contents(packageRootStatus().'/src/Console/Commands/MakeAuthUserCommand.php');

    // Lee el flag.
    expect($src)->toContain("\$withStatus = (bool) \$this->option('with-status');");
    // Define y mergea los 3 placeholders (default vacío = BC sin flag).
    expect($src)->toContain("'{{statusColumn}}'");
    expect($src)->toContain("'{{statusFillableEntry}}'");
    expect($src)->toContain("'{{statusCastEntry}}'");
    expect($src)->toContain('$statusReplacements');
    // El default de la columna sale del propio enum (única fuente de verdad).
    expect($src)->toContain('::default()->value');
    // Genera el enum del status.
    expect($src)->toContain("'auth-user/enum-status.stub'");
});
