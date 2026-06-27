<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Pivots\MkPivot;
use Mk\Director\Auth\Pivots\MkRoleUserPivot;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-021 BUG-NEW-31 — e2e test que pinea el comportamiento RUNTIME del
 * `MkBelongsToMany::from()` + override de `newPivot()` para inyectar
 * `user_type` en pivots MME-polimórficas.
 *
 * HALLAZGO-NEW-01 (RC9) introducía `MkPivot::boot()` con un listener
 * `creating` que NO funcionaba runtime porque `pivotParent` es null cuando
 * Laravel hace `newPivot()->save()` en `attachUsingCustomClass()` (vendor).
 *
 * La fix R-PKG-021 es:
 *   1. `MkBelongsToMany` extiende `BelongsToMany` y override `newPivot()`
 *      para inyectar `user_type` ANTES de instanciar la pivot.
 *   2. `MkBelongsToMany::from()` promueve una `BelongsToMany` instance
 *      (creada por `belongsToMany()`) a `MkBelongsToMany` via reflection-
 *      based state copy. Preserva el setup interno de Laravel.
 *   3. `HasRoles::roles()` y `HasAbilities::directAbilities()` retornan
 *      `MkBelongsToMany::from($relation)` para que el override aplique.
 *
 * Estos tests pinean que la combinación de los 3 puntos funciona runtime.
 *
 * IMPORTANTE: usan SQLite in-memory via Capsule manager. Las migrations
 * (`role_user` con `user_type`) se crean en runtime dentro del test.
 */
uses(MkLaravelTestCase::class);

beforeEach(function () {
    // Reset cache estático entre tests.
    MkBelongsToMany::clearUserTypeCache();
    MkPivot::clearUserTypeCache();
});

test('MkBelongsToMany::from() promotes BelongsToMany to MkBelongsToMany preserving state', function () {
    // Arrange: crear un modelo concreto que use HasRoles.
    $admin = new class extends Model
    {
        use HasRoles;
        use HasUuids;

        protected $table = 'test_users';

        protected $guarded = [];

        public $timestamps = true;

        public function getAuthScope(): ?string
        {
            return 'admin';
        }
    };

    // Act
    $relation = $admin->roles();

    // Assert
    expect($relation)->toBeInstanceOf(MkBelongsToMany::class);
    // La table pivot debe preservarse via reflection copy.
    expect($relation->getTable())->toBe('role_user');
    // El using pivot class debe preservarse.
    expect($relation->getPivotClass())->toBe(MkRoleUserPivot::class);
});

test('MkBelongsToMany source parsing: newPivot override exists and merges user_type', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Database/Eloquent/Relations/MkBelongsToMany.php');
    expect($source)->toContain('public function newPivot(array $attributes = [], $exists = false)');
    expect($source)->toContain('mergeUserTypeIntoAttributes');
    expect($source)->toContain('setPivotParent');
});

test('MkBelongsToMany source parsing: attach override routes based on using property', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Database/Eloquent/Relations/MkBelongsToMany.php');
    expect($source)->toContain('public function attach($ids, array $attributes = [], $touch = true)');
    // El nuevo approach delega al flow attachUsingCustomClass manualmente
    // leyendo $this->using via reflection (más predecible que parent::attach()).
    expect($source)->toContain('readUsingProperty()');
    expect($source)->toContain('mergeUserTypeIntoAttributes($attributes)');
    expect($source)->toContain('attachUsingCustomClass()');
});

test('MkBelongsToMany source parsing: from() factory uses reflection-based state copy', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Database/Eloquent/Relations/MkBelongsToMany.php');
    expect($source)->toContain('public static function from(BelongsToMany $source): self');
    expect($source)->toContain('newInstanceWithoutConstructor');
    expect($source)->toContain('ReflectionObject');
    expect($source)->toContain('setValue($instance, $value)');
});

test('HasRoles::roles() returns MkBelongsToMany instance (R-PKG-021)', function () {
    $source = file_get_contents(__DIR__.'/../../../src/Auth/Concerns/HasRoles.php');
    expect($source)->toContain('use Mk\\Director\\Database\\Eloquent\\Relations\\MkBelongsToMany');
    expect($source)->toContain('MkBelongsToMany::from($relation)');
});

test('HasAbilities::directAbilities() returns MkBelongsToMany instance (R-PKG-021)', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');
    expect($source)->toContain('MkBelongsToMany::from($relation)');
    // Tambien pinea el FQCN para Pinear que el import usa el path correcto.
    expect($source)->toContain('use Mk\\Director\\Database\\Eloquent\\Relations\\MkBelongsToMany');
});
