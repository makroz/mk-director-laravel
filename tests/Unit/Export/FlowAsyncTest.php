<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Export;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\CustomReportDispatcher;
use Mk\Director\Export\CustomReportRegistry;
use Mk\Director\Export\Discovery\ClassDiscovery;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\FilasDelExport;
use Mk\Director\Export\InlineExportDispatcher;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Las piezas del flow async que se pueden ejercer sin base de datos.
 *
 * El camino completo —crear el reporte, encolar, correr el job— vive en la
 * app de prueba (`apps/sandbox-laravel`), donde hay migraciones y cola. Acá se
 * miden las decisiones que NO necesitan nada de eso y que en el original
 * fallaban en silencio.
 */
uses(MkLaravelTestCase::class);

// ────────────────────────────────────────────────────────────────────
// El buzón
// ────────────────────────────────────────────────────────────────────

test('🔴 una lista VACÍA no es lo mismo que "el controller no pasó"', function () {
    $buzon = new FilasDelExport;

    // Nadie dejó nada: el controller no pasó por el trait.
    expect($buzon->hayFilas())->toBeFalse();

    // El controller pasó y su filtro no matcheó nada. Es una lista legítima.
    $buzon->dejar([]);
    expect($buzon->hayFilas())->toBeTrue();
    expect($buzon->filas())->toBe([]);

    // 🔴 Confundir los dos casos es cómo el motor llegó a marcar `completed`
    // reportes que nunca tuvieron datos. Un `filas() !== []` no los distingue.
});

test('vaciar borra la marca, no sólo el contenido', function () {
    $buzon = new FilasDelExport;
    $buzon->dejar([['a' => 1]]);

    $buzon->vaciar();

    // ⚠️ Si `vaciar()` sólo pusiera las filas en `[]` y dejara la marca en
    // true, el job siguiente encontraría "hay filas" y exportaría una lista
    // vacía como si fuera el resultado real de su consulta.
    expect($buzon->hayFilas())->toBeFalse();
});

// ────────────────────────────────────────────────────────────────────
// El descubrimiento de clases
// ────────────────────────────────────────────────────────────────────

test('🔴 el FQCN sale del namespace del archivo, no de cuentas con la ruta', function () {
    $raiz = sys_get_temp_dir().'/mk-discovery-'.uniqid('', true);
    $dir = $raiz.'/Finanzas/Pagos/Export';
    mkdir($dir, 0777, true);

    // Una clase cuyo nombre TERMINA EN 'h'. Con el `rtrim($class, '.php')` del
    // original esto se convertía en `...\Grap` y `class_exists` daba false: el
    // registry quedaba vacío y el export se iba por otro camino sin un solo
    // error visible.
    file_put_contents($dir.'/Graph.php', <<<'PHP'
    <?php
    namespace Prueba\Discovery\Anidado;
    class Graph {}
    PHP);

    $metodo = new \ReflectionMethod(ClassDiscovery::class, 'fqcnDe');

    expect($metodo->invoke(null, new \SplFileInfo($dir.'/Graph.php')))
        ->toBe('Prueba\Discovery\Anidado\Graph');

    unlink($dir.'/Graph.php');
});

test('la búsqueda es recursiva: encuentra un módulo anidado', function () {
    $raiz = sys_get_temp_dir().'/mk-discovery-'.uniqid('', true);
    $dir = $raiz.'/Finanzas/Pagos/Export';
    mkdir($dir, 0777, true);

    file_put_contents($dir.'/PagosExportConfig.php', <<<'PHP'
    <?php
    namespace Prueba\Discovery\Profundo;
    class PagosExportConfig {}
    PHP);

    $metodo = new \ReflectionMethod(ClassDiscovery::class, 'archivosEn');

    $encontrados = [];
    foreach ($metodo->invoke(null, $raiz, 'Export') as $archivo) {
        $encontrados[] = $archivo->getBasename();
    }

    // ⚠️ El original miraba exactamente UN nivel bajo la raíz, así que un
    // `Modules/Finanzas/Pagos/Export/` no lo veía nunca.
    expect($encontrados)->toBe(['PagosExportConfig.php']);

    unlink($dir.'/PagosExportConfig.php');
});

test('una carpeta que no existe no rompe el descubrimiento', function () {
    $encontradas = ClassDiscovery::implementando(
        ExportConfigInterface::class,
        ['/no/existe/en/ningun/lado'],
        'Export',
    );

    // ⚠️ Devolver `[]` y no reventar: un consumer sin módulos todavía es un
    // caso normal, y una excepción acá tumbaría CUALQUIER listado.
    expect($encontradas)->toBe([]);
});

// ────────────────────────────────────────────────────────────────────
// El corte de re-entrada
// ────────────────────────────────────────────────────────────────────

test('🔴 la marca de re-entrada NO se guarda en los params del reporte', function () {
    config()->set('mk_director.tenant.enabled', false);

    $manager = managerDePrueba(new FilasDelExport);

    $metodo = new \ReflectionMethod($manager, 'paramsDelPedido');

    $request = Request::create('/x', 'GET', [
        'filterBy' => ['status:1'],
        '_export' => 'pdf',
        AsyncExportManager::MARCA_DE_REENTRADA => 42,
    ]);

    $params = $metodo->invoke($manager, $request);

    // 🔴 Guardar la marca haría que el job, al reconstruir el request desde
    // los params, se re-dispachara a sí mismo. Es la cadena que dejó 1648
    // reportes huérfanos.
    expect($params)->not->toHaveKey(AsyncExportManager::MARCA_DE_REENTRADA);

    // Y `_export` tampoco: es lo que pidió el usuario en ESTE request.
    expect($params)->not->toHaveKey('_export');

    // Los filtros sí, que es de lo único que el job reconstruye la lista.
    expect($params)->toHaveKey('filterBy');
});

test('con la marca puesta, el manager ENTREGA las filas en vez de crear otro reporte', function () {
    config()->set('mk_director.tenant.enabled', false);
    conResponseFactory();

    $buzon = new FilasDelExport;
    $manager = managerDePrueba($buzon);

    $request = Request::create('/x', 'GET', [
        AsyncExportManager::MARCA_DE_REENTRADA => 42,
    ]);

    $filas = [['id' => 1], ['id' => 2]];
    $respuesta = $manager->export($request, $filas, 'lo-que-sea');

    // 🔴 200 y no 202: no se encoló nada. Un 202 acá significaría que se creó
    // otro reporte, que es exactamente el bug.
    expect($respuesta->getStatusCode())->toBe(200);
    expect($buzon->hayFilas())->toBeTrue();
    expect($buzon->filas())->toBe($filas);
});

/**
 * El container mínimo de los tests unitarios no trae `ResponseFactory`, y
 * `response()->json()` lo resuelve. Se bindea un doble que devuelve una
 * `JsonResponse` de verdad: alcanza para mirar el código de estado, que es lo
 * único que este archivo afirma sobre las respuestas.
 */
function conResponseFactory(): void
{
    Container::getInstance()->bind(
        ResponseFactory::class,
        fn () => new class
        {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        }
    );
}

/** El manager, armado a mano con dobles que no tocan disco ni base. */
function managerDePrueba(FilasDelExport $buzon): AsyncExportManager
{
    return new AsyncExportManager(
        new ExportConfigRegistry,
        new CustomReportRegistry,
        new InlineExportDispatcher(new ExportConfigRegistry),
        new CustomReportDispatcher(new CustomReportRegistry),
        Container::getInstance()->make(TenantContext::class),
        $buzon,
    );
}
