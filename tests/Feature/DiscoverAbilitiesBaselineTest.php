<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Auth\Attributes\Ability;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Abilities BASELINE: las que tiene cualquier usuario autenticado del scope por
 * el solo hecho de existir (ver el propio perfil, ver el muro, cambiar la clave).
 *
 * ## Qué problema resuelve
 *
 * Sin una forma de declararlas, cada consumidor inventa la suya. En RETO fue un
 * `Member::grantWallAccess()` colgado de un hook `created`, que le pega abilities
 * DIRECTAS a cada member que nace: el mismo permiso repetido en `ability_user`
 * una vez por usuario, en vez de una sola vez en un rol.
 *
 * ## Qué mide este archivo
 *
 * Comportamiento contra una base sqlite REAL, no la forma del código fuente.
 * Lo importante no es que exista el flag: es que el UPSERT lo ASIGNE. Un flag
 * que sólo se escribe al crear la fila sirve para nada — no se puede marcar una
 * ability que ya existía, y sacarlo no revoca. Eso tiene su test propio y está
 * probado en rojo.
 *
 * @see Ability::__construct() el flag.
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

/**
 * Capsule sqlite en memoria con la tabla `abilities`.
 *
 * `$conColumna` decide si la tabla tiene `is_baseline`. Las dos variantes son
 * escenarios REALES: la global del paquete la tiene después de migrar, y una
 * `{scope}_abilities` per-scope generada por `mk:module --with-rbac` NO, porque
 * la migración del paquete no puede alcanzarla.
 */
function baselineCapsule(bool $conColumna, string $tabla = 'abilities'): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Container::setInstance(new Container);
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::setFacadeApplication(Container::getInstance());

    $capsule->getConnection()->getSchemaBuilder()->create($tabla, function ($t) use ($conColumna) {
        $t->id();
        $t->string('name')->unique();
        $t->string('description')->nullable();
        if ($conColumna) {
            $t->boolean('is_baseline')->default(false);
        }
        $t->timestamps();
    });

    return $capsule;
}

/** Comando con salida capturable, para poder afirmar sobre los avisos. */
function baselineCommand(): DiscoverAbilitiesCommand
{
    $cmd = new DiscoverAbilitiesCommand;
    $cmd->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $cmd;
}

function correrUpsert(DiscoverAbilitiesCommand $cmd, string $scope, array $abilities): void
{
    $m = (new ReflectionClass($cmd))->getMethod('upsertAbilities');
    $m->setAccessible(true);
    $m->invokeArgs($cmd, [$scope, $abilities]);
}

// ─── El flag se persiste ────────────────────────────────────────────────

it('persiste is_baseline cuando la ability se declara baseline', function () {
    $capsule = baselineCapsule(conColumna: true);

    correrUpsert(baselineCommand(), 'member', [
        ['name' => 'member.wall.viewAny', 'description' => 'Ver el muro', 'baseline' => true],
        ['name' => 'member.posts.delete', 'description' => 'Borrar', 'baseline' => false],
    ]);

    $filas = $capsule->getConnection()->table('abilities')->pluck('is_baseline', 'name');

    expect((bool) $filas['member.wall.viewAny'])->toBeTrue()
        ->and((bool) $filas['member.posts.delete'])->toBeFalse();
});

it('el default es role-gated: sin el flag, is_baseline queda en false', function () {
    // La asimetría es deliberada. Olvidarse el flag deja a alguien SIN un
    // permiso —se ve, se reclama, se arregla—; el default inverso se lo daría
    // a todo el scope en silencio.
    $capsule = baselineCapsule(conColumna: true);

    correrUpsert(baselineCommand(), 'member', [
        ['name' => 'member.posts.create', 'description' => null],
    ]);

    expect((bool) $capsule->getConnection()->table('abilities')->value('is_baseline'))->toBeFalse();
});

// ─── Lo que de verdad importa: el UPSERT ASIGNA, no sólo inserta ────────

