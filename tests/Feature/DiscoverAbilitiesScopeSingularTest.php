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
 * De dónde sale el SCOPE, que es lo que ata una ability a un guard.
 *
 * ## Los dos errores que este archivo cierra
 *
 * **1. El scope del módulo se pluralizaba.** `Str::snake(Str::plural('Member'))`
 * daba `members`, y de ahí salía todo torcido: el placeholder de
 * `#[Ability('{scope}.auth.login')]` se resolvía a `members.auth.login` mientras
 * la ruta exige `mk.ability:member.auth.login`. La ability quedaba escrita y
 * NINGUNA ruta la miraba. En la base de RETO había 24 filas así, al lado de las
 * correctas que escribía —en la misma corrida— el camino de `$mkConfig`, que
 * nunca pluralizó. Un solo comando con dos vocabularios.
 *
 * **2. El guard del rol base salía del MÓDULO.** Un módulo puede declarar
 * abilities de varios scopes: en RETO, `Communications` declara `admin.posts.*`
 * (backoffice) y `admin.wall.*` + `member.wall.*` (el muro, espejado). Con el
 * guard atado al módulo, el rol base habría quedado con guard `communications`
 * — y `AssignsBaseRole` lo busca por `guard = $user->getAuthScope()`, que dice
 * `member`. No se habrían encontrado nunca: la feature entera muerta, en
 * silencio, sin un error que lo delate.
 *
 * El scope es el PRIMER SEGMENTO del nombre de la ability, que es la convención
 * que el middleware `mk.ability:` ya usa en todo el ecosistema.
 *
 * ## Por qué se mide así
 *
 * El test viejo que cubría esto afirmaba sobre el TEXTO del comando
 * (`toContain("Str::snake(Str::plural(\$moduleName))")`). Un test que copia la
 * implementación no puede ver que la implementación está mal: la fija. Acá se
 * mide contra sqlite real, por lo que queda escrito.
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

/**
 * Esquema RBAC completo. `$conTablaPerScope` agrega `member_abilities`.
 *
 * Va apagada por default a propósito: `resolveAbilitiesTable()` PREFIERE la
 * per-scope si existe, así que tenerla siempre haría que los tests del rol base
 * escribieran en una tabla y leyeran de la otra. Sólo la enciende el test que
 * mide justamente esa resolución.
 */
function scopeCapsule(bool $conTablaPerScope = false): Capsule
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

    // 🔴 SINGULAR, Y NO ES UN DETALLE DEL TEST. Así la nombra el scaffolder:
    // `mk:module Member --with-rbac` emite
    // `create_{Str::snake($moduleName)}_abilities_table`. Con el scope
    // pluralizado, `resolveAbilitiesTable()` buscaba `members_abilities`, no la
    // encontraba, y caía a la tabla global por accidente — el camino per-scope
    // no se usaba NUNCA.
    if ($conTablaPerScope) {
        $schema->create('member_abilities', function ($t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('description')->nullable();
            $t->boolean('is_baseline')->default(false);
            $t->timestamps();
        });
    }

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

    $schema->create('ability_role', function ($t) {
        $t->id();
        $t->unsignedBigInteger('ability_id');
        $t->unsignedBigInteger('role_id');
        $t->boolean('is_baseline')->default(false);
        $t->timestamps();
        $t->unique(['ability_id', 'role_id']);
    });

    return $capsule;
}

function scopeCommand(): DiscoverAbilitiesCommand
{
    $cmd = new DiscoverAbilitiesCommand;
    $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $cmd;
}

/** Invoca un método privado del comando. */
function invocar(DiscoverAbilitiesCommand $cmd, string $metodo, array $args)
{
    $m = (new ReflectionClass($cmd))->getMethod($metodo);
    $m->setAccessible(true);

    return $m->invokeArgs($cmd, $args);
}

