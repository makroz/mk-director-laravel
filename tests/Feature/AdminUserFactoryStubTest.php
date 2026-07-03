<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Tests for `mk:make:auth-user` stub: `admin-factory.stub` (R-PKG-019 BUG-NEW-28).
 *
 * El bug: el stub emitía `'email_verified_at' => now()` SIEMPRE. Si el
 * scaffolder se llamaba SIN `--verify-email`, la tabla `admins` NO tiene
 * columna `email_verified_at`, y la factory crasheaba con
 * `SQLSTATE[HY000]: General error: 1 table admins has no column named email_verified_at`.
 *
 * Fix (R-PKG-019 BUG-NEW-28): el stub ahora envuelve `email_verified_at`
 * en `if (Schema::hasColumn(...))` para que solo se incluya si la tabla
 * tiene esa columna. BC-safe: con `--verify-email` la factory funciona
 * idéntico a antes.
 *
 * Patrón de tests (source-parsing + render + syntax check):
 *   1. Source-parsing: el stub tiene el check `Schema::hasColumn` (rápido, sin runtime).
 *   2. Stub no hardcodea `email_verified_at` fuera del if.
 *   3. Render: el stub con placeholders reemplazados compila como PHP válido
 *      (php -l confirma sintaxis correcta).
 *
 * ¿Por qué no e2e runtime con Schema facade? Porque requiere bootstrap
 * completo de Laravel + DB connection + Container. El source-parsing +
 * syntax check son suficientes para pinear la regresión (el fix es
 * trivial — agregar un if).
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    if (isset($this->tempFile) && file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

// ─── Source-parsing tests (rápidos, sin DB) ──────────────────────────────

test('R-PKG-019 BUG-NEW-28: admin-factory.stub uses Schema::hasColumn check for email_verified_at', function () {
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/auth-user/admin-factory.stub');

    // El stub DEBE importar Schema facade.
    expect($stub)->toMatch('/use\s+Illuminate\\\\Support\\\\Facades\\\\Schema;/');

    // El stub DEBE usar Schema::hasColumn para chequear email_verified_at.
    expect($stub)->toContain('Schema::hasColumn(');
    expect($stub)->toContain("'email_verified_at'");

    // El check DEBE envolver el `'email_verified_at' => now()`.
    // Patrón: `if (Schema::hasColumn(...)) { $base['email_verified_at'] = now(); }`.
    // El `[^{]*` permite whitespace entre el `)` y el `{` (multi-line).
    expect($stub)->toMatch('/if\s*\(\s*Schema::hasColumn\([^)]+\)[^{]*\{[^}]*\}/s');
});

test('R-PKG-019 BUG-NEW-28: admin-factory.stub no longer hardcodes email_verified_at at definition() top-level', function () {
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/auth-user/admin-factory.stub');

    // Patrón legacy (BUG): `'email_verified_at' => now()` literal sin condicionar.
    // Patrón fixed: el `'email_verified_at' => now()` está dentro del `if (Schema::hasColumn(...))`.
    expect($stub)->not->toMatch("/'email_verified_at'\s*=>\s*now\(\),?\s*$/m");
});

// ─── Render + syntax check tests ─────────────────────────────────────────

test('R-PKG-019 BUG-NEW-28: stub renders valid PHP (php -l passes)', function () {
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/auth-user/admin-factory.stub');

    // Reemplazos básicos (lo que hace el scaffolder real).
    $rendered = str_replace(
        ['{{ModuleName}}', '{{moduleNameLower}}'],
        ['Admin', 'admin'],
        $stub
    );

    // Debe compilar como PHP válido.
    $tmpFile = tempnam(sys_get_temp_dir(), 'mk-factory-test-').'.php';
    file_put_contents($tmpFile, $rendered);
    $this->tempFile = $tmpFile;

    $syntaxCheck = shell_exec("php -l '$tmpFile' 2>&1");
    expect($syntaxCheck)->toContain('No syntax errors detected');
});

test('R-PKG-019 BUG-NEW-28: rendered stub has correct Schema::hasColumn wrapping', function () {
    $stub = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/auth-user/admin-factory.stub');

    $rendered = str_replace(
        ['{{ModuleName}}', '{{moduleNameLower}}'],
        ['Member', 'member'],
        $stub
    );

    // El rendered NO debe tener `'email_verified_at' => now()` literal.
    expect($rendered)->not->toMatch("/'email_verified_at'\s*=>\s*now\(\),?\s*$/m");

    // El rendered SÍ debe tener el check Schema::hasColumn envolviendo email_verified_at.
    expect($rendered)->toMatch('/if\s*\(\s*Schema::hasColumn\([^)]+\)[^{]*\{[^}]*\}/s');

    // Y debe tener `Schema::hasColumn((new Member())->getTable()` (rendered con el module name).
    expect($rendered)->toContain("Schema::hasColumn((new Member())->getTable()");
});