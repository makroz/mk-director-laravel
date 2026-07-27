<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Store;
use Mk\Director\ModuleLoader\ModuleProviderRegistry;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El descubrimiento de módulos SOBREVIVE a un cache caído.
 *
 * 🔴 POR QUÉ ESTO MERECE UN ARCHIVO PROPIO.
 *
 * `discover()` corre en el boot de `ModuleLoaderServiceProvider`, y el boot de
 * los providers lo dispara `package:discover`, que corre dentro de
 * `composer install`. Con el driver de cache por default (`database`) eso
 * significaba que instalar dependencias exigía una base de datos viva: en un
 * clone limpio el install moría con
 *
 *   Database file at path [.../database.sqlite] does not exist.
 *
 * un mensaje que no nombra ni el cache ni los módulos. Pasó de verdad, en el
 * primer arranque limpio del CI de reto-api, y se tapó del lado del consumidor
 * con `CACHE_STORE=array`. Esto lo arregla del lado del paquete, que es donde
 * está la causa.
 *
 * Estos tests miden COMPORTAMIENTO con un store que revienta, no la forma del
 * código fuente. Los que quedaron en `PerformanceImprovementsTest` leían el
 * archivo y buscaban la cadena `Cache::remember`: pasaban en verde con el bug
 * adentro, porque `Cache::remember` es exactamente lo que causaba el problema.
 */
uses(MkLaravelTestCase::class);

/** Store que revienta en la operación que se le pida. */
function storeQueRevienta(bool $enGet = true, bool $enPut = true, bool $enForget = true): Store
{
    return new class($enGet, $enPut, $enForget) implements Store
    {
        public function __construct(
            private bool $enGet,
            private bool $enPut,
            private bool $enForget,
        ) {}

        /** @var array<string, mixed> */
        private array $datos = [];

        private function reventar(): never
        {
            // El mismo tipo de error que tira un backend inalcanzable.
            throw new RuntimeException('Database file at path [database.sqlite] does not exist.');
        }

        public function get($key)
        {
            if ($this->enGet) {
                $this->reventar();
            }

            return $this->datos[$key] ?? null;
        }

        public function many(array $keys)
        {
            return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
        }

        public function put($key, $value, $seconds)
        {
            if ($this->enPut) {
                $this->reventar();
            }
            $this->datos[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds)
        {
            foreach ($values as $k => $v) {
                $this->put($k, $v, $seconds);
            }

            return true;
        }

        public function increment($key, $value = 1)
        {
            return $this->reventar();
        }

        public function decrement($key, $value = 1)
        {
            return $this->reventar();
        }

        public function forever($key, $value)
        {
            return $this->put($key, $value, 0);
        }

        public function forget($key)
        {
            if ($this->enForget) {
                $this->reventar();
            }
            unset($this->datos[$key]);

            return true;
        }

        public function touch($key, $ttl)
        {
            return $this->reventar();
        }

        public function flush()
        {
            $this->datos = [];

            return true;
        }

        public function getPrefix()
        {
            return '';
        }
    };
}

/** Deja el store dado detrás de la facade `Cache`. */
function usarStore(Store $store): void
{
    Container::getInstance()->instance('cache', new CacheRepository($store));
}

/**
 * Registry que cuenta sus escaneos y devuelve un resultado fijo, así los tests
 * no dependen de que exista un `app/Modules` real en disco.
 */
function registrySpy(?Throwable $scanRevienta = null): ModuleProviderRegistry
{
    return new class($scanRevienta) extends ModuleProviderRegistry
    {
        public int $escaneos = 0;

        public function __construct(private ?Throwable $scanRevienta = null) {}

        public function scan(): array
        {
            $this->escaneos++;

            if ($this->scanRevienta !== null) {
                throw $this->scanRevienta;
            }

            return ['App\\Modules\\Ventas\\Providers\\VentasServiceProvider'];
        }
    };
}

beforeEach(function () {
    ModuleProviderRegistry::flushProcessState();
});

test('con el cache caído en la LECTURA, discover() escanea el disco y devuelve', function () {
    usarStore(storeQueRevienta());

    $registry = registrySpy();

    expect($registry->discover())
        ->toBe(['App\\Modules\\Ventas\\Providers\\VentasServiceProvider']);
});

test('con el cache caído en la ESCRITURA, discover() igual devuelve el scan', function () {
    // Lectura sana (devuelve null: no hay nada guardado), escritura reventada.
    usarStore(storeQueRevienta(enGet: false, enPut: true));

    $registry = registrySpy();

    expect($registry->discover())
        ->toBe(['App\\Modules\\Ventas\\Providers\\VentasServiceProvider']);
});

test('sin cache donde apoyarse, el scan corre UNA sola vez por instancia', function () {
    // Sin memo, un cache caído degrada a un scan del disco por cada llamada, y
    // el registry es un singleton al que le pega todo el boot.
    usarStore(storeQueRevienta());

    $registry = registrySpy();
    $registry->discover();
    $registry->discover();
    $registry->discover();

    expect($registry->escaneos)->toBe(1);
});

test('un error DEL SCAN sí se propaga: el try sólo absorbe el cache', function () {
    // La contracara del test de arriba. Si el rescate se tragara cualquier
    // excepción, un bug nuestro en el scan quedaría escondido detrás de una
    // lista de módulos vacía — y la app arrancaría sin módulos, en silencio.
    usarStore(storeQueRevienta());

    $registry = registrySpy(new LogicException('bug nuestro en el scan'));

    expect(fn () => $registry->discover())
        ->toThrow(LogicException::class, 'bug nuestro en el scan');
});

test('flush() con el cache caído no tira abajo al que lo llama', function () {
    usarStore(storeQueRevienta());

    $registry = registrySpy();
    $registry->discover();

    // 🔴 SIN `not->toThrow`, QUE NO AFIRMA LO QUE PARECE. Medido acá mismo:
    //   expect(fn () => throw new RuntimeException('x'))->not->toThrow(Throwable::class)
    // da VERDE. La forma honesta es llamar al método pelado —si tira, el test
    // falla solo, con el stack real— y después afirmar que además HIZO algo:
    // sobrevivir es la mitad del trabajo, la otra mitad es haber olvidado el
    // memo para que el próximo `discover()` vuelva a escanear.
    $registry->flush();
    $registry->discover();

    expect($registry->escaneos)->toBe(2);
});

test('con el cache SANO se sigue cacheando: la segunda instancia no escanea', function () {
    // El rescate no puede haberse comido la optimización. Con un ArrayStore de
    // verdad —el que monta el harness— la segunda instancia tiene que leer del
    // cache y NO tocar el disco.
    $primero = registrySpy();
    $primero->discover();

    $segundo = registrySpy();

    expect($segundo->discover())
        ->toBe(['App\\Modules\\Ventas\\Providers\\VentasServiceProvider'])
        ->and($segundo->escaneos)->toBe(0)
        ->and($primero->escaneos)->toBe(1);
});

test('flush() con el cache sano obliga a rescanear', function () {
    $primero = registrySpy();
    $primero->discover();
    $primero->flush();

    $segundo = registrySpy();
    $segundo->discover();

    expect($segundo->escaneos)->toBe(1);
});

test('el TTL que llega al store sale de la config, con 3600 de default', function () {
    // Reemplaza a un test que leía el archivo fuente y exigía la cadena
    // "3600". Esto mide el segundo que efectivamente recibe el store.
    $espia = new class extends ArrayStore
    {
        public ?int $segundos = null;

        public function put($key, $value, $seconds)
        {
            $this->segundos = $seconds;

            return parent::put($key, $value, $seconds);
        }
    };
    usarStore($espia);

    registrySpy()->discover();
    expect($espia->segundos)->toBe(3600);

    config()->set('mk_director.modules.cache_ttl', 90);
    registrySpy()->flush();
    registrySpy()->discover();

    expect($espia->segundos)->toBe(90);
});

test('un valor cacheado que no es una lista se ignora y se rescanea', function () {
    // Basura de una versión vieja de la clave, o de alguien que pisó el
    // prefijo. Devolverla tal cual haría que el boot itere sobre un string.
    $registry = registrySpy();

    $clave = (function () use ($registry) {
        $m = new ReflectionMethod($registry, 'cacheKey');
        $m->setAccessible(true);

        return (string) $m->invoke($registry);
    })();

    Container::getInstance()->make('cache')->put($clave, 'basura', 60);

    expect($registry->discover())
        ->toBe(['App\\Modules\\Ventas\\Providers\\VentasServiceProvider'])
        ->and($registry->escaneos)->toBe(1);
});