it('marcar baseline una ability QUE YA EXISTÍA la actualiza', function () {
    // 🔴 ESTE ES EL TEST QUE JUSTIFICA EL ARCHIVO. Si `is_baseline` no está en
    // la lista de columnas a actualizar del UPSERT, la fila se encuentra por
    // `name` y la columna se saltea: el flag sólo andaría en abilities nuevas.
    $capsule = baselineCapsule(conColumna: true);
    $cmd = baselineCommand();

    correrUpsert($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => 'Ver el muro']]);
    expect((bool) $capsule->getConnection()->table('abilities')->value('is_baseline'))->toBeFalse();

    correrUpsert($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => 'Ver el muro', 'baseline' => true]]);

    expect((bool) $capsule->getConnection()->table('abilities')->value('is_baseline'))->toBeTrue();
});

it('SACAR baseline del código REVOCA la marca', function () {
    // La contracara, y la que impide el peor final: que quitar `baseline: true`
    // no revoque nada y el permiso quede concedido para siempre.
    $capsule = baselineCapsule(conColumna: true);
    $cmd = baselineCommand();

    correrUpsert($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => true]]);
    expect((bool) $capsule->getConnection()->table('abilities')->value('is_baseline'))->toBeTrue();

    correrUpsert($cmd, 'member', [['name' => 'member.wall.viewAny', 'description' => null, 'baseline' => false]]);

    expect((bool) $capsule->getConnection()->table('abilities')->value('is_baseline'))->toBeFalse();
});

// ─── Sin la columna: degrada, no revienta ───────────────────────────────

it('sin la columna is_baseline no revienta y persiste igual el resto', function () {
    // Escenario real: una `{scope}_abilities` per-scope generada por el
    // scaffolder, que la migración del paquete no puede alcanzar.
    $capsule = baselineCapsule(conColumna: false);

    // Sin `expect`: que la llamada vuelva ES la afirmación. Si escribiera la
    // columna a ciegas, acá habría un error de SQL.
    correrUpsert(baselineCommand(), 'member', [
        ['name' => 'member.wall.viewAny', 'description' => 'Ver el muro', 'baseline' => true],
        ['name' => 'member.posts.create', 'description' => 'Crear', 'baseline' => false],
    ]);

    $filas = $capsule->getConnection()->table('abilities')->pluck('name')->all();

    expect($filas)->toHaveCount(2)
        ->and($filas)->toContain('member.wall.viewAny');
});

// ─── El flag viaja hasta el reporte ─────────────────────────────────────

it('el reporte conserva baseline en vez de recortarlo', function () {
    // El mapper del reporte alimenta la tabla humana Y el `--json` del CI.
    // Recortaba a name+description, así que la columna nueva habría salido
    // vacía siempre y el CI nunca se habría enterado.
    $cmd = baselineCommand();
    $m = (new ReflectionClass($cmd))->getMethod('discoverAbilitiesFromProvider');
    $m->setAccessible(true);

    // El resolver del comando exige un nombre que termine en `ServiceProvider`,
    // así que la clase no puede ser anónima. Namespace único por corrida para
    // que dos ejecuciones en el mismo proceso no choquen con "Cannot redeclare".
    $ns = 'TestNs\\Baseline'.str_replace('.', '', uniqid('', true));
    eval(<<<PHP
        namespace {$ns};
        class MemberModuleServiceProvider {
            public function discoverAbilities(): array {
                return [
                    'member.posts.create',
                    ['name' => 'member.wall.viewAny', 'description' => 'Ver el muro', 'baseline' => true],
                ];
            }
        }
        PHP);
    $providerFqn = $ns.'\\MemberModuleServiceProvider';

    Container::setInstance(new Container);
    Facade::setFacadeApplication(Container::getInstance());

    $out = $m->invokeArgs($cmd, ['Member', ['path' => '/tmp', 'classes' => [$providerFqn]]]);

    expect($out['source'])->toBe('provider')
        ->and($out['abilities'])->toBe([
            // El string pelado sigue andando: la forma vieja es BC.
            ['name' => 'member.posts.create', 'description' => null, 'baseline' => false],
            // Y la forma extendida es la única vía para que un módulo con
            // provider —que IGNORA los atributos— pueda declarar una baseline.
            ['name' => 'member.wall.viewAny', 'description' => 'Ver el muro', 'baseline' => true],
        ]);
});
