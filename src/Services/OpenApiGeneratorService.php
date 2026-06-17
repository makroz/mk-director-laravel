<?php

declare(strict_types=1);

namespace Mk\Director\Services;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mk\Director\Controllers\SmartController;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Services\ModuleRouteExtractor;

class OpenApiGeneratorService
{
    /**
     * Generar especificación OpenAPI 3.0 completa en array
     */
    public function generate(): array
    {
        $spec = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('app.name', 'MK-Director API') . ' - SaaS API',
                'description' => 'Documentación Auto-Generada por MK-Director Enterprise Guard.',
                'version' => config('app.version', '1.0.0'),
            ],
            'servers' => [
                [
                    'url' => url('/'),
                    'description' => 'Servidor Principal',
                ]
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                    ]
                ]
            ],
            'security' => [
                ['bearerAuth' => []]
            ]
        ];

        $modelsProcessed = [];

        foreach (Route::getRoutes() as $route) {
            $controller = $this->getRouteController($route);

            if ($controller instanceof SmartController) {
                $mkConfig = $controller->getMkConfig();
                $modelClass = $mkConfig['model'] ?? null;
                $uri = '/' . ltrim($route->uri(), '/');
                $method = strtolower($route->methods()[0]);
                $actionName = $route->getActionMethod();
                
                if (!$modelClass || !class_exists($modelClass)) {
                    continue;
                }

                $modelName = class_basename($modelClass);

                // Initialize path
                if (!isset($spec['paths'][$uri])) {
                    $spec['paths'][$uri] = [];
                }

                $tags = [Str::plural($modelName)];

                // Process Route
                $spec['paths'][$uri][$method] = $this->generateRouteSpec($method, $actionName, $modelName, $tags);

                // Add Path parameters if uri has {id} etc.
                if (str_contains($uri, '{')) {
                    preg_match_all('/{(.*?)}/', $uri, $matches);
                    $spec['paths'][$uri][$method]['parameters'] = array_map(function($param) {
                        return [
                            'name' => str_replace('?', '', $param),
                            'in' => 'path',
                            'required' => !str_contains($param, '?'),
                            'schema' => ['type' => 'string']
                        ];
                    }, $matches[1]);
                }

                // Process Component Schema
                if (!in_array($modelClass, $modelsProcessed)) {
                    $spec['components']['schemas'][$modelName] = $this->generateModelSchema($modelClass);
                    $spec['components']['schemas'][$modelName . 'Input'] = $this->generateInputSchema($modelClass);
                    $modelsProcessed[] = $modelClass;
                }
            }
        }

        // Surface every MME module's route (auth, custom actions, etc.)
        // even if the controller isn't a SmartController.
        $moduleExtractor = new ModuleRouteExtractor();
        foreach ($moduleExtractor->extract() as $modRoute) {
            $uri = '/' . ltrim($modRoute['uri'], '/');
            $method = strtolower($modRoute['method']);
            $tag = Str::pluralStudly($modRoute['module']);

            if (! isset($spec['paths'][$uri])) {
                $spec['paths'][$uri] = [];
            }
            // Don't overwrite a SmartController-generated entry.
            if (isset($spec['paths'][$uri][$method])) {
                continue;
            }

            $spec['paths'][$uri][$method] = $this->generateModuleRouteSpec(
                $modRoute,
                $tag,
            );

            // Path params (e.g. {id})
            if (str_contains($uri, '{')) {
                preg_match_all('/{(.*?)}/', $uri, $matches);
                $spec['paths'][$uri][$method]['parameters'] = array_map(function ($param) {
                    return [
                        'name' => str_replace('?', '', $param),
                        'in' => 'path',
                        'required' => ! str_contains($param, '?'),
                        'schema' => ['type' => 'string'],
                    ];
                }, $matches[1]);
            }
        }

        return $spec;
    }

    /**
     * Generate an OpenAPI spec for a route that comes from an MME module
     * (not a SmartController-based CRUD). Uses a generic shape that
     * documents the handler, tag, and authentication requirements.
     *
     * @param array{module: string, file: string, method: string, uri: string, handler: string, middleware: list<string>} $modRoute
     * @return array<string, mixed>
     */
    protected function generateModuleRouteSpec(array $modRoute, string $tag): array
    {
        $action = Str::afterLast($modRoute['handler'], '@');
        $summary = ucfirst($action) . ' (' . $modRoute['module'] . ')';

        $spec = [
            'summary' => $summary,
            'description' => 'Module: ' . $modRoute['module'] . '. Handler: `' . $modRoute['handler'] . '`.',
            'tags' => [$tag],
            'responses' => [
                '200' => ['description' => 'OK'],
                '401' => ['description' => 'Unauthenticated'],
                '422' => ['description' => 'Validation error'],
                '500' => ['description' => 'Server error'],
            ],
        ];

        if (in_array(strtolower($modRoute['method']), ['post', 'put', 'patch'], true)) {
            $spec['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                ],
            ];
        }

        return $spec;
    }

    /**
     * Extraer controlador de la ruta
     */
    protected function getRouteController($route)
    {
        if (is_string($route->getAction('uses'))) {
            $controllerClass = explode('@', $route->getAction('uses'))[0];
            if (class_exists($controllerClass)) {
                return app($controllerClass);
            }
        } elseif (is_array($route->getAction('uses'))) {
            $controllerClass = $route->getAction('uses')[0];
            if (class_exists($controllerClass)) {
                return app($controllerClass);
            }
        }

        return $route->getController();
    }

    /**
     * Generar especificación del verbo
     */
    protected function generateRouteSpec(string $method, string $actionName, string $modelName, array $tags): array
    {
        $responses = [
            '200' => [
                'description' => 'Operación exitosa',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/' . $modelName
                        ]
                    ]
                ]
            ],
            '401' => ['description' => 'No Autorizado'],
            '403' => ['description' => 'Prohibido'],
        ];

        $spec = [
            'summary' => ucfirst($actionName) . ' ' . $modelName,
            'tags' => $tags,
            'responses' => $responses
        ];

        // List Endpoint (Index)
        if ($actionName === 'index') {
            $spec['parameters'] = [
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
            ];
            $spec['responses']['200']['content']['application/json']['schema'] = [
                'type' => 'object',
                'properties' => [
                    'data' => [
                        'type' => 'array',
                        'items' => ['$ref' => '#/components/schemas/' . $modelName]
                    ],
                    '__extraData' => [
                        'type' => 'object'
                    ]
                ]
            ];
        }

        // Mutations
        if (in_array($method, ['post', 'put', 'patch'])) {
            $spec['requestBody'] = [
                'required' => true,
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/' . $modelName . 'Input'
                        ]
                    ]
                ]
            ];
            $spec['responses']['422'] = [
                'description' => 'Error de Validación (DTOFactory)',
            ];
            
            if ($method === 'post') {
                $spec['responses']['201'] = $spec['responses']['200'];
                unset($spec['responses']['200']);
            }
        }

        if ($method === 'delete') {
            unset($spec['responses']['200']['content']);
        }

        return $spec;
    }

    /**
     * Introspección mixta (Casts + Nativo) para generar JSON Schema de un modelo
     */
    protected function generateModelSchema(string $modelClass): array
    {
        /** @var Model $model */
        $model = new $modelClass;
        $table = $model->getTable();
        $casts = $model->getCasts();
        
        $properties = [];
        // Intentar obtener columnas de schema
        $columns = [];
        try {
            if (method_exists(Schema::class, 'getColumns')) {
                // Laravel 11 Native schema
                $columns = Schema::getColumns($table);
            } else {
                $cols = Schema::getColumnListing($table);
                foreach ($cols as $col) {
                    $columns[] = ['name' => $col, 'type_name' => Schema::getColumnType($table, $col)];
                }
            }
        } catch (\Throwable $e) {
            // Silence if DB is not available or doctrine is missing
            $columns = array_map(fn($col) => ['name' => $col, 'type_name' => 'string'], $model->getFillable());
        }

        foreach ($columns as $column) {
            $name = $column['name'];
            $nativeType = strtolower($column['type_name'] ?? 'string');
            
            $typeConfig = $this->mapTypeToOpenApi($nativeType);

            // Override with Casts if exists
            if (isset($casts[$name])) {
                $castVal = $casts[$name];
                $coreCast = explode(':', is_string($castVal) ? $castVal : '')[0];

                if (is_string($castVal) && enum_exists($castVal)) {
                    $typeConfig = [
                        'type' => 'string',
                        'enum' => array_column($castVal::cases(), 'value'),
                    ];
                } elseif (in_array($coreCast, ['array', 'json', 'object', 'collection'])) {
                    $typeConfig = ['type' => 'object'];
                } elseif (in_array($coreCast, ['int', 'integer'])) {
                    $typeConfig = ['type' => 'integer'];
                } elseif (in_array($coreCast, ['bool', 'boolean'])) {
                    $typeConfig = ['type' => 'boolean'];
                } elseif (in_array($coreCast, ['float', 'double', 'decimal'])) {
                    $typeConfig = ['type' => 'number', 'format' => 'float'];
                }
            }

            $properties[$name] = $typeConfig;
        }

        return [
            'type' => 'object',
            'properties' => $properties
        ];
    }

    /**
     * Schema de request basado *solo* en Fillable
     */
    protected function generateInputSchema(string $modelClass): array
    {
        $baseSchema = $this->generateModelSchema($modelClass);
        $model = new $modelClass;
        $fillable = $model->getFillable();

        $inputProperties = [];
        foreach ($fillable as $field) {
            if (isset($baseSchema['properties'][$field])) {
                $inputProperties[$field] = $baseSchema['properties'][$field];
            } else {
                $inputProperties[$field] = ['type' => 'string'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $inputProperties
        ];
    }

    protected function mapTypeToOpenApi(string $nativeType): array
    {
        return match(true) {
            str_contains($nativeType, 'int') => ['type' => 'integer'],
            str_contains($nativeType, 'bool') => ['type' => 'boolean'],
            str_contains($nativeType, 'float') || str_contains($nativeType, 'double') || str_contains($nativeType, 'decimal') => ['type' => 'number', 'format' => 'float'],
            str_contains($nativeType, 'json') => ['type' => 'object'],
            str_contains($nativeType, 'date') || str_contains($nativeType, 'timestamp') => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string']
        };
    }
}
