<?php

declare(strict_types=1);

namespace Mk\Director\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MkEnvelope — toda respuesta JSON sale con el sobre `{success, message, data}`.
 *
 * ── 🔴 QUÉ PROBLEMA RESUELVE, Y POR QUÉ NO PUEDE VIVIR EN CADA CONTROLLER ───
 *
 * El cliente HTTP del paquete (`@makroz/core`, `useApiCore.ts`) hace, textual:
 *
 *     if (!result || result.success !== true) throw new ApiCoreError(...)
 *
 * O sea que **cualquier 2xx sin `success: true` explota en el cliente**, con un
 * mensaje que además culpa al servidor de haber dicho `success=false` cuando lo que
 * pasó es que no dijo nada.
 *
 * Del lado de Laravel, lo único que emitía ese sobre era
 * `BaseController::sendResponse()`. Y `CRUDSmart` no sirve para tomar un pedido,
 * cobrar, encolar una impresión ni sincronizar: para todo eso el consumidor escribe un
 * controller que extiende `Illuminate\Routing\Controller`, devuelve
 * `Resource->response()`, y produce `{data: ...}` sin `success`. Válido para Laravel,
 * ilegible para el cliente del propio paquete.
 *
 * En el piloto NetPizza el defecto vivió detrás de **473 tests en verde** hasta que la
 * app de meseros usó `useApiClient()` contra un controller escrito a mano. El barrido
 * de rutas dio **9 rutas con 200 sin sobre** y 15 más que lo perdían en el camino de
 * error. No era un módulo: eran seis.
 *
 * 🔴 VA EN UN MIDDLEWARE Y NO EN UN HELPER. Un helper que hay que acordarse de llamar
 * es una convención, y el próximo controller que alguien escriba se la olvida — y el
 * síntoma no es un error del servidor, es una app que no arranca. Puesto acá, una ruta
 * nueva nace con el sobre puesto.
 *
 * ── 🔴 EL `data` SE LEVANTA, NO SE ANIDA ────────────────────────────────────
 *
 * Un `Resource->response()` ya produce `{data: {...}}` y una colección paginada
 * `{data: [...], links, meta}`. Este middleware toma ESE `data` y le agrega
 * `success`/`message` como HERMANOS, dejando los demás keys donde estaban:
 *
 *     antes  {"data": {...}, "meta": {...}}
 *     ahora  {"success": true, "message": "", "data": {...}, "meta": {...}}
 *
 * Así ningún path JSON cambia y las aserciones que ya existen siguen valiendo. La
 * alternativa —envolver el cuerpo entero adentro de `data`— daría `data.data.*` y
 * rompería cientos de aserciones; eso no sería «hay que reescribir los tests», sería
 * la señal de que la forma elegida es la equivocada. Es además la misma forma de un
 * nivel que R-PKG-024 ya impuso al `BaseController`.
 *
 * ── Lo que NO toca, y por qué ───────────────────────────────────────────────
 *
 *  - Lo que YA trae `success`: son las respuestas del propio paquete
 *    (`BaseController::sendResponse()`, `MkAbility::errorResponse()`). Reescribirlas
 *    sería pisar `__extraData` y `debugMsg`, que el front lee.
 *  - Los `204` / `304`: no tienen cuerpo. Meterles uno convierte la respuesta en
 *    inválida, y el cliente ya los trata aparte (`body === null` → `success: true`).
 *  - Cualquier cosa que no sea una `JsonResponse` con un array adentro.
 *
 * ── 🔴 `success` SALE DEL STATUS, NO DE UNA OPINIÓN ─────────────────────────
 *
 * `success = status < 400`. Un 4xx con `success: true` haría que el cliente tratara un
 * error como un éxito, que es peor que el bug original.
 *
 * ── Cómo se enchufa ─────────────────────────────────────────────────────────
 *
 * El alias `mk.envelope` queda registrado siempre, para ponerlo en un grupo de rutas.
 * Y `mk_director.response.force_envelope` (env `MK_FORCE_ENVELOPE`) lo empuja al grupo
 * `api` entero.
 *
 * ⚠️ Ese flag es **opt-in a propósito, igual que `authorize_with_policy`**: prenderlo
 * en un `composer update` reescribiría el cuerpo de todas las respuestas JSON de todo
 * consumidor sin que nadie lo pidiera. Un consumidor que ya normalizó el sobre por su
 * cuenta lo tiene que dejar apagado, o sacar su propio middleware primero.
 */
class MkEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if (! $respuesta instanceof JsonResponse) {
            return $respuesta;
        }

        // 204/304 no llevan cuerpo. `isEmpty()` cubre los dos.
        $contenido = $respuesta->getContent();

        if ($respuesta->isEmpty() || $contenido === '' || $contenido === false) {
            return $respuesta;
        }

        $cuerpo = $respuesta->getData(true);

        if (! is_array($cuerpo)) {
            return $respuesta;
        }

        // Ya viene con el sobre puesto (el propio paquete).
        if (array_key_exists('success', $cuerpo)) {
            return $respuesta;
        }

        return $respuesta->setData($this->envolver($cuerpo, $respuesta->getStatusCode()));
    }

    /**
     * @param  array<array-key, mixed>  $cuerpo
     * @return array<string, mixed>
     */
    protected function envolver(array $cuerpo, int $status): array
    {
        $exito = $status < 400;

        // 🔴 EL `message` SE EXTRAE ANTES QUE EL `data`. Al revés, un 2xx sin `data`
        // se llevaría su propio `message` adentro del `data` y el cliente lo perdería
        // de vista.
        $mensaje = '';

        if (isset($cuerpo['message']) && is_string($cuerpo['message'])) {
            $mensaje = $cuerpo['message'];
            unset($cuerpo['message']);
        }

        if (array_key_exists('data', $cuerpo)) {
            $data = $cuerpo['data'];
            unset($cuerpo['data']);
        } elseif ($exito) {
            // Un 2xx sin `data`: el cuerpo entero ES el dato.
            $data = $cuerpo;
            $cuerpo = [];
        } else {
            // Un error no tiene dato. Los demás keys (`errors`, `__extraData`) quedan
            // donde el front ya los lee.
            $data = null;
        }

        return ['success' => $exito, 'message' => $mensaje, 'data' => $data] + $cuerpo;
    }
}
