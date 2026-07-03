<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-022 BUG-NEW-33 — scaffolder `--with-crud`
 * debe emitir el patrón completo `MkBelongsToMany::from($relation)` +
 * `->using(MkRoleUserPivot::class)` (HALLAZGO-NEW-04: scaffolder auto-aplica
 * patrones bien definidos, no solo los documenta).
 *
 * Pre-R-PKG-022 el scaffolder emitía solo `BelongsToMany` stock con
 * `wherePivot('user_type', static::class)`. Eso bypaseaba el fix BUG-NEW-31
 * (`MkBelongsToMany::from()`) — el override scaffoldeado no incluía la
 * `MkBelongsToMany::from($relation)` ni `->using(MkRoleUserPivot::class)`,
 * así que `$admin->roles()->attach([1])` directo fallaba con
 * `SQLSTATE: null value in column "user_type" violates not-null constraint`.
 *
 * Este test es source-parsing sobre `MakeAuthUserCommand.php` (patrón
 * R-PKG-012 — pinea strings críticos). No ejecuta el comando. Para
 * verificación end-to-end, ver audit e2e en sandbox-laravel (Bloque D
 * del SDD R-PKG-022).
 */
uses(MkLaravelTestCase::class);

function scaffolderSource(): string
{
    return (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php'
    );
}

// ── Bloque BUG-NEW-33 fix #1: rolesRelationOverride ──────────────────────

test('BUG-NEW-33 scaffolder: rolesRelationOverride emite ->using(MkRoleUserPivot::class)', function () {
    $src = scaffolderSource();

    expect($src)->toContain('\\Mk\\Director\\Auth\\Pivots\\MkRoleUserPivot::class');
});

test('BUG-NEW-33 scaffolder: rolesRelationOverride emite MkBelongsToMany::from()', function () {
    $src = scaffolderSource();

    expect($src)->toContain(
        '\\Mk\\Director\\Database\\Eloquent\\Relations\\MkBelongsToMany::from(\\$relation)'
    );
});

test('BUG-NEW-33 scaffolder: rolesRelationOverride mantiene FK explícita user_id (BUG-NEW-06 BC)', function () {
    $src = scaffolderSource();

    // R-PKG-015 BUG-NEW-06: FK override sigue presente (no se rompió con R-PKG-022).
    expect($src)->toContain("'user_id',\n            'role_id'");
});

test('BUG-NEW-33 scaffolder: rolesRelationOverride mantiene wherePivot user_type (MME-polimórfico)', function () {
    $src = scaffolderSource();

    // El wherePivot está dentro de un bloque heredoc y la sintaxis exacta puede
    // tener whitespace variable. Pineamos que la cadena `->wherePivot('user_type'`
    // aparezca al menos 2 veces (roles + directAbilities).
    $count = substr_count($src, "->wherePivot('user_type', static::class)");
    expect($count)->toBeGreaterThanOrEqual(2);
});

// ── Bloque BUG-NEW-33 fix #2: directAbilitiesRelationOverride ────────────

test('BUG-NEW-33 scaffolder: directAbilitiesRelationOverride emite ->using(MkAbilityUserPivot::class)', function () {
    $src = scaffolderSource();

    expect($src)->toContain('\\Mk\\Director\\Auth\\Pivots\\MkAbilityUserPivot::class');
});

test('BUG-NEW-33 scaffolder: directAbilitiesRelationOverride emite MkBelongsToMany::from()', function () {
    $src = scaffolderSource();

    // Hay 2 invocaciones a MkBelongsToMany::from() (roles + directAbilities).
    // Asegurar que aparecen por lo menos 2.
    $count = substr_count($src, '\\Mk\\Director\\Database\\Eloquent\\Relations\\MkBelongsToMany::from(');
    expect($count)->toBeGreaterThanOrEqual(2);
});

test('BUG-NEW-33 scaffolder: directAbilitiesRelationOverride mantiene FK explícita user_id', function () {
    $src = scaffolderSource();

    expect($src)->toContain("'user_id',\n            'ability_id'");
});

// ── R-G-032 pineo: comment R-PKG-022 BUG-NEW-33 presente ───────────────

test('BUG-NEW-33 scaffolder: comment R-PKG-022 documenta el fix (R-G-032 audit trail)', function () {
    $src = scaffolderSource();

    expect($src)->toContain('R-PKG-022 BUG-NEW-33');
    expect($src)->toContain('HALLAZGO-NEW-04');
});

// ── Negative assertion: el scaffolder NO emite el patrón viejo (drift detector) ─

test('BUG-NEW-33 scaffolder: NO emite el patrón viejo BelongsToMany stock sin from() (regression guard)', function () {
    $src = scaffolderSource();

    // El patrón viejo era:
    //   return \$this->belongsToMany(...)->wherePivot(...)->withTimestamps();
    //
    // El patrón nuevo es:
    //   \$relation = \$this->belongsToMany(...)
    //       ->using(...)->wherePivot(...)->withTimestamps();
    //   return MkBelongsToMany::from(\$relation);
    //
    // Buscamos el patrón viejo (return directo sin from) en los bloques PHP.
    // El bloque PHP está entre `<<<PHP` y `PHP` — extractamos solo esos bloques
    // y verificamos que NO haya `return \$this->belongsToMany(` directo.
    preg_match_all('/<<<PHP(.*?)PHP/s', $src, $matches);
    $heredocBodies = $matches[1] ?? [];

    foreach ($heredocBodies as $body) {
        // El patrón viejo (sin \$relation + MkBelongsToMany::from) sería:
        //   return \$this->belongsToMany(
        //       ...
        //   )->wherePivot(...)->withTimestamps();
        //
        // Pineamos que NO esté en ningún heredoc — si llega a estar,
        // alguien revirtió el fix.
        $hasLegacyReturn = preg_match(
            '/return\s+\\\$this->belongsToMany\([^)]*\)->wherePivot\(/s',
            $body
        );

        expect($hasLegacyReturn)->toBe(0, 'No debe haber patrón viejo `return \$this->belongsToMany(...)->wherePivot(...)` en ningún heredoc');
    }
});