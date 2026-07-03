<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-021 BUG-NEW-29 (HIGH) — tests source-parsing para pinear que
 * `mk:discover-abilities --force` es schema-aware (per-scope vs global).
 *
 * El feedback de RETO fase 7 reportó:
 *   - `mk:module X --with-rbac`         → tabla `{scope}_abilities` per-scope.
 *   - `mk:make:auth-user X --with-crud` → tabla `abilities` global (del paquete).
 *   - `mk:discover-abilities --force` SIEMPRE escribía en `{scope}_abilities`,
 *     fallando con `relation "{scope}_abilities" does not exist` cuando el
 *     consumer usa `--with-crud`.
 *
 * Fix R-PKG-021: `upsertAbilities()` ahora detecta qué tabla existe y
 * escribe ahí. Si per-scope existe → per-scope. Si NO → global. Si NINGUNA
 * existe → RuntimeException con mensaje accionable.
 */
uses(MkLaravelTestCase::class);

test('DiscoverAbilitiesCommand::upsertAbilities uses resolveAbilitiesTable helper', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('private function resolveAbilitiesTable(string $scope): string');
});

test('DiscoverAbilitiesCommand::resolveAbilitiesTable prefers per-scope table when exists', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('$perScopeTable = "{$scope}_abilities"');
    expect($source)->toContain('$globalTable = \'abilities\'');
    expect($source)->toContain('if ($perScopeExists)');
    expect($source)->toContain('return $perScopeTable;');
});

test('DiscoverAbilitiesCommand::resolveAbilitiesTable falls back to global table when per-scope missing', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('if ($globalExists)');
    expect($source)->toContain('return $globalTable;');
});

test('DiscoverAbilitiesCommand::resolveAbilitiesTable throws actionable error when neither table exists', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('throw new \\RuntimeException');
    expect($source)->toContain('php artisan migrate');
});

test('DiscoverAbilitiesCommand::tableExists uses DB::connection() schema builder with try/catch fallback', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('private function tableExists(string $table): bool');
    // Use the container-bound DatabaseManager to get the schema builder
    // (more robust than Schema facade which requires db.schema binding).
    expect($source)->toContain('$app->make(\'db\')->connection()');
    expect($source)->toContain('getSchemaBuilder()');
    expect($source)->toContain('hasTable($table)');
    // Pint reformats `catch (\Throwable)` with extra spaces; match a tolerant substring.
    // Note: source uses `Throwable` (imported via `use Throwable;`), so accept both.
    expect($source)->toMatch('/catch\\s*\\(\\s*\\\\?Throwable\\s*\\)/');
});
