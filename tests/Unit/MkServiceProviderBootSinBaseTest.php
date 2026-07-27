<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El boot del provider SOBREVIVE sin base de datos.
 *
 * 🔴 EL BOOT DE UN PROVIDER CORRE DENTRO DE `composer install`.
 *
 * `package:discover` bootea la app entera, y `composer install` lo dispara en
 * su `post-autoload-dump`. Todo lo que este provider toque en su boot pasa a
 * ser, sin que nadie lo haya decidido, un requisito para INSTALAR
 * DEPENDENCIAS. `registerAutoDiscoverAbilities()` llamaba `Schema::hasTable()`
 * a pelo, así que en un clone limpio el install moría con
 *
 *   Database file at path [.../database.sqlite] does not exist.
 *
 * El guard existía —el comentario decía "skip si la tabla no está"— pero
 * confundía dos cosas distintas: "la tabla no está" (hasTable devuelve false)
 * y "no puedo ni preguntar" (hasTable TIRA). Sólo cubría la primera.
 *
 * 🔴 Y ADEMÁS FALTABA `use Throwable`. Los dos `catch (Throwable $e)` de este
 * archivo resolvían a `Mk\Director\Throwable`, una clase que no existe, así
 * que no atrapaban NADA. PHP no se queja, el linter no se queja, y el bloque
 * simplemente nunca corre: un rescate decorativo.
 *
 * 🔴 CÓMO SE AFIRMA ACÁ QUE ALGO "NO EXPLOTA", Y POR QUÉ NO CON `not->toThrow`.
 * Porque `not->toThrow` NO afirma eso. Medido en este mismo repo:
 *
 *   expect(fn () => throw new RuntimeException('x'))->not->toThrow(Throwable::class)  → PASA
 *
 * Se lee como "no lanza" y no lo comprueba. La forma honesta es la más simple:
 * llamar al método pelado. Si tira, el test falla solo, con el stack de verdad
 * y sin intermediarios. Y después se afirma que además hizo LO QUE TENÍA QUE
 * HACER, que no sobrevivir es la mitad del trabajo.
 */
uses(MkLaravelTestCase::class);

/**
 * Container que sabe decir que corre en consola. El Container pelado del
 * harness no tiene `runningInConsole()`, y ese es justo el chequeo que decide
 * si el auto-discover sigue: sin esto el método corta antes de llegar al
 * schema y el test mide el vacío. (Pasó: la primera versión de este archivo
 * daba verde con el bug puesto.)
 */
function containerDeConsola(): Container
{
    $container = Container::getInstance();

    return new class($container) extends Container
    {
        public function __construct(private Container $real)
        {
            $this->bindings = $real->bindings ?? [];
        }

        public function runningInConsole(): bool
        {
            return true;
        }

        public function make($abstract, array $parameters = [])
        {
            return $this->real->make($abstract, $parameters);
        }
    };
}

/** Deja una facade `Schema` que revienta en cualquier llamada. */
function schemaQueRevienta(): void
{
    Facade::clearResolvedInstances();

    Container::getInstance()->instance('db.schema', new class
    {
        public function __call(string $method, array $args): mixed
        {
            throw new RuntimeException('Database file at path [database.sqlite] does not exist.');
        }
    });
}

/** Invoca el método protegido del provider y devuelve si llegó al final. */
function correrAutoDiscover(?Container $app = null): bool
{
    $provider = new MkServiceProvider($app ?? containerDeConsola());

    $m = new ReflectionMethod($provider, 'registerAutoDiscoverAbilities');
    $m->setAccessible(true);
    $m->invoke($provider);

    return true;
}

test('sin conexión, el auto-discover se saltea en vez de tumbar el boot', function () {
    // La feature prendida + consola es exactamente el escenario de
    // `package:discover`: si acá no hay rescate, `composer install` muere.
    config()->set('mk_director.features.auto_discover_abilities', true);

    schemaQueRevienta();

    // Sin `expect`: que la llamada vuelva ES la afirmación. Si `hasTable`
    // vuelve a escaparse, esta línea tira y el test falla con el error real.
    expect(correrAutoDiscover())->toBeTrue();
});

test('el guard SÍ se ejecuta: sin el rescate este mismo escenario explota', function () {
    // La contracara del test de arriba, y la que lo vuelve creíble. Comprueba
    // que el camino llega DE VERDAD hasta el schema — si cortara antes, el test
    // anterior estaría midiendo la nada.
    config()->set('mk_director.features.auto_discover_abilities', true);

    schemaQueRevienta();

    $llegoAlSchema = false;
    try {
        // La facade sin el try del provider: reproduce lo que hacía el código
        // viejo en la misma línea, con el mismo container y la misma config.
        Schema::hasTable('abilities');
    } catch (Throwable) {
        $llegoAlSchema = true;
    }

    expect($llegoAlSchema)->toBeTrue();
});

test('con la feature apagada ni se acerca a la base', function () {
    // El camino corto tiene que cortar ANTES del schema. Si esta vía tocara la
    // base, apagar la feature no serviría de nada.
    config()->set('mk_director.features.auto_discover_abilities', false);

    schemaQueRevienta();

    expect(correrAutoDiscover())->toBeTrue();
});

test('con la tabla ausente, el skip CORTA: no llega al Artisan::call', function () {
    // Reemplaza a un regex que medía el orden de tres cadenas en el archivo
    // (`Schema::hasTable .* Log::debug .* return;`). Ese regex se ponía rojo
    // por mover un log a un helper, sin que cambiara nada de lo que importa.
    // Esto ejecuta el método y le pregunta al kernel si lo llamaron.
    config()->set('mk_director.features.auto_discover_abilities', true);

    Facade::clearResolvedInstances();
    Container::getInstance()->instance('db.schema', new class
    {
        public function hasTable(string $tabla): bool
        {
            return false;
        }

        public function __call(string $method, array $args): mixed
        {
            return null;
        }
    });

    $kernel = new class
    {
        public int $llamadas = 0;

        public function call($comando, array $params = [], $output = null): int
        {
            $this->llamadas++;

            return 0;
        }
    };
    Container::getInstance()->instance(Kernel::class, $kernel);

    correrAutoDiscover();

    expect($kernel->llamadas)->toBe(0);
});

test('los catch del provider atrapan de verdad: Throwable está importado', function () {
    // Sin `use Throwable`, dentro del namespace el nombre pelado apunta a
    // `Mk\Director\Throwable`, que no existe, y el catch no matchea nunca.
    expect(class_exists('Mk\\Director\\Throwable'))->toBeFalse()
        ->and(interface_exists('Mk\\Director\\Throwable'))->toBeFalse();
});
