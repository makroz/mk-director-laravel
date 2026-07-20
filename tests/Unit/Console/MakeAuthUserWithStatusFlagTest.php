<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Status enum — `mk:make:auth-user` post-R-PKG-047 D2 + D4.
 *
 * **R-PKG-047 D2 (2026-07-09 22:12)**: el flag `--with-status` se ELIMINÓ.
 * Status es ahora default ON. Para opt-out, `--no-status`. La flag `--status-values`
 * también se eliminó (los 4 estados canónicos son pineados forzosamente por la
 * agencia: Active/Inactive/Blocked/Pending).
 *
 * **R-PKG-047 D4 (2026-07-09)**: el enum per-scope pasó de int-backed configurable
 * a 4 cases hardcoded pineados por la agencia. El scaffolder emite un thin
 * wrapper que delega en `ScopeStatus` (enum canónico del paquete). NO hay
 * templating dinámico — los 4 states son fijos.
 *
 * **Revert (2026-07-19)**: D4 también había cambiado el backing type a string;
 * eso se revirtió a int (values canónicos 1..4). Los 4 estados pineados y la
 * eliminación de `--status-values` se conservan. Ver el docblock de
 * `ScopeStatus` para el análisis completo.
 *
 * Contrato pineado acá:
 *   - El command NO acepta `--with-status` (eliminado en D2).
 *   - El command acepta `--no-status` (D2 opt-out).
 *   - `enum-status.stub` existe, es int-backed, NO templatizado (los 4 cases
 *     pineados hardcoded), y delega a `ScopeStatus` (SSoT canónico del paquete).
 *   - El scaffolder cablea el placeholder `{{statusColumn}}`, `{{statusFillableEntry}}`,
 *     `{{statusCastEntry}}` y `{{statusRequestRuleStore/Update}}` (default ON post-D2).
 *
 * @see \Mk\Director\Auth\Enums\ScopeStatus
 */
uses(MkLaravelTestCase::class);

function packageRootStatus(): string
{
    return dirname(__DIR__, 3);
}

test('R-PKG-047 D2: --with-status flag está ELIMINADO (default ON, --no-status opt-out)', function () {
    $path = packageRootStatus().'/src/Console/Commands/MakeAuthUserCommand.php';

    expect((string) file_get_contents($path))->not->toContain('--with-status :');
    expect((string) file_get_contents($path))->toContain('--no-status :');
});

test('R-PKG-047 D4: enum-status.stub es thin wrapper int-backed (no templatizado)', function () {
    $stub = packageRootStatus().'/src/Stubs/auth-user/enum-status.stub';

    expect(file_exists($stub))->toBeTrue("enum-status.stub debe existir en {$stub}");

    $src = (string) file_get_contents($stub);

    // Revert 2026-07-19: int-backed (el backing string de D4 se revirtió; los
    // 4 estados canónicos y la eliminación de --status-values se conservan).
    expect($src)->toContain('enum {{ModuleName}}Status: int');

    // Los 4 cases pineados hardcoded con sus values canónicos 1..4
    // (no templating dinámico).
    expect($src)->toContain('case Active = 1;');
    expect($src)->toContain('case Inactive = 2;');
    expect($src)->toContain('case Blocked = 3;');
    expect($src)->toContain('case Pending = 4;');

    // D4: thin wrapper delega a ScopeStatus (SSoT canónico del paquete).
    expect($src)->toContain('use Mk\\Director\\Auth\\Enums\\ScopeStatus;');

    // D4: values() delega a ScopeStatus; default() retorna `self::Active`
    // (F10-B09: NO `ScopeStatus::Active` — clase distinta al return type
    // `self`, causaba TypeError en factories/seeders).
    expect($src)->toContain('return self::Active;');
    expect($src)->not->toContain('return ScopeStatus::Active;');
    expect($src)->toContain('return ScopeStatus::values();');

    // D4: canAuthenticate() y label() pineados.
    expect($src)->toContain('public function canAuthenticate(): bool');
    expect($src)->toContain('public function label(): string');

    // D4: NO hay templating dinámico (los placeholders pre-D4 se eliminaron).
    expect($src)->not->toContain('{{statusCases}}');
    expect($src)->not->toContain('{{statusDefaultCase}}');
    expect($src)->not->toContain('{{statusLabelArms}}');
});

test('R-PKG-047 D4: ScopeStatus enum canónico del paquete tiene los 4 estados + default()', function () {
    $path = packageRootStatus().'/src/Auth/Enums/ScopeStatus.php';
    expect(file_exists($path))->toBeTrue("ScopeStatus debe existir (R-PKG-047 D4 SSoT)");

    $src = (string) file_get_contents($path);

    // 4 estados canónicos de la agencia.
    expect($src)->toMatch('/case\s+Active\b/');
    expect($src)->toMatch('/case\s+Inactive\b/');
    expect($src)->toMatch('/case\s+Blocked\b/');
    expect($src)->toMatch('/case\s+Pending\b/');

    // default() retorna Active (convención de la agencia).
    expect($src)->toContain('public static function default(): self');
    expect($src)->toMatch('/return\s+self::Active/');

    // values() retorna los 4 values int canónicos.
    expect($src)->toContain('public static function values(): array');
});

test('R-PKG-047 D2: migration + model + request stubs tienen los placeholders de status (default ON post-D2)', function () {
    $migration = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user.migration.stub');
    $model = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user.model.stub');
    $storeRequest = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user/store-admin-request.stub');
    $updateRequest = (string) file_get_contents(packageRootStatus().'/src/Stubs/auth-user/update-admin-request.stub');

    // Los 3 placeholders pineados en migration + model.
    expect($migration)->toContain('{{statusColumn}}');
    expect($model)->toContain('{{statusFillableEntry}}');
    expect($model)->toContain('{{statusCastEntry}}');
    // El import del enum + el status en use statement (model).
    expect($model)->toContain('{{statusUseImport}}');

    // El request rule (R-PKG-047 D4) pinea en StoreRequest + UpdateRequest.
    expect($storeRequest)->toContain('{{statusRequestRuleStore}}');
    expect($updateRequest)->toContain('{{statusRequestRuleUpdate}}');
});

test('R-PKG-047 D2: command cablea los placeholders de status (default ON, no requiere flag)', function () {
    $src = (string) file_get_contents(packageRootStatus().'/src/Console/Commands/MakeAuthUserCommand.php');

    // El default de $withStatus es true (D2: default ON).
    // El código usa `! (bool) $this->option('no-status')` (con cast bool + espacios).
    expect($src)->toContain('$withStatus');
    expect($src)->toMatch('/\$withStatus\s*=\s*!\s*\(bool\)\s*\$this->option\(\s*[\'"]no-status[\'"]\s*\)/');

    // Cablea los 5 placeholders (3 originales + 2 request rules).
    expect($src)->toContain("'{{statusColumn}}'");
    expect($src)->toContain("'{{statusFillableEntry}}'");
    expect($src)->toContain("'{{statusCastEntry}}'");
    expect($src)->toContain("'{{statusRequestRuleStore}}'");
    expect($src)->toContain("'{{statusRequestRuleUpdate}}'");

    // Genera el enum del status.
    expect($src)->toContain("'auth-user/enum-status.stub'");
});
