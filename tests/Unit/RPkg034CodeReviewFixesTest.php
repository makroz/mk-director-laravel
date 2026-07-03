<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-034 — Code review 4R sprint (2026-06-29) — source-parsing tests.
 *
 * Pinea los 4 fixes concretos que se aplicaron al paquete en respuesta al
 * code review externo. HALLAZGO-NEW-03 (cross-project lesson): source-parsing
 * pinea INTENCIÓN (estructura OK), NO EFECTIVIDAD (runtime funciona). El runtime
 * end-to-end se valida en el consumer (RETO) en su sprint aparte.
 *
 * Fijos pineados:
 *  - BUG-NEW-33: MkModuleServiceInterface::beforeUpdate/afterUpdate/beforeDelete/afterDelete
 *                 ahora aceptan `string|int $id` (UUID support, R-PKG-016 alignment).
 *  - BUG-NEW-34: DiscoverAbilitiesCommand::discoverClassesInDir() ahora loguea
 *                 `Log::warning` cuando un `require_once` falla (en vez de catch
 *                 silencioso).
 *  - BUG-NEW-35: SearchManager::searchStatic() + helpers estáticos eliminados
 *                 (código muerto legacy de v0.x con naming español).
 *  - BUG-NEW-36: HasRoles::assignRole()/syncRoles() ya no tienen docblocks
 *                 duplicados (artifact de merge cleanup).
 */
uses(MkLaravelTestCase::class);

// ============================================================================
// BUG-NEW-33: MkModuleServiceInterface UUID support
// ============================================================================

test('R-PKG-034 BUG-NEW-33: MkModuleServiceInterface::beforeUpdate accepts string|int $id', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    expect($source)->toContain('public function beforeUpdate(Request $request, string|int $id, array $input): array');
});

test('R-PKG-034 BUG-NEW-33: MkModuleServiceInterface::afterUpdate accepts string|int $id', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    expect($source)->toContain('public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed');
});

test('R-PKG-034 BUG-NEW-33: MkModuleServiceInterface::beforeDelete accepts string|int $id', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    expect($source)->toContain('public function beforeDelete(Request $request, Model $model, string|int $id): bool');
});

test('R-PKG-034 BUG-NEW-33: MkModuleServiceInterface::afterDelete accepts string|int $id', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    expect($source)->toContain('public function afterDelete(Request $request, Model $model, string|int $id): mixed');
});

test('R-PKG-034 BUG-NEW-33: NO legacy `int $id` remains in MkModuleServiceInterface hooks', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    // Pineado: el pineo pinea alineación con R-PKG-016 BUG-NEW-20 (CRUDSmart
    // show/update ya aceptaban string|int $id desde v1.6.x). Si alguien revierte
    // el fix, este test falla y queda explícito.
    // Regex anchors on `Request $request,` (start of params block) and looks
    // for `int $id` WITHOUT a preceding `string|`. The current signatures
    // use `string|int $id`, which this regex must NOT match.
    expect($source)->not->toMatch('/public function (beforeUpdate|afterUpdate|beforeDelete|afterDelete)\([^)]*Request \$request,[^)]*(?<!string\|)int \$id/');
});

test('R-PKG-034 BUG-NEW-33: docblock reference pinea R-PKG-016 + R-PKG-034 IDs', function () {
    $source = file_get_contents(__DIR__.'/../../src/Contracts/MkModuleServiceInterface.php');
    expect($source)->toContain('R-PKG-016');
    expect($source)->toContain('R-PKG-034 BUG-NEW-33');
});

// ============================================================================
// BUG-NEW-34: DiscoverAbilitiesCommand require_once observability
// ============================================================================

