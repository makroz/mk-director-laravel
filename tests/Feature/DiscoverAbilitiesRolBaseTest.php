<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Config\Repository;
use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * El ROL BASE: `mk:discover-abilities` lo arma y lo mantiene igual a lo que
 * declara el código, sin pisarle el trabajo a nadie.
 *
 * ## La propiedad que este archivo existe para defender
 *
 * El discovery toca SÓLO las vinculaciones que puso él (`ability_role.is_baseline
 * = 1`). Lo que sembró el `{Scope}RolesSeeder` o agregó un admin desde la UI no
 * lo mira nunca.
 *
 * Esa distinción no es un lujo. El caso que la obliga:
 *
 *   1. El código declara `member.wall.viewAny` baseline → entra al rol base.
 *   2. Un admin le agrega al rol base `member.reports.view`, que NO es baseline.
 *   3. Alguien saca `baseline: true` de `member.wall.viewAny`.
 *
 * Las dos quedan con `abilities.is_baseline = false` dentro del rol base. Sin
 * una marca EN LA VINCULACIÓN son indistinguibles, y el discovery tiene que
 * sacar una y respetar la otra. Ese escenario exacto tiene su test acá abajo.
 *
 * Todo se mide contra sqlite real, y cada test está probado en rojo.
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

/** Capsule con el esquema RBAC completo (abilities + roles + pivot). */
function rolBaseCapsule(bool $pivotConMarca = true): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Container::setInstance(new Container);
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->instance('config', new Repository([
        'mk_director' => ['auth' => ['base_role' => 'base']],
    ]));
    Facade::setFacadeApplication(Container::getInstance());

    $schema = $capsule->getConnection()->getSchemaBuilder();

    $schema->create('abilities', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->string('description')->nullable();
        $t->boolean('is_baseline')->default(false);
        $t->timestamps();
    });

    $schema->create('roles', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('guard')->nullable();
        $t->timestamps();
    });

    $schema->create('ability_role', function ($t) use ($pivotConMarca) {
        $t->id();
        $t->unsignedBigInteger('ability_id');
        $t->unsignedBigInteger('role_id');
        if ($pivotConMarca) {
            $t->boolean('is_baseline')->default(false);
        }
        $t->timestamps();
        $t->unique(['ability_id', 'role_id']);
    });

    return $capsule;
}

function rolBaseCommand(): DiscoverAbilitiesCommand
{
    $cmd = new DiscoverAbilitiesCommand;
    $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $cmd;
}

/** Corre el upsert + la sincronización, que es lo que hace el comando real. */
function descubrir(DiscoverAbilitiesCommand $cmd, string $scope, array $abilities): void
{
    $r = new ReflectionClass($cmd);
    foreach (['upsertAbilities', 'sincronizarRolBase'] as $metodo) {
        $m = $r->getMethod($metodo);
        $m->setAccessible(true);
        $m->invokeArgs($cmd, [$scope, $abilities]);
    }
}

/** Nombres de las abilities que quedaron en el rol base del scope. */
function abilitiesDelRolBase(Capsule $capsule, string $scope = 'member'): array
{
    $rolId = $capsule->getConnection()->table('roles')
        ->where('name', 'base')->where('guard', $scope)->value('id');

    if ($rolId === null) {
        return [];
    }

    return $capsule->getConnection()->table('ability_role')
        ->join('abilities', 'abilities.id', '=', 'ability_role.ability_id')
        ->where('ability_role.role_id', $rolId)
        ->orderBy('abilities.name')
        ->pluck('abilities.name')
        ->all();
}

// ─── Construcción ───────────────────────────────────────────────────────

it('crea el rol base y le mete las abilities declaradas baseline', function () {
    $capsule = rolBaseCapsule();

    descubrir(rolBaseCommand(), 'member', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
        ['name' => 'member.profile.view', 'description' => null, 'baseline' => true],
        ['name' => 'member.posts.delete', 'description' => null, 'baseline' => false],
    ]);

    expect(abilitiesDelRolBase($capsule))->toBe(['member.profile.view', 'member.wall.viewAny']);
});

it('NO crea un rol vacío cuando el scope no declara ninguna baseline', function () {
    // Un rol sin permisos colgando en la UI de todos los scopes que no usan la
    // feature es basura, y encima invita a que alguien le cuelgue cosas.
    $capsule = rolBaseCapsule();

    descubrir(rolBaseCommand(), 'member', [
        ['name' => 'member.posts.create', 'description' => null, 'baseline' => false],
    ]);

    expect($capsule->getConnection()->table('roles')->count())->toBe(0);
});

