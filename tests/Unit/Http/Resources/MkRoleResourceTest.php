<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Http\Resources\MkRoleResource;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

uses(TestCase::class, UsesDatabase::class);

/**
 * `MkRoleResource` — shape de contrato, verificado con filas reales.
 *
 * Lo que sólo se puede testear con base real: que `abilities` sea CONDICIONAL.
 * Un source-grep ve la palabra `whenLoaded` y se pone verde; no puede ver que
 * la clave desaparece del payload cuando la relación no está cargada, que es
 * justamente lo que distingue "el rol no tiene abilities" de "no las pediste".
 */
beforeEach(function () {
    $this->setUpDatabase();
    runAuthMigration('2026_06_10_000002_create_roles_table.php');
    runAuthMigration('2026_06_10_000003_create_abilities_table.php');
    runAuthMigration('2026_06_10_000005_create_ability_role_table.php');
    runAuthMigration('2026_07_14_000001_add_description_to_roles_table.php');

    $this->request = Request::create('/');
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * Sin `abilities`: ése es el shape base. La clave condicional se assertea
 * aparte, en su propio test.
 *
 * @return list<string>
 */
function mkRoleContractKeys(): array
{
    return ['id', 'name', 'guard', 'description', 'is_fixed', 'created_at', 'updated_at'];
}

test('un rol sin la relación cargada NO trae la clave abilities', function () {
    $role = Role::create([
        'name' => 'editor',
        'guard' => 'admin',
        'description' => 'Edita contenido del muro',
    ]);

    $payload = (new MkRoleResource($role))->resolve($this->request);

    expect(array_keys($payload))->toBe(mkRoleContractKeys());

    // 🔴 Ausente, no null ni []. Si se resolviera siempre, el índice de roles
    // dispararía un N+1: una query de abilities por rol listado.
    expect($payload)->not->toHaveKey('abilities');

    expect($payload)->toMatchArray([
        'id' => $role->id,
        'name' => 'editor',
        'guard' => 'admin',
        'description' => 'Edita contenido del muro',
        'is_fixed' => FixedStatus::Editable->value,
    ]);
});

test('con la relación cargada trae abilities como lista de NOMBRES', function () {
    $role = Role::create(['name' => 'super-admin', 'guard' => 'admin']);
    $role->abilities()->attach([
        Ability::create(['name' => 'posts.view'])->id,
        Ability::create(['name' => 'posts.delete'])->id,
    ]);

    $loaded = Role::query()->with('abilities')->findOrFail($role->id);

    $payload = (new MkRoleResource($loaded))->resolve($this->request);

    expect(array_keys($payload))->toBe([
        'id', 'name', 'guard', 'description', 'is_fixed', 'abilities', 'created_at', 'updated_at',
    ]);

    // Nombres, no resources anidados: es lo que el front necesita para pintar
    // los checkboxes del rol.
    expect($payload['abilities']->all())->toBe(['posts.view', 'posts.delete']);
});

test('un rol con la relación cargada pero vacía trae abilities como lista vacía', function () {
    $role = Role::create(['name' => 'viewer', 'guard' => 'member']);

    $loaded = Role::query()->with('abilities')->findOrFail($role->id);

    $payload = (new MkRoleResource($loaded))->resolve($this->request);

    // Presente y vacía — distinto de ausente. Es la diferencia entre "este rol
    // no tiene abilities" y "no pediste las abilities".
    expect($payload)->toHaveKey('abilities');
    expect($payload['abilities']->all())->toBe([]);
});

test('is_fixed sale SIEMPRE como int, venga de la base o casteado a FixedStatus', function () {
    Role::create(['name' => 'fijo', 'guard' => 'admin'])
        ->newQuery()->where('name', 'fijo')->update(['is_fixed' => FixedStatus::Fixed->value]);

    $fromDb = Role::query()->where('name', 'fijo')->firstOrFail();

    expect((new MkRoleResource($fromDb))->resolve($this->request)['is_fixed'])->toBe(1)->toBeInt();

    $fromDb->setAttribute('is_fixed', FixedStatus::Fixed);

    expect((new MkRoleResource($fromDb))->resolve($this->request)['is_fixed'])->toBe(1)->toBeInt();
});

test('guard viaja en el payload — la uniqueness de roles es (name, guard), no name', function () {
    // Dos scopes siembran el MISMO nombre de rol con guards distintos. Sin
    // `guard` en la respuesta, el front no puede distinguirlos.
    Role::create(['name' => 'admin', 'guard' => 'admin']);
    Role::create(['name' => 'admin', 'guard' => 'member']);

    $payloads = Role::query()->orderBy('guard')->get()
        ->map(fn (Role $r): array => (new MkRoleResource($r))->resolve($this->request))
        ->all();

    expect(array_column($payloads, 'name'))->toBe(['admin', 'admin']);
    expect(array_column($payloads, 'guard'))->toBe(['admin', 'member']);
});

test('las fechas salen en ISO-8601', function () {
    $payload = (new MkRoleResource(Role::create(['name' => 'r', 'guard' => 'web'])))
        ->resolve($this->request);

    expect($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    expect($payload['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});
