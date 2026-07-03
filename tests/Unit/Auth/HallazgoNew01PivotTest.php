<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-020 HALLAZGO-NEW-01 — `attach()` en HasRoles/HasAbilities no
 * incluye `user_type` automáticamente.
 *
 * El feedback de RETO fase 6 reportó: `$admin->roles()->attach([$id1, $id2])`
 * NO incluye `user_type` (falla con `NOT NULL constraint failed: role_user.user_type`).
 *
 * Solución de raíz aplicada en R-PKG-020: las relations `roles()` y
 * `directAbilities()` ahora usan `->using(MkRoleUserPivot::class)` /
 * `->using(MkAbilityUserPivot::class)`. Estas clases extienden `MkPivot`
 * (base abstracta) que registra un listener `creating` que setea
 * `user_type = get_class($pivot->pivotParent)` automáticamente cuando
 * la pivot tiene la columna.
 *
 * Estos tests pinean la integración via source-parsing (no requieren DB
 * activa, siguen el patrón mk-director-implementation.md § "Audit-driven
 * pre-tag discovery"). Si alguien revierte el `->using(...)` o borra el
 * listener, estos tests fallan.
 */
uses(MkLaravelTestCase::class);

test('MkRoleUserPivot class exists and extends MkPivot base', function () {
    expect(class_exists(\Mk\Director\Auth\Pivots\MkRoleUserPivot::class))->toBeTrue();
    expect(is_subclass_of(\Mk\Director\Auth\Pivots\MkRoleUserPivot::class, \Mk\Director\Auth\Pivots\MkPivot::class))->toBeTrue();
});

test('MkAbilityUserPivot class exists and extends MkPivot base', function () {
    expect(class_exists(\Mk\Director\Auth\Pivots\MkAbilityUserPivot::class))->toBeTrue();
    expect(is_subclass_of(\Mk\Director\Auth\Pivots\MkAbilityUserPivot::class, \Mk\Director\Auth\Pivots\MkPivot::class))->toBeTrue();
});

test('MkRoleUserPivot declares table = role_user', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Pivots/MkRoleUserPivot.php');
    expect($source)->toContain("protected \$table = 'role_user'");
});

test('MkAbilityUserPivot declares table = ability_user', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Pivots/MkAbilityUserPivot.php');
    expect($source)->toContain("protected \$table = 'ability_user'");
});

test('HasRoles::roles() uses MkRoleUserPivot (HALLAZGO-NEW-01 fix)', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasRoles.php');
    expect($source)->toContain('use Mk\\Director\\Auth\\Pivots\\MkRoleUserPivot');
    expect($source)->toContain('->using(MkRoleUserPivot::class)');
});

test('HasAbilities::directAbilities() uses MkAbilityUserPivot (HALLAZGO-NEW-01 fix)', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');
    expect($source)->toContain('use Mk\\Director\\Auth\\Pivots\\MkAbilityUserPivot');
    expect($source)->toContain('->using(MkAbilityUserPivot::class)');
});

test('MkPivot base has creating listener that sets user_type from pivotParent', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Pivots/MkPivot.php');
    expect($source)->toContain('protected static function boot(): void');
    expect($source)->toContain("static::creating(function (self \$pivot): void");
    expect($source)->toContain('Schema::hasColumn($table, \'user_type\')');
    expect($source)->toContain('$pivot->user_type = $pivot->pivotParent->getMorphClass()');
});

test('MkPivot listener respects explicit user_type (consumer override)', function () {
    // Verifica que el listener NO pisa user_type si el consumer ya lo seteó.
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Pivots/MkPivot.php');
    expect($source)->toContain('if ($pivot->user_type !== null)');
    expect($source)->toContain('return;');
});

test('MkPivot listener is no-op when pivot lacks user_type column (BC)', function () {
    // Si la pivot NO tiene columna user_type (consumer legacy), el listener
    // no debe hacer nada. Cache el resultado de hasColumn para evitar
    // queries repetidos.
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Pivots/MkPivot.php');
    expect($source)->toContain("self::\$userTypeColumnCache");
    expect($source)->toContain('Schema::hasColumn');
});
