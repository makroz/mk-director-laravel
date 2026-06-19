<?php

declare(strict_types=1);

namespace Mk\Director\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Cache;
use Mk\Director\Services\OpenApiGeneratorService;

class OpenApiController extends BaseController
{
    /**
     * Cache key for the OpenAPI spec. Bumped by mk:generate-docs
     * (audit R4-005) so the controller never serves a stale spec
     * after a manual regeneration.
     */
    public const CACHE_KEY = 'mk_openapi_spec';

    /**
     * Default TTL: 24 hours. Configurable via
     * mk_director.openapi.cache_ttl (seconds).
     */
    public const DEFAULT_TTL = 86400;

    /**
     * Devuelve el JSON de OpenAPI autogenerado.
     *
     * Audit R4-005: the spec was regenerated on every request,
     * which meant /mk/openapi.json walked every route + introspected
     * every model on each hit. Now wrapped in Cache::remember() with
     * a 24h TTL — a typical consumer only regenerates the spec when
     * they ship code, so a full day of cache misses would mean a
     * serious deploy drift.
     *
     * `mk:generate-docs` calls {@see OpenApiController::CACHE_KEY}
     * forget() so manual regeneration picks up immediately.
     */
    public function spec(OpenApiGeneratorService $generator): JsonResponse
    {
        $ttl = (int) config('mk_director.openapi.cache_ttl', self::DEFAULT_TTL);

        $spec = Cache::remember(
            self::CACHE_KEY,
            $ttl,
            fn (): array => $generator->generate(),
        );

        return new JsonResponse($spec);
    }

    /**
     * Muestra la vista con Swagger UI embebido (Si deseamos proveer la UI por defecto)
     */
    public function docs()
    {
        $specUrl = route('mk.openapi.spec');
        
        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1" />
            <title>MK-Director API Docs</title>
            <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui.css" />
        </head>
        <body>
        <div id="swagger-ui"></div>
        <script src="https://unpkg.com/swagger-ui-dist@5.11.0/swagger-ui-bundle.js" crossorigin></script>
        <script>
            window.onload = () => {
                window.ui = SwaggerUIBundle({
                    url: '{$specUrl}',
                    dom_id: '#swagger-ui',
                });
            };
        </script>
        </body>
        </html>
        HTML;

        return response($html)->header('Content-Type', 'text/html');
    }
}
