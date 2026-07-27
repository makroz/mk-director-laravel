<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Http\Resources\MkAbilityResource;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

uses(TestCase::class, UsesDatabase::class);

/**
 * `MkAbilityResource` — shape de contrato, verificado con filas reales.
 *
 * Ver el docblock de `MkMediaResourceTest` por qué el assert central es el
 * set exacto de claves y no un grep del source.
 */
beforeEach(function () {
    $this->setUpDatabase();
    runAuthMigration('2026_06_10_000003_create_abilities_table.php');

    $this->request = Request::create('/');
});

afterEach(function () {
    $this->tearDownDatabase();
});

/**
 * Las migraciones de Auth viven en `src/Auth/Database/Migrations`, no en
 * `src/Database/Migrations` — que es donde busca
 * {@see UsesDatabase::runPackageMigration()}. Mismo mecanismo (clase anónima
 * devuelta por `require`), otra carpeta.
 */
function runAuthMigration(string $filename): void
{
    $path = dirname(__DIR__, 4).'/src/Auth/Database/Migrations/'.$filename;

    if (! is_file($path)) {
        throw new \RuntimeException("Migración de Auth inexistente: {$path}");
    }

    (require $path)->up();
}

/** @return list<string> */
function mkAbilityContractKeys(): array
{
    return ['id', 'name', 'description', 'is_fixed', 'module', 'created_at', 'updated_at'];
}

test('una ability editable serializa las 7 claves del contrato', function () {
    $ability = Ability::create([
        'name' => 'posts.publish',
        'description' => 'Publicar posts en el muro',
    ]);

    $payload = (new MkAbilityResource($ability))->resolve($this->request);

    expect(array_keys($payload))->toBe(mkAbilityContractKeys());

    expect($payload)->toMatchArray([
        'id' => $ability->id,
        'name' => 'posts.publish',
        'description' => 'Publicar posts en el muro',
        // Default de la columna: 0 = Editable.
        'is_fixed' => FixedStatus::Editable->value,
    ]);
});

test('is_fixed sale SIEMPRE como int, venga de la base o casteado a FixedStatus', function () {
    // `Ability` NO castea la columna, así que en lectura normal llega int
    // crudo desde sqlite. Es el camino que recorre la app real.
    Ability::create(['name' => 'wildcard'])->newQuery()
        ->where('name', 'wildcard')
        ->update(['is_fixed' => FixedStatus::Fixed->value]);

    $fromDb = Ability::query()->where('name', 'wildcard')->firstOrFail();

    $payload = (new MkAbilityResource($fromDb))->resolve($this->request);

    expect($payload['is_fixed'])->toBe(1)->toBeInt();

    // Y el consumer que SÍ castea en su propio modelo tiene que producir el
    // MISMO int, no el objeto enum serializado.
    $fromDb->setAttribute('is_fixed', FixedStatus::Fixed);

    expect((new MkAbilityResource($fromDb))->resolve($this->request)['is_fixed'])
        ->toBe(1)->toBeInt();
});

test('is_fixed null degrada a 0 en vez de romper el payload', function () {
    $ability = Ability::create(['name' => 'sin.flag']);
    $ability->setAttribute('is_fixed', null);

    expect((new MkAbilityResource($ability))->resolve($this->request)['is_fixed'])->toBe(0);
});

test('las fechas salen en ISO-8601, no en el formato por defecto de Eloquent', function () {
    $ability = Ability::create(['name' => 'con.fechas']);

    $payload = (new MkAbilityResource($ability))->resolve($this->request);

    // `2026-07-27T12:34:56+00:00`, no `2026-07-27 12:34:56`. El front parsea
    // el primero; el segundo es ambiguo en zona horaria.
    expect($payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
    expect($payload['updated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

test('module sigue en el payload aunque la tabla del paquete no tenga la columna', function () {
    // 🔴 Esto NO es un test de una feature: es el guard de una deuda.
    // La tabla `abilities` del paquete no tiene columna `module`, así que hoy
    // esta clave serializa null para todo consumer que no la agregue por su
    // cuenta. Se mantiene porque los fronts YA la reciben: sacarla es
    // breaking. El día que se agregue la columna, este test debería empezar a
    // afirmar un valor real.
    expect(Ability::query()->getConnection()->getSchemaBuilder()->hasColumn('abilities', 'module'))
        ->toBeFalse();

    $payload = (new MkAbilityResource(Ability::create(['name' => 'x.y'])))->resolve($this->request);

    expect($payload)->toHaveKey('module');
    expect($payload['module'])->toBeNull();
});
