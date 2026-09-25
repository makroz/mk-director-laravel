<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mk\Director\Controllers\BaseController;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 41: `sendResponse()` NO SABÍA PAGINAR CON UN RESOURCE ELEGIDO POR EL
 * CONTROLLER.
 *
 * El camino de paginador era todo o nada:
 *
 *   - Le pasás el PAGINADOR → emite `__extraData.pagination`, pero el Resource lo
 *     resuelve `autoTransform()` por `Model::$apiResource`: el shaping lo elige el
 *     MODELO.
 *   - Le pasás la `ResourceCollection` —la única forma de que el shaping lo elija el
 *     CONTROLLER— → `$isPaginator` da `false` y la metadata de paginación se pierde
 *     ENTERA.
 *
 * No había una tercera forma. Y `sendResponse(MiResource::collection($paginador))` es
 * exactamente lo que alguien escribe cuando quiere las dos cosas.
 *
 * 🔴 **El síntoma es el peor que hay**: 200, filas correctas, y la lista truncada en
 * silencio. `useMkList` lee la paginación de un solo lugar (`__extraData.pagination`,
 * sin fallback a `meta`), así que el front no puede ni saber que hay más páginas.
 *
 * Medido en el piloto sobre las 38 rutas de listado: **9 rutas / 8 controllers**
 * paginaban de verdad y **ninguna** emitía `__extraData.pagination`.
 */
uses(MkLaravelTestCase::class);

class ResourceCollectionPaginadaController extends BaseController
{
    public function llamar($result, $message = '', $code = 200, array $extra = [])
    {
        return $this->sendResponse($result, $message, $code, $extra);
    }
}

/** Un modelo SIN `$apiResource`: el shaping sólo puede venir del controller. */
class PrecioSinResource extends Model
{
    protected $table = 'precios';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * El Resource que ELIGE el controller. Emite una clave que el modelo no tiene, así
 * que su presencia en la respuesta prueba que el shaping pasó por acá y no por
 * `autoTransform()`.
 */
class PrecioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request = null): array
    {
        return [
            'id' => $this->resource->id,
            'elegido_por_el_controller' => true,
        ];
    }
}

beforeEach(function () {
    $container = Container::getInstance();
    $factory = \Mockery::mock(ResponseFactory::class);
    $factory->shouldReceive('json')->andReturnUsing(
        fn ($data = [], $status = 200, array $headers = []) => new JsonResponse($data, $status, $headers),
    );
    $container->instance('Illuminate\Contracts\Routing\ResponseFactory', $factory);

    // 🔴 `JsonResource::resolve()` resuelve `request` del container cuando no se le
    // pasa una. Sin este binding el test muere con
    // `Target class [request] does not exist` — un error del harness, no del código
    // que se está midiendo.
    $container->instance('request', Request::create('/api/precios', 'GET'));

    config([
        'mk_director.debug' => ['enabled' => false, 'explain_enabled' => false],
    ]);
});

/** @return array<string, mixed> */
function cuerpoDeLaRespuesta(JsonResponse $response): array
{
    return (array) json_decode((string) $response->getContent(), true);
}

function paginadorDePrecios(int $total = 100, int $porPagina = 20, int $pagina = 1): LengthAwarePaginator
{
    $items = new Collection([
        new PrecioSinResource(['id' => 1]),
        new PrecioSinResource(['id' => 2]),
    ]);

    return new LengthAwarePaginator($items, $total, $porPagina, $pagina);
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: una ResourceCollection sobre un paginador emite `__extraData.pagination`', function () {
    $controller = new ResourceCollectionPaginadaController;

    $response = $controller->llamar(PrecioResource::collection(paginadorDePrecios()));
    $cuerpo = cuerpoDeLaRespuesta($response);

    expect($cuerpo['__extraData']['pagination'])->toHaveKeys([
        'current_page', 'last_page', 'per_page', 'total', 'has_more_pages',
    ])
        ->and($cuerpo['__extraData']['pagination']['last_page'])->toBe(5)
        ->and($cuerpo['__extraData']['pagination']['total'])->toBe(100)
        ->and($cuerpo['__extraData']['pagination']['has_more_pages'])->toBeTrue();
});

/**
 * 🔴 Y LA OTRA MITAD, QUE ES LA QUE DA SENTIDO AL HALLAZGO. Emitir la paginación no
 * sirve si para conseguirla se pierde el Resource del controller: eso sería el bug de
 * siempre con otra cara. Esta clave sólo la puede poner `PrecioResource`.
 */
test('🔴 y el shaping sigue siendo el del CONTROLLER, no el del modelo', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(PrecioResource::collection(paginadorDePrecios())));

    expect($cuerpo['data'])->toHaveCount(2)
        ->and($cuerpo['data'][0]['elegido_por_el_controller'])->toBeTrue()
        ->and($cuerpo['data'][0]['id'])->toBe(1);
});

test('el `data` es un array plano de items: nada de `data.data`, `links` ni `meta`', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(PrecioResource::collection(paginadorDePrecios())));

    expect($cuerpo['data'])->toBeArray()
        ->and($cuerpo['data'])->not->toHaveKey('data')
        ->and($cuerpo['data'])->not->toHaveKey('links')
        ->and($cuerpo['data'])->not->toHaveKey('meta')
        // Y la paginación NO vuelve a salir plana: R-PKG-032 la agrupa.
        ->and($cuerpo['__extraData'])->not->toHaveKey('last_page');
});

test('también con un CursorPaginator', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cursor = new CursorPaginator(new Collection([new PrecioSinResource(['id' => 1])]), 20);

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(PrecioResource::collection($cursor)));

    expect($cuerpo['__extraData']['pagination'])->toHaveKeys(['per_page', 'next_cursor', 'prev_cursor'])
        ->and($cuerpo['data'][0]['elegido_por_el_controller'])->toBeTrue();
});

test('el `$extra` del llamador sigue ganando sobre la paginación calculada', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(
        PrecioResource::collection(paginadorDePrecios()),
        extra: ['pagination' => ['propia' => true]],
    ));

    expect($cuerpo['__extraData']['pagination'])->toBe(['propia' => true]);
});

/*
|--------------------------------------------------------------------------
| LOS CONTROLES. El bloque nuevo no puede haberse comido ninguno de los
| caminos que ya funcionaban.
|--------------------------------------------------------------------------
*/

test('CONTROL: una ResourceCollection NO paginada sigue SIN `__extraData`', function () {
    $controller = new ResourceCollectionPaginadaController;

    $sueltos = new Collection([new PrecioSinResource(['id' => 1])]);

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(PrecioResource::collection($sueltos)));

    expect($cuerpo)->not->toHaveKey('__extraData')
        ->and($cuerpo['data'][0]['elegido_por_el_controller'])->toBeTrue();
});

test('CONTROL: el paginador PELADO sigue andando como antes', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(paginadorDePrecios()));

    expect($cuerpo['__extraData']['pagination']['last_page'])->toBe(5)
        ->and($cuerpo['data'])->toHaveCount(2)
        // Sin `$apiResource` en el modelo, el shaping es el del modelo: la clave del
        // Resource del controller NO puede aparecer acá.
        ->and($cuerpo['data'][0])->not->toHaveKey('elegido_por_el_controller');
});

test('CONTROL: un modelo suelto sigue sin `__extraData`', function () {
    $controller = new ResourceCollectionPaginadaController;

    $cuerpo = cuerpoDeLaRespuesta($controller->llamar(new PrecioSinResource(['id' => 9])));

    expect($cuerpo)->not->toHaveKey('__extraData');
});
