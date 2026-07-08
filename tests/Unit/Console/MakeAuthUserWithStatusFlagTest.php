<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK A8 — `mk:make:auth-user --with-status`.
 *
 * Pinea el contrato del flag (source-parsing, sin bootear un app):
 *   - la signature expone `--with-status`
 *   - existe `enum-status.stub`, int-backed, templatizado ({{statusCases}}) + default()
 *   - la migración/modelo tienen los placeholders condicionales
 *   - el comando cablea los 3 placeholders y genera el enum del status
 *   - N8: los estados arrancan en 1 (nunca 0/falsy) y son configurables por CSV
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

test('enum-status.stub existe, es int-backed y está templatizado (N8)', function () {
    $stub = packageRootStatus().'/src/Stubs/auth-user/enum-status.stub';

    expect(file_exists($stub))->toBeTrue("enum-status.stub debe existir en {$stub}");

    $src = (string) file_get_contents($stub);
    // Backing NUMÉRICO (convención de la org), no string.
    expect($src)->toContain('enum {{ModuleName}}Status: int');
    // N8: los cases y labels salen de --status-values (placeholders), no hardcode.
    expect($src)->toContain('{{statusCases}}');
    expect($src)->toContain('self::{{statusDefaultCase}}');
    expect($src)->toContain('{{statusLabelArms}}');
    // N8: NO debe quedar el `Inactive = 0` (falsy) hardcodeado.
    expect($src)->not->toContain('case Inactive = 0;');
    // default() es la fuente de verdad del valor por defecto de la columna.
    expect($src)->toContain('public static function default(): self');
});

test('N8: resolveStatusStates arranca en 1 (nunca 0) y soporta CSV', function () {
    $command = new MakeAuthUserCommand;
    $method = new \ReflectionMethod($command, 'resolveStatusStates');
    $method->setAccessible(true);

    // Default (sin CSV) → Active=1, Inactive=2 (NUNCA 0).
    expect($method->invoke($command, ''))->toBe(['Active' => 1, 'Inactive' => 2]);

    // CSV custom → valores 1..N en orden.
    expect($method->invoke($command, 'Active,Inactive,Suspended'))
        ->toBe(['Active' => 1, 'Inactive' => 2, 'Suspended' => 3]);

    // Ningún valor puede ser 0 (falsy — colisiona con <MkSelect>).
    foreach ($method->invoke($command, 'Active,Inactive,Suspended') as $value) {
        expect($value)->toBeGreaterThanOrEqual(1);
    }
});

test('N8: el enum renderizado arranca en 1 y mapea labels', function () {
    $command = new MakeAuthUserCommand;

    $casesMethod = new \ReflectionMethod($command, 'buildStatusCases');
    $casesMethod->setAccessible(true);
    $armsMethod = new \ReflectionMethod($command, 'buildStatusLabelArms');
    $armsMethod->setAccessible(true);

    $states = ['Active' => 1, 'Inactive' => 2, 'Suspended' => 3];
    expect($casesMethod->invoke($command, $states))
        ->toContain('case Active = 1;')
        ->toContain('case Inactive = 2;')
        ->toContain('case Suspended = 3;');

    expect($armsMethod->invoke($command, $states))
        ->toContain("self::Suspended => 'Suspendido',");
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