test('R-PKG-034 BUG-NEW-34: DiscoverAbilitiesCommand imports Log facade', function () {
    $source = file_get_contents(__DIR__.'/../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    expect($source)->toContain('use Illuminate\\Support\\Facades\\Log');
});

test('R-PKG-034 BUG-NEW-34: DiscoverAbilitiesCommand::discoverClassesInDir logs warning on require_once failure', function () {
    $source = file_get_contents(__DIR__.'/../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    // The catch in discoverClassesInDir() must call Log::warning BEFORE `continue`.
    // We assert the call-site exists in the discoverClassesInDir method body.
    $methodStart = strpos($source, 'private function discoverClassesInDir(string $dir): array');
    expect($methodStart)->not->toBeFalse();

    // Slice the method body until the next `private function` or end of class.
    $body = substr($source, (int) $methodStart);
    $nextMethod = strpos($body, "\n    private function", 100);
    if ($nextMethod !== false) {
        $body = substr($body, 0, $nextMethod);
    }

    expect($body)->toContain('Log::warning');
    expect($body)->toContain('require_once falló durante class discovery');
    expect($body)->toContain('$realPath');
    expect($body)->toContain('$e->getMessage()');
});

test('R-PKG-034 BUG-NEW-34: discoverClassesInDir still continues (BC-safe skip behavior)', function () {
    $source = file_get_contents(__DIR__.'/../../src/Console/Commands/DiscoverAbilitiesCommand.php');
    $methodStart = strpos($source, 'private function discoverClassesInDir(string $dir): array');
    expect($methodStart)->not->toBeFalse();
    $body = substr($source, (int) $methodStart);
    $nextMethod = strpos($body, "\n    private function", 100);
    if ($nextMethod !== false) {
        $body = substr($body, 0, $nextMethod);
    }
    // `continue` after Log::warning — pre-fix behavior preserved.
    expect($body)->toMatch('/Log::warning[\s\S]{0,800}continue;/');
});

// ============================================================================
// BUG-NEW-35: SearchManager.searchStatic removed
// ============================================================================

test('R-PKG-034 BUG-NEW-35: SearchManager no longer declares searchStatic()', function () {
    $source = file_get_contents(__DIR__.'/../../src/Managers/SearchManager.php');
    expect($source)->not->toContain('public static function searchStatic');
    expect($source)->not->toContain('private static function searchStatic');
});

test('R-PKG-034 BUG-NEW-35: SearchManager no longer declares legacy static helpers', function () {
    $source = file_get_contents(__DIR__.'/../../src/Managers/SearchManager.php');
    expect($source)->not->toContain('private static function getJoinType');
    expect($source)->not->toContain('private static function getBusqueda');
    expect($source)->not->toContain('private static function isEndGroup');
    expect($source)->not->toContain('private static function resetGroupEnd');
    expect($source)->not->toContain('private static function isStartNestedGroup');
    expect($source)->not->toContain('private static function handleNestedGroup');
    expect($source)->not->toContain('private static function applySearchCriteria');
});

test('R-PKG-034 BUG-NEW-35: SearchManager no longer declares REPEATED_COMAS sentinel', function () {
    $source = file_get_contents(__DIR__.'/../../src/Managers/SearchManager.php');
    // We expect the docstring mentioning REPEATED_COMAS as historical context,
    // but the runtime constant declaration MUST be gone. Strip comments and
    // assert no `const REPEATED_COMAS` line survives.
    $codeOnly = preg_replace('!/\*.*?\*/!s', '', $source);
    $codeOnly = preg_replace('!//.*!', '', (string) $codeOnly);
    expect($codeOnly)->not->toMatch('/const\s+REPEATED_COMAS/');
    // The legacy sentinel was 7 consecutive commas wrapped in apostrophes
    // (`',,,,,,'`); the modern `parse()` legitimately uses `explode(',', ...)`
    // with a single comma, which we MUST NOT regress.
    expect($codeOnly)->not->toMatch("/',{4,}'/");
});

test('R-PKG-034 BUG-NEW-35: SearchManager still exposes the modern search() API', function () {
    $source = file_get_contents(__DIR__.'/../../src/Managers/SearchManager.php');
    // Pint may rewrite the FQN into a `use` import — accept either form.
    expect($source)->toMatch('#public function search\((\\\\Illuminate\\\\Database\\\\Eloquent\\\\)?Builder#');
    expect($source)->toContain('public function parse(string $search): array');
    expect($source)->toContain('public function setStrategy');
});

test('R-PKG-034 BUG-NEW-35: source has R-PKG-034 removal note', function () {
    $source = file_get_contents(__DIR__.'/../../src/Managers/SearchManager.php');
    expect($source)->toContain('R-PKG-034 BUG-NEW-35');
    expect($source)->toContain('legacy de v0.x');
});

// ============================================================================
// BUG-NEW-36: HasRoles duplicate docblocks removed
// ============================================================================

test('R-PKG-034 BUG-NEW-36: HasRoles::assignRole has a single canonical docblock', function () {
    $source = file_get_contents(__DIR__.'/../../src/Auth/Concerns/HasRoles.php');

    // Extract the assignRole method body (between its opening and next function decl).
    $methodStart = strpos($source, 'public function assignRole(string|Role $role): void');
    expect($methodStart)->not->toBeFalse();

    $body = substr($source, (int) $methodStart);
    $nextFn = strpos($body, 'public function', 100);
    if ($nextFn !== false) {
        $body = substr($body, 0, $nextFn);
    }

    // Pre-fix: assignRole had TWO consecutive `/**` docblocks (artifact of merge
    // cleanup). Post-fix: exactly ONE.
    preg_match_all('/\/\*\*/', $body, $matches);
    expect(count($matches[0]))->toBe(1);
});

test('R-PKG-034 BUG-NEW-36: HasRoles::syncRoles has a single canonical docblock', function () {
    $source = file_get_contents(__DIR__.'/../../src/Auth/Concerns/HasRoles.php');

    $methodStart = strpos($source, 'public function syncRoles(array $roles): void');
    expect($methodStart)->not->toBeFalse();

    $body = substr($source, (int) $methodStart);
    $nextFn = strpos($body, 'public function', 100);
    if ($nextFn !== false) {
        $body = substr($body, 0, $nextFn);
    }

    preg_match_all('/\/\*\*/', $body, $matches);
    expect(count($matches[0]))->toBe(1);
});

test('R-PKG-034 BUG-NEW-36: HasRoles::assignRole docblock preserves R-PKG-016 fix reference', function () {
    $source = file_get_contents(__DIR__.'/../../src/Auth/Concerns/HasRoles.php');
    expect($source)->toContain('R-PKG-016 BUG-NEW-16');
});

test('R-PKG-034 BUG-NEW-36: HasRoles::syncRoles docblock preserves R-PKG-016 fix reference', function () {
    $source = file_get_contents(__DIR__.'/../../src/Auth/Concerns/HasRoles.php');
    expect($source)->toContain('R-PKG-016 BUG-NEW-16');
});