it('es idempotente: correrlo dos veces no duplica vinculaciones', function () {
    $capsule = rolBaseCapsule();
    $cmd = rolBaseCommand();
    $declaradas = [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]];

    descubrir($cmd, 'member', $declaradas);
    descubrir($cmd, 'member', $declaradas);

    expect($capsule->getConnection()->table('ability_role')->count())->toBe(1)
        ->and($capsule->getConnection()->table('roles')->count())->toBe(1);
});

// ─── Reconciliación ─────────────────────────────────────────────────────

it('sacar baseline del código saca la ability del rol base', function () {
    $capsule = rolBaseCapsule();
    $cmd = rolBaseCommand();

    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]]);
    expect(abilitiesDelRolBase($capsule))->toBe(['member.wall.viewAny']);

    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => false]]);

    expect(abilitiesDelRolBase($capsule))->toBe([]);
});

it('🔴 respeta lo que un admin agregó a mano, y saca lo suyo, en el MISMO rol', function () {
    // El escenario de tres pasos que obliga a marcar la VINCULACIÓN y no la
    // ability. Al final las dos tienen `abilities.is_baseline = false` y están
    // en el mismo rol: sin la marca en el pivot son indistinguibles.
    $capsule = rolBaseCapsule();
    $cmd = rolBaseCommand();
    $db = $capsule->getConnection();

    // (1) El código declara una baseline.
    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]]);

    // (2) Un admin agrega A MANO una ability que NO es baseline. Sin marca:
    //     es exactamente lo que hace la UI de gestión de roles.
    $otra = $db->table('abilities')->insertGetId([
        'name' => 'member.reports.view', 'description' => null,
        'is_baseline' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rolId = $db->table('roles')->where('name', 'base')->value('id');
    $db->table('ability_role')->insert([
        'ability_id' => $otra, 'role_id' => $rolId,
        'is_baseline' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // (3) Alguien saca `baseline: true` del código.
    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => false]]);

    // La suya se fue. La del admin sigue.
    expect(abilitiesDelRolBase($capsule))->toBe(['member.reports.view']);
});

it('no toca los OTROS roles, ni siquiera los que comparten la ability', function () {
    // El rol base y `viewer` pueden compartir una ability. Reconciliar el base
    // no puede tocar la fila de viewer.
    $capsule = rolBaseCapsule();
    $cmd = rolBaseCommand();
    $db = $capsule->getConnection();

    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]]);

    $viewer = $db->table('roles')->insertGetId([
        'name' => 'viewer', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $abilityId = $db->table('abilities')->where('name', 'member.wall.viewAny')->value('id');
    $db->table('ability_role')->insert([
        'ability_id' => $abilityId, 'role_id' => $viewer,
        'is_baseline' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => false]]);

    expect(abilitiesDelRolBase($capsule))->toBe([])
        ->and($db->table('ability_role')->where('role_id', $viewer)->count())->toBe(1);
});

it('cada scope tiene su propio rol base, separados por guard', function () {
    $capsule = rolBaseCapsule();
    $cmd = rolBaseCommand();

    descubrir($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]]);
    descubrir($cmd, 'admin', [['name' => 'admin.dashboard.view', 'description' => null, 'baseline' => true]]);

    expect(abilitiesDelRolBase($capsule, 'member'))->toBe(['member.wall.viewAny'])
        ->and(abilitiesDelRolBase($capsule, 'admin'))->toBe(['admin.dashboard.view'])
        ->and($capsule->getConnection()->table('roles')->where('name', 'base')->count())->toBe(2);
});

// ─── Sin la marca en el pivot: no se toca nada ──────────────────────────

it('sin la columna del pivot NO sincroniza, en vez de arriesgarse a sacar permisos', function () {
    // Podría sincronizar igual, pero sin poder distinguir lo propio de lo ajeno
    // el precio de equivocarse es sacarle permisos a alguien. Mejor no hacer
    // nada y que se corra la migración.
    $capsule = rolBaseCapsule(pivotConMarca: false);

    descubrir(rolBaseCommand(), 'member', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
    ]);

    expect($capsule->getConnection()->table('roles')->count())->toBe(0)
        ->and($capsule->getConnection()->table('ability_role')->count())->toBe(0)
        // Las abilities SÍ se persisten: lo que se saltea es sólo el rol.
        ->and($capsule->getConnection()->table('abilities')->count())->toBe(1);
});
