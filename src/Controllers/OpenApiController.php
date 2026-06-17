<?php

declare(strict_types=1);

namespace Mk\Director\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use Mk\Director\Services\OpenApiGeneratorService;

class OpenApiController extends BaseController
{
    /**
     * Devuelve el JSON de OpenAPI autogenerado
     */
    public function spec(OpenApiGeneratorService $generator): JsonResponse
    {
        return response()->json($generator->generate());
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
