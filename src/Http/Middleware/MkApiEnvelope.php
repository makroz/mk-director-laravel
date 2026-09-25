<?php

declare(strict_types=1);

namespace Mk\Director\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El normalizador del sobre ({@see MkEnvelope}) como middleware GLOBAL,
 * limitado a las rutas del API.
 *
 * Es lo que engancha `mk_director.response.force_envelope`. Hallazgo 66: el
 * flag empujaba {@see MkEnvelope} al grupo `api`, y los módulos registran sus
 * rutas con `loadRoutesFrom()` FUERA de ese grupo —por eso su prefijo lleva
 * `api/` a mano—. O sea que en un consumidor organizado en módulos, que es el
 * que el paquete scaffoldea, el flag no llegaba a ninguna ruta.
 *
 * Global también envuelve lo que arma el manejador de excepciones (401, 403,
 * 404, 422), que no siempre pasa por el grupo.
 *
 * Las rutas son `mk_director.response.envelope_paths` (default `['api/*']`),
 * con la sintaxis de `Request::is()`.
 */
class MkApiEnvelope extends MkEnvelope
{
    public function handle(Request $request, Closure $next): Response
    {
        $rutas = (array) config('mk_director.response.envelope_paths', ['api/*']);

        if ($rutas === [] || ! $request->is(...$rutas)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
