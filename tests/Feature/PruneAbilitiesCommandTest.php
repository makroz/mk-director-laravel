<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Console\Commands\PruneAbilitiesCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;

/**
 * `mk:prune-abilities` — y sobre todo, TODO LO QUE SE NIEGA A BORRAR.
 *
 * Este comando borra permisos. El riesgo no está repartido parejo entre sus dos
 * formas de equivocarse:
 *
 *  - Dejar de más una fila que nadie mira cuesta una línea en un listado.
 *  - Borrar de más una que alguien usaba cuesta un 403 que nadie sabe leer, y
 *    encima el síntoma aparece lejos en el tiempo del comando que lo causó.
 *
 * Por eso `nombresVivos()` junta CINCO fuentes y basta con una para salvar una
 * ability. Los tests de acá son casi todos sobre esas cinco.
 *
 * ## Las dos que no son obvias
 *
 * **Las rutas.** Una ruta puede exigir una ability que el discovery no
 * descubre. Borrarla deja la ruta pidiendo un permiso inexistente: 403 para
 * TODO el mundo, incluido quien lo tenía. Es el peor error posible acá y el
 * único que el discovery por sí solo no puede evitar.
 *
 * **El config.** `mk_director.auth.abilities.*` nombra abilities que no salen de
 * ningún atributo ni de ninguna ruta. Sin esa fuente, el paquete borraría las
 * suyas propias.
 */
uses(MkLaravelTestCase::class);

function pruneCommand(): PruneAbilitiesCommand
{
    return new PruneAbilitiesCommand;
}

/** Invoca un privado del comando. */
function invocarPrune(PruneAbilitiesCommand $cmd, string $metodo, array $args = [])
{
    $m = (new ReflectionClass($cmd))->getMethod($metodo);
    $m->setAccessible(true);

    return $m->invokeArgs($cmd, $args);
}

// ─── El contrato de la firma ────────────────────────────────────────────

it('🔴 NO acepta --module, para no podar mirando un solo módulo', function () {
    // Con `--module=Admin`, "lo declarado" sería sólo lo de Admin y todas las
    // abilities de los demás módulos parecerían huérfanas. Un `--module` acá no
    // acota el daño: lo concentra.
    $cmd = pruneCommand();

    expect($cmd->getDefinition()->hasOption('module'))->toBeFalse()
        ->and($cmd->getDefinition()->hasOption('force'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('dry-run'))->toBeTrue()
        ->and($cmd->getDefinition()->hasOption('incluir-otorgadas'))->toBeTrue();
});

it('el default NO borra: sin flags pregunta, y en no-interactivo es que no', function () {
    // Misma política que `mk:discover-abilities`. Un comando destructivo que
    // borra por default es un comando que algún día borra en producción porque
    // alguien lo tipeó para ver qué hacía.
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/PruneAbilitiesCommand.php');

    expect($src)->toMatch('/confirm\([^)]*,\s*false\s*\)/');
});

// ─── Las cinco fuentes de "vivo" ────────────────────────────────────────

it('🔴 el wildcard `*` nunca se poda', function () {
    // Es el bypass de super-admin. No lo declara ningún módulo, así que sin
    // protegerlo explícitamente sería lo PRIMERO en caer.
    $vivas = invocarPrune(pruneCommand(), 'nombresVivos', [[], []]);

    expect($vivas)->toContain('*');
});

it('🔴 lo que nombra el config se protege', function () {
    // `mk_director.auth.abilities.*` no sale de ningún atributo ni ruta. Sin
    // esta fuente el paquete borraría sus propias abilities.
    config()->set('mk_director.auth.abilities', [
        'me' => 'auth.admin.me',
        'logout' => 'auth.admin.logout',
    ]);

    $vivas = invocarPrune(pruneCommand(), 'nombresVivos', [[], []]);

    expect($vivas)->toContain('auth.admin.me')
        ->and($vivas)->toContain('auth.admin.logout');
});

it('🔴 lo que exige una RUTA entra a la lista de vivas', function () {
    // El peor error posible: borrar la ability que una ruta exige deja esa ruta
    // en 403 para todo el mundo. El discovery solo no puede evitarlo — una ruta
    // puede pedir algo que ningún atributo declara.
    $vivas = invocarPrune(pruneCommand(), 'nombresVivos', [['reportes.export'], []]);

    expect($vivas)->toContain('reportes.export');
});

it('🔴 una ruta con VARIAS abilities las protege a todas', function () {
    // `mk.ability:a,b` es UNA sola cadena de middleware con dos abilities.
    // Partirla mal deja viva la primera y borra la segunda — y el 403 sale sólo
    // en el endpoint que combina las dos, que es el que nadie prueba a mano.
    //
    // Se mide sobre el parseo real, no sobre el texto del comando: es la parte
    // con forma de tener bugs. `illuminate/routing` no es dependencia del
    // paquete, así que el recorrido de rutas no se puede montar acá — por eso
    // el parseo vive en un método `static` propio.
    $nombres = PruneAbilitiesCommand::abilitiesDeMiddleware([
        'mk.auth:admin',
        'throttle:60,1',
        'mk.ability:facturas.ver,facturas.exportar',
    ]);

    expect($nombres)->toBe(['facturas.ver', 'facturas.exportar']);
});

it('ignora el middleware que no es de abilities, y los espacios', function () {
    $nombres = PruneAbilitiesCommand::abilitiesDeMiddleware([
        'auth', 'mk.ability: a.b , , c.d ', 42, null,
    ]);

    expect($nombres)->toBe(['a.b', 'c.d']);
});

it('🔴 sin router, FRENA en vez de quedarse sin la protección de las rutas', function () {
    // Una lista vacía significa "ninguna ruta protege abilities" y es
    // indistinguible de "no pude mirar". Si se confunden, la poda sigue sin su
    // red más importante y borra justo lo que los endpoints validan.
    //
    // El contenedor de los tests del paquete no bindea `router`, así que este
    // escenario es el que corre de verdad acá — no una simulación.
    expect(fn () => invocarPrune(pruneCommand(), 'exigidasPorLasRutas'))
        ->toThrow(\RuntimeException::class);
});

// ─── Si no puede saber qué está vivo, no borra ──────────────────────────

it('🔴 si el discovery no devuelve un reporte legible, FRENA', function () {
    // Sin la lista de declaradas, TODA la tabla parece huérfana. Un fallback
    // silencioso a "lista vacía" convertiría un error de lectura en un borrado
    // masivo — el modo de falla más caro que este comando podría tener.
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/PruneAbilitiesCommand.php');

    // La forma importa: tiene que LANZAR, no `return []`.
    expect($src)->toMatch('/if\s*\(\s*!\s*is_array\(\$reporte\)\s*\)\s*\{\s*(\/\/[^\n]*\n\s*)*throw new/');
});

it('el discovery se invoca SIEMPRE en dry-run y sin --module', function () {
    // Dos invariantes en una línea: una poda no puede modificar nada (el
    // discovery en modo escritura tocaría hasta el rol base), y el conjunto de
    // declaradas tiene que ser el de todos los módulos.
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/PruneAbilitiesCommand.php');

    expect($src)->toContain("Artisan::call('mk:discover-abilities', ['--dry-run' => true, '--json' => true])")
        ->and($src)->not->toContain("'--module' =>");
});

// ─── Comportamiento contra una base REAL ────────────────────────────────
//
// Los tests de arriba que leen el código fuente cubren invariantes de FORMA
// (que no exista `--module`, que el confirm sea default-No). Los de acá miden
// lo que el comando HACE, que es lo que importa cuando borra permisos.

/** Capsule con la tabla de abilities y las dos pivots. */
function prunecapsule(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Container::setInstance(new Container);
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Facade::setFacadeApplication(Container::getInstance());

    $schema = $capsule->getConnection()->getSchemaBuilder();

    $schema->create('abilities', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->timestamps();
    });

    // 🔴 SIN `->onDelete('cascade')`, A PROPÓSITO. Es el escenario que obliga a
    // borrar las pivots a mano: si el comando confiara en el cascade, acá
    // quedarían filas colgadas y el test lo vería.
    foreach (['ability_role', 'ability_user'] as $pivot) {
        $schema->create($pivot, function ($t) use ($pivot) {
            $t->id();
            $t->unsignedBigInteger('ability_id');
            $t->unsignedBigInteger($pivot === 'ability_role' ? 'role_id' : 'user_id');
            $t->timestamps();
        });
    }

    return $capsule;
}

