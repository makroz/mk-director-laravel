<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mk\Director\Http\Middleware\MkEnvelope;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 38: EL SOBRE `{success}` ES CONTRATO DURO DEL CLIENTE Y EL PAQUETE SÓLO
 * LO EMITÍA DESDE `BaseController`.
 *
 * `@makroz/core` (`useApiCore.ts:291`) hace, textual:
 *
 *     if (!result || result.success !== true) throw new ApiCoreError(...)
 *
 * O sea que **cualquier 2xx sin `success: true` explota en el cliente**. Del lado de
 * Laravel, lo único que emitía ese sobre era `BaseController::sendResponse()`. Un
 * consumidor que escriba un controller que NO sea un CRUD —y el paquete no da otra
 * opción: `CRUDSmart` no sirve para tomar un pedido, cobrar, encolar una impresión ni
 * sincronizar— extiende `Illuminate\Routing\Controller`, devuelve
 * `Resource->response()`, y produce `{data: ...}` sin `success`. Válido para Laravel,
 * ilegible para el cliente del propio paquete.
 *
 * En el piloto el defecto vivió detrás de **473 tests en verde** hasta que la app de
 * meseros usó `useApiClient()` contra un controller escrito a mano: 9 rutas devolvían
 * 200 sin sobre y 15 más lo perdían en el camino de error.
 *
 * ── 🔴 EL `data` SE LEVANTA, NO SE ANIDA ────────────────────────────────────
 *
 * Un `Resource->response()` ya produce `{data: {...}}`, y una colección paginada
 * `{data: [...], links, meta}`. El middleware toma ESE `data` y le agrega
 * `success`/`message` como HERMANOS. Envolver el cuerpo entero adentro de `data` daría
 * `data.data.*` y rompería toda aserción existente — y eso no sería «hay que
 * reescribir los tests», sería la señal de que la forma elegida es la equivocada.
 */
uses(MkLaravelTestCase::class);

function pasarPorElSobre(mixed $respuesta, string $uri = 'api/cosas'): JsonResponse|Response
{
    return (new MkEnvelope)->handle(
        Request::create($uri, 'GET'),
        fn () => $respuesta,
    );
}

function cuerpoDelSobre(mixed $respuesta, string $uri = 'api/cosas'): array
{
    $salida = pasarPorElSobre($respuesta, $uri);

    return (array) $salida->getData(true);
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: un 200 sin sobre sale con `success`, y el `data` queda al mismo nivel', function () {
    $cuerpo = cuerpoDelSobre(new JsonResponse(['data' => ['id' => 7, 'name' => 'Pizza']], 200));

    expect($cuerpo['success'])->toBeTrue()
        ->and($cuerpo['message'])->toBe('')
        // 🔴 `data.id`, NO `data.data.id`. Si esto fuera anidado, toda aserción
        // existente de todo consumidor cambiaría de path.
        ->and($cuerpo['data']['id'])->toBe(7);
});

test('los hermanos del `data` se quedan donde estaban (una colección paginada)', function () {
    $cuerpo = cuerpoDelSobre(new JsonResponse([
        'data' => [['id' => 1]],
        'links' => ['next' => null],
        'meta' => ['total' => 1],
    ], 200));

    expect(array_keys($cuerpo))->toBe(['success', 'message', 'data', 'links', 'meta'])
        ->and($cuerpo['meta']['total'])->toBe(1);
});

test('un 2xx SIN clave `data`: el cuerpo entero ES el dato', function () {
    $cuerpo = cuerpoDelSobre(new JsonResponse(['id' => 7, 'total' => 120], 201));

    expect($cuerpo['success'])->toBeTrue()
        ->and($cuerpo['data'])->toBe(['id' => 7, 'total' => 120]);
});

/**
 * 🔴 El `message` se extrae ANTES que el `data`. Al revés, un 2xx sin `data` se
 * llevaría su propio `message` adentro del `data` y el cliente lo perdería de vista.
 */
test('el `message` del cuerpo sube al sobre y no queda adentro del `data`', function () {
    $cuerpo = cuerpoDelSobre(new JsonResponse(['message' => 'Pedido tomado', 'id' => 7], 201));

    expect($cuerpo['message'])->toBe('Pedido tomado')
        ->and($cuerpo['data'])->toBe(['id' => 7]);
});

/*
|--------------------------------------------------------------------------
| 🔴 `success` SALE DEL STATUS, NO DE UNA OPINIÓN.
|
| Un 4xx con `success: true` haría que el cliente tratara un error como un
| éxito, que es peor que el bug original.
|--------------------------------------------------------------------------
*/

test('un 422 sale con `success: false` y sin inventar un `data`', function () {
    $cuerpo = cuerpoDelSobre(new JsonResponse(['message' => 'Datos inválidos', 'errors' => ['name' => ['requerido']]], 422));

    expect($cuerpo['success'])->toBeFalse()
        ->and($cuerpo['message'])->toBe('Datos inválidos')
        ->and($cuerpo['data'])->toBeNull()
        // `errors` se queda donde el front ya lo lee.
        ->and($cuerpo['errors'])->toBe(['name' => ['requerido']]);
});

/*
|--------------------------------------------------------------------------
| LO QUE NO TOCA. Cada uno de estos es una forma de romper algo que ya
| funcionaba, así que van con su test.
|--------------------------------------------------------------------------
*/

test('CONTROL: lo que YA trae `success` pasa INTACTO', function () {
    // Son las respuestas del propio paquete. Reescribirlas sería pisar
    // `__extraData` y `debugMsg`, que el front lee.
    $original = ['success' => true, 'message' => 'ok', 'data' => [1, 2], '__extraData' => ['pagination' => []]];

    expect(cuerpoDelSobre(new JsonResponse($original, 200)))->toBe($original);
});

test('CONTROL: un 204 no recibe sobre', function () {
    $salida = pasarPorElSobre(new JsonResponse(null, 204));

    // Meterle un cuerpo a un 204 lo convierte en una respuesta inválida, y el
    // cliente los trata aparte (`body === null` → `success: true`).
    //
    // ⚠️ La aserción es «no le agregó el sobre», NO `getContent() === ''`: un
    // `JsonResponse(null, 204)` serializa `'{}'` por su cuenta, así que pedir la
    // cadena vacía mide a Symfony y no a este middleware. Medido acá.
    expect($salida->getStatusCode())->toBe(204)
        ->and($salida->getData(true))->not->toHaveKey('success');
});

test('CONTROL: una respuesta que no es JSON sale tal cual', function () {
    $salida = pasarPorElSobre(new Response('<html></html>', 200));

    expect($salida)->toBeInstanceOf(Response::class)
        ->and($salida->getContent())->toBe('<html></html>');
});

test('CONTROL: un cuerpo JSON que no es un array (una lista pelada) sale tal cual', function () {
    // `JsonResponse` admite un escalar. Envolverlo exigiría adivinar qué es.
    $salida = pasarPorElSobre(new JsonResponse('texto suelto', 200));

    expect($salida->getData(true))->toBe('texto suelto');
});