/** Nombres de las abilities que quedaron en el rol base de un guard. */
function baseDeGuard(Capsule $capsule, string $guard): array
{
    $rolId = $capsule->getConnection()->table('roles')
        ->where('name', 'base')->where('guard', $guard)->value('id');

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

// ─── 1. El scope del módulo es singular ─────────────────────────────────

it('🔴 el módulo Member da el scope `member`, no `members`', function () {
    // El bug exacto que dejó 24 abilities muertas en la base de RETO.
    // `processModule` es el único lugar donde se deriva, así que se mide ahí.
    $capsule = scopeCapsule(conTablaPerScope: true);
    $cmd = scopeCommand();

    // Un módulo sin clases: alcanza para ver qué scope calcula y en qué tabla
    // escribe. Lo que se descubre no importa acá — importa CÓMO se nombra.
    $reporte = invocar($cmd, 'processModule', [
        'Member',
        ['path' => sys_get_temp_dir(), 'classes' => []],
        false,
        true,
    ]);

    expect($reporte['scope'])->toBe('member')
        ->and($reporte['scope'])->not->toBe('members');

    // Y la consecuencia que de verdad dolía: la tabla per-scope que el
    // scaffolder crea en singular ahora SÍ se encuentra.
    expect(invocar($cmd, 'resolveAbilitiesTable', [$reporte['scope']]))
        ->toBe('member_abilities');

    // Prueba de vida del harness: si `processModule` hubiese devuelto cualquier
    // otra cosa, la tabla global habría ganado y esto valdría `abilities`.
    expect($capsule->getConnection()->getSchemaBuilder()->hasTable('member_abilities'))->toBeTrue();
});

// ─── 2. El guard del rol base sale del NOMBRE ───────────────────────────

it('🔴 un módulo con abilities de DOS scopes arma los dos roles base', function () {
    // El caso Communications de RETO: el muro está espejado por scope, así que
    // el mismo módulo declara `admin.wall.*` y `member.wall.*`. Con el guard
    // atado al módulo, los dos habrían caído en un rol base `communications`
    // que ningún usuario busca.
    $capsule = scopeCapsule();

    invocar(scopeCommand(), 'sincronizarRolBase', ['communications', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
        ['name' => 'admin.wall.viewAny', 'description' => null, 'baseline' => true],
        ['name' => 'admin.posts.create', 'description' => null, 'baseline' => false],
    ]]);

    // Las abilities tienen que existir para poder vincularse: las siembra el
    // upsert en la corrida real. Acá se siembran a mano y se vuelve a correr.
    $db = $capsule->getConnection();
    foreach (['member.wall.viewAny', 'admin.wall.viewAny'] as $n) {
        $db->table('abilities')->insert([
            'name' => $n, 'is_baseline' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    invocar(scopeCommand(), 'sincronizarRolBase', ['communications', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
        ['name' => 'admin.wall.viewAny', 'description' => null, 'baseline' => true],
    ]]);

    expect(baseDeGuard($capsule, 'member'))->toBe(['member.wall.viewAny'])
        ->and(baseDeGuard($capsule, 'admin'))->toBe(['admin.wall.viewAny'])
        // Y NINGÚN rol con el guard del módulo: ese es el que nadie buscaría.
        ->and($db->table('roles')->where('guard', 'communications')->count())->toBe(0);
});

it('una ability sin prefijo de scope se ignora, no se le adivina un guard', function () {
    // `*` es el wildcard de super-admin: no nombra ningún scope. Adivinárselo
    // sería conceder permisos por descarte.
    $capsule = scopeCapsule();
    $db = $capsule->getConnection();
    $db->table('abilities')->insert([
        'name' => '*', 'is_baseline' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    invocar(scopeCommand(), 'sincronizarRolBase', ['member', [
        ['name' => '*', 'description' => null, 'baseline' => true],
    ]]);

    expect($db->table('roles')->count())->toBe(0)
        ->and($db->table('ability_role')->count())->toBe(0);

    // PRUEBA DE VIDA. La aserción de arriba la cumple igual un método que no
    // hizo nada por otro motivo. Con un nombre que SÍ nombra scope, arma el rol.
    $db->table('abilities')->insert([
        'name' => 'member.wall.viewAny', 'is_baseline' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    invocar(scopeCommand(), 'sincronizarRolBase', ['member', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
    ]]);

    expect(baseDeGuard($capsule, 'member'))->toBe(['member.wall.viewAny']);
});

it('el rol base del scope del módulo se sigue vaciando aunque no declare nada', function () {
    // Sin esto, sacar la última `baseline: true` de un módulo dejaría su rol
    // base con la ability adentro para siempre: el grupo del guard ya no
    // existiría en el array y nadie lo reconciliaría.
    $capsule = scopeCapsule();
    $db = $capsule->getConnection();
    $db->table('abilities')->insert([
        'name' => 'member.wall.viewAny', 'is_baseline' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    invocar(scopeCommand(), 'sincronizarRolBase', ['member', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true],
    ]]);
    expect(baseDeGuard($capsule, 'member'))->toBe(['member.wall.viewAny']);

    // Ninguna baseline declarada: el grupo `member` ya no viene en el array.
    invocar(scopeCommand(), 'sincronizarRolBase', ['member', [
        ['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => false],
    ]]);

    expect(baseDeGuard($capsule, 'member'))->toBe([]);
});