it('🔴 al podar se lleva las filas de pivot, sin confiar en el cascade', function () {
    // Una pivot huérfana no da error: da un JOIN que devuelve de menos, mucho
    // después, sin nada que lo relacione con este comando.
    $capsule = prunecapsule();
    $db = $capsule->getConnection();

    $id = $db->table('abilities')->insertGetId(['name' => 'vieja.ability']);
    $sobrevive = $db->table('abilities')->insertGetId(['name' => 'otra.ability']);
    $db->table('ability_role')->insert(['ability_id' => $id, 'role_id' => 1]);
    $db->table('ability_user')->insert(['ability_id' => $id, 'user_id' => 1]);
    $db->table('ability_role')->insert(['ability_id' => $sobrevive, 'role_id' => 2]);

    pruneCommand()->podar([$id]);

    expect($db->table('abilities')->pluck('name')->all())->toBe(['otra.ability'])
        ->and($db->table('ability_role')->where('ability_id', $id)->count())->toBe(0)
        ->and($db->table('ability_user')->where('ability_id', $id)->count())->toBe(0)
        // Y no se llevó puesto lo ajeno.
        ->and($db->table('ability_role')->where('ability_id', $sobrevive)->count())->toBe(1);

    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

it('🔴 detecta que una ability está OTORGADA — la regla que evita revocar en silencio', function () {
    // Ésta es la protección más delicada del comando: una ability que el código
    // ya no declara puede estar concedida a alguien igual (un admin la otorgó a
    // mano desde la UI, o alguien se olvidó de declararla). Borrarla se lleva
    // las filas de pivot y le saca el permiso a esa persona, sin decir nada.
    $capsule = prunecapsule();
    $db = $capsule->getConnection();
    $cmd = pruneCommand();

    $porRol = $db->table('abilities')->insertGetId(['name' => 'a.por.rol']);
    $porUsuario = $db->table('abilities')->insertGetId(['name' => 'a.por.usuario']);
    $libre = $db->table('abilities')->insertGetId(['name' => 'a.sin.grant']);

    $db->table('ability_role')->insert(['ability_id' => $porRol, 'role_id' => 1]);
    $db->table('ability_user')->insert(['ability_id' => $porUsuario, 'user_id' => 1]);

    expect($cmd->estaOtorgada($porRol))->toBeTrue()
        ->and($cmd->estaOtorgada($porUsuario))->toBeTrue()
        // Y la que no la tiene nadie SÍ es podable. Sin esta tercera aserción,
        // un método que devolviera `true` siempre pasaría las dos de arriba.
        ->and($cmd->estaOtorgada($libre))->toBeFalse();

    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

it('podar con una lista vacía no toca nada', function () {
    $capsule = prunecapsule();
    $capsule->getConnection()->table('abilities')->insert(['name' => 'x.y']);

    pruneCommand()->podar([]);

    expect($capsule->getConnection()->table('abilities')->count())->toBe(1);

    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});
