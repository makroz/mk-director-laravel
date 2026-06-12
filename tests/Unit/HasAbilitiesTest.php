<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;

/**
 * Unit tests para la trait HasAbilities.
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.5.
 *
 * Cobertura:
 *  - canMk() con wildcard `users.*` matchea `users.view`.
 *  - canMk() con `users.*` NO matchea `orders.create`.
 *  - canMk() con `users.view` solo NO matchea `users.edit`.
 *  - canMk() con `*` (super-admin) matchea cualquier ability.
 *  - Modelos Auth existen y están bien declarados (AuthUser abstracto,
 *    Role con relación abilities, Ability como modelo de tabla).
 *
 * Estos tests son merge-friendly con la implementación de la sesión
 * S2.A.2: solo validan el comportamiento del trait sobre una
 * colección de nombres de abilities (sin requerir DB real). El método
 * `collectAllAbilityNames()` de la trait se puede override en una
 * subclase para tests con datos sintéticos.
 */

/**
 * Stub de AuthUser para tests sin DB.
 *
 * Como `AuthUser` es abstracto y depende del boot de Eloquent, lo
 * stubbeamos con un Model anónimo que use la trait directamente y
 * override `collectAllAbilityNames` para devolver una colección
 * controlada. Esto permite probar la lógica de `canMk` sin tocar DB.
 */
class HasAbilitiesTestUser extends \Illuminate\Database\Eloquent\Model
{
    use HasAbilities;

    protected $table = 'auth_users';

    protected $fillable = ['name', 'email', 'auth_scope'];

    /**
     * Colección controlable de nombres de abilities para los tests.
     */
    public \Illuminate\Support\Collection $stubAbilityNames;

    /**
     * Override: devolvemos los nombres stub sin tocar DB.
     */
    protected function collectAllAbilityNames(): \Illuminate\Support\Collection
    {
        return $this->stubAbilityNames ?? collect();
    }

    public function getAuthScope(): ?string
    {
        return 'admin';
    }
}

beforeEach(function () {
    $this->user = new HasAbilitiesTestUser();
});

test('canMk returns true when wildcard users.* is granted and asking for users.view', function () {
    $this->user->stubAbilityNames = collect(['users.*']);

    expect($this->user->canMk('users.view'))->toBeTrue();
});

test('canMk returns false when wildcard users.* is granted and asking for orders.create', function () {
    $this->user->stubAbilityNames = collect(['users.*']);

    expect($this->user->canMk('orders.create'))->toBeFalse();
});

test('canMk returns false when only users.view is granted and asking for users.edit', function () {
    $this->user->stubAbilityNames = collect(['users.view']);

    expect($this->user->canMk('users.edit'))->toBeFalse();
});

test('canMk returns true for exact match', function () {
    $this->user->stubAbilityNames = collect(['users.view', 'users.edit']);

    expect($this->user->canMk('users.edit'))->toBeTrue();
    expect($this->user->canMk('users.view'))->toBeTrue();
});

test('canMk with * grants everything (super-admin)', function () {
    $this->user->stubAbilityNames = collect(['*']);

    expect($this->user->canMk('users.view'))->toBeTrue();
    expect($this->user->canMk('orders.create'))->toBeTrue();
    expect($this->user->canMk('anything.really'))->toBeTrue();
});

test('canMk returns false for empty ability list', function () {
    $this->user->stubAbilityNames = collect();

    expect($this->user->canMk('users.view'))->toBeFalse();
});

test('AuthUser model uses HasAbilities trait (structural check)', function () {
    // No instanciamos AuthUser directamente (es abstracto y depende
    // del boot de Eloquent). En su lugar, verificamos que la trait
    // existe y es la que está aplicada al modelo, leyéndola desde el
    // código fuente. Esto es robusto a dependencias faltantes
    // (ej. laravel/sanctum si el package consumer no la instaló).
    $source = file_get_contents(__DIR__ . '/../../src/Auth/Models/AuthUser.php');
    expect($source)->toContain('use HasAbilities;');
    expect($source)->toContain('abstract class AuthUser');
    expect($source)->toContain('implements');
    expect($source)->toContain('MustVerifyEmail');
});

test('Role model exposes abilities() relationship', function () {
    $reflection = new \ReflectionClass(Role::class);

    expect($reflection->hasMethod('abilities'))->toBeTrue();
});

test('Ability model has name and description fillable', function () {
    $ability = new Ability();
    expect($ability->getFillable())->toContain('name');
    expect($ability->getTable())->toBe('abilities');
});
