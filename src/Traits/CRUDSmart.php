<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Managers\ListManager;
use Mk\Director\Managers\PluginManager;
use Mk\Director\DTOs\DTOFactory;

/**
 * CRUDSmart Trait - Lógica CRUD automática basada en configuración
 * 
 * Este trait proporciona métodos automáticos de CRUD que leen la configuración
 * del controller y ejecutan automáticamente el flujo completo.
 */
trait CRUDSmart
{
    /**
     * Configuración del módulo - debe definirse en el controller
     */
    protected array $mkConfig = [];

    /**
     * Obtener el modelo desde configuración
     */
    protected function getModel(): string
    {
        return $this->mkConfig['model'] ?? Model::class;
    }

    /**
     * Obtener el service desde configuración
     */
    protected function getService(): ?MkModuleServiceInterface
    {
        $serviceClass = $this->mkConfig['service'] ?? null;
        
        if (!$serviceClass) {
            return null;
        }

        // Si es un string, resolver del container
        if (is_string($serviceClass) && app()->bound($serviceClass)) {
            return app($serviceClass);
        }

        return null;
    }

    /**
     * Obtener el resource para transformación
     */
    protected function getResource(): ?string
    {
        return $this->mkConfig['resource'] ?? null;
    }

    /**
     * Obtener campos searchables
     */
    protected function getSearchable(): array
    {
        return $this->mkConfig['searchable'] ?? [];
    }

    /**
     * Obtener relaciones para eager loading
     */
    protected function getWith(): array
    {
        return $this->mkConfig['with'] ?? [];
    }

    /**
     * Obtener contadores para eager loading
     */
    protected function getWithCount(): array
    {
        return $this->mkConfig['withCount'] ?? [];
    }

    /**
     * Obtener relaciones dinámicas permitidas (include)
     */
    protected function getAllowedIncludes(): array
    {
        return $this->mkConfig['allowedIncludes'] ?? [];
    }

    /**
     * Obtener contadores dinámicos permitidos (withCount)
     */
    protected function getAllowedWithCount(): array
    {
        return $this->mkConfig['allowedWithCount'] ?? [];
    }

    /**
     * Obtener configuraciones de características para ListManager
     */
    protected function getListFeatures(): array
    {
        return $this->mkConfig['features'] ?? [];
    }

    /**
     * Verificar si el caché automático está activo (Global vs Local)
     */
    protected function isCacheEnabled(): bool
    {
        $features = $this->getListFeatures();
        return $features['auto_cache'] ?? config('mk_director.features.auto_cache', false);
    }

    /**
     * Obtener tiempo de vida del caché en segundos
     */
    protected function getCacheTTL(): int
    {
        return $this->mkConfig['cache_ttl'] ?? config('mk_director.cache.default_ttl', 3600);
    }

    /**
     * Obtener etiquetas (tags) para el caché, por defecto es el nombre de la tabla
     */
    protected function getCacheTags(): array
    {
        if (isset($this->mkConfig['cache_tags'])) {
            return (array) $this->mkConfig['cache_tags'];
        }
        
        $modelClass = $this->getModel();
        return [(new $modelClass)->getTable()];
    }

    /**
     * Obtener DTO class para validación de tipos
     */
    protected function getDTOClass(): ?string
    {
        return $this->mkConfig['dto'] ?? null;
    }

    /**
     * Obtener mapa de enums
     */
    protected function getEnumMap(): array
    {
        return $this->mkConfig['enumMap'] ?? [];
    }

    /**
     * Obtener campos fillable del modelo
     */
    protected function getFillable(): array
    {
        $modelClass = $this->getModel();
        $model = new $modelClass;
        return $model->getFillable();
    }

    /**
     * Obtener el PluginManager
     */
    protected function getPluginManager(): PluginManager
    {
        $manager = app(PluginManager::class);

        // Set the controller context
        $manager->setControllerConfig($this->mkConfig);

        // Register local plugins if configured in the controller
        if (isset($this->mkConfig['plugins']) && is_array($this->mkConfig['plugins'])) {
            $manager->registerPlugins($this->mkConfig['plugins']);
        }

        // Validate Requirements (Only in debug mode)
        $manager->validateRequirements($this->getFillable());

        return $manager;
    }

    /**
     * GET /resource - Listar con paginación, filtros, búsqueda
     */
    public function index(Request $request)
    {
        $modelClass = $this->getModel();
        $model = new $modelClass;
        
        // Apply service hook beforeList
        $service = $this->getService();
        if ($service && method_exists($service, 'beforeList')) {
            $model = $service->beforeList($request, $model) ?? $model;
        }

        // Apply list management (filters, sorting, search, pagination)
        $searchable = $this->getSearchable();
        $allowedIncludes = $this->getAllowedIncludes();
        $allowedWithCount = $this->getAllowedWithCount();
        $listFeatures = $this->getListFeatures();
        $query = ListManager::apply($request, $model, $searchable, $allowedIncludes, $allowedWithCount, $listFeatures);

        // Apply service hook beforeSearch
        if ($service && method_exists($service, 'beforeSearch')) {
            $query = call_user_func([$service, 'beforeSearch'], $request, $query);
        }

        // Add eager loading
        $query->with($this->getWith());
        $query->withCount($this->getWithCount());

        // Plugin Hook: beforeQuery
        $this->getPluginManager()->fireBeforeQuery($query, $request);

        $perPage = ListManager::getPerPage($request);
        $page = $request->query('page', 1);
        $cursor = $request->query('cursor', '');
        $cacheKey = md5($query->toSql() . serialize($query->getBindings()) . 'page:' . $page . 'cursor:' . $cursor . 'perPage:' . $perPage);

        $resolver = function() use ($query, $perPage, $listFeatures) {
            $paginationType = $listFeatures['pagination_type'] ?? config('mk_director.features.pagination_type', 'length_aware');
            if ($paginationType === 'cursor') {
                return $query->cursorPaginate($perPage);
            }
            return $query->paginate($perPage);
        };

        $paginator = $this->isCacheEnabled()
            ? \Mk\Director\Managers\CacheManager::remember($cacheKey, $this->getCacheTags(), $this->getCacheTTL(), $resolver)
            : $resolver();

        // Apply service hook afterList
        $total = method_exists($paginator, 'total') ? $paginator->total() : null;
        $extra = ['total' => $total];
        if ($service && method_exists($service, 'afterList')) {
            $extra = array_merge($extra, $service->afterList($request, $paginator->items(), $total));
        }

        // Add pagination info if ListManager has getExtraData
        if (method_exists('\Mk\Director\Managers\ListManager', 'getExtraData')) {
            $extra = array_merge($extra, ListManager::getExtraData($paginator));
        } else {
            // Include basic cursors if cursor pagination is in use
            if (method_exists($paginator, 'nextCursor')) {
                $extra['next_cursor'] = $paginator->nextCursor() ? $paginator->nextCursor()->encode() : null;
                $extra['prev_cursor'] = $paginator->previousCursor() ? $paginator->previousCursor()->encode() : null;
            }
        }

        $response = [
            'data' => $paginator->items(),
            '__extraData' => $extra
        ];

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($response);

        return $this->sendResponse($response);
    }

    /**
     * GET /resource/{id} - Ver detalle
     */
    public function show(Request $request, int $id)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        // Build query with eager loading
        $query = $modelClass::query();
        $query->with($this->getWith());
        $query->withCount($this->getWithCount());

        // Plugin Hook: beforeQuery
        $this->getPluginManager()->fireBeforeQuery($query, $request);

        // Apply dynamic includes/counts if any
        $listFeatures = $this->getListFeatures();
        $useIncludes = $listFeatures['dynamic_includes'] ?? config('mk_director.features.dynamic_includes', true);
        
        if ($useIncludes) {
            $query = ListManager::applyIncludes($request, $query, $this->getAllowedIncludes(), $this->getAllowedWithCount());
        }

        $resolver = function() use ($id, $query) {
            return $query->findOrFail($id);
        };

        $cacheKey = md5($query->toSql() . serialize($query->getBindings()) . 'show_id:' . $id);

        $model = $this->isCacheEnabled()
            ? \Mk\Director\Managers\CacheManager::remember($cacheKey, $this->getCacheTags(), $this->getCacheTTL(), $resolver)
            : $resolver();

        // Apply service hook beforeShow
        if ($service && method_exists($service, 'beforeShow')) {
            $model = $service->beforeShow($request, $model) ?? $model;
        }

        // Auto transform with resource
        $data = $this->autoTransform($model);

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data);
    }

    /**
     * POST /resource - Crear
     */
    public function store(Request $request)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        // Apply service hook beforeCreate
        $input = $request->all();
        
        // Plugin Hook: beforeSave
        $this->getPluginManager()->fireBeforeSave($request, $input, 'create');

        if ($service && method_exists($service, 'beforeCreate')) {
            $input = $service->beforeCreate($request, $input) ?? $input;
        }

        // Apply DTO validation (type safety + enum validation)
        $input = $this->applyDTOValidation($input);

        // Filter input to only fillable fields
        $fillable = $this->getFillable();
        $input = array_intersect_key($input, array_flip($fillable));

        // Create model
        $model = $modelClass::create($input);

        // Apply service hook afterCreate
        if ($service && method_exists($service, 'afterCreate')) {
            $service->afterCreate($request, $model, $input);
        }

        // Plugin Hook: afterSave
        $this->getPluginManager()->fireAfterSave($model, $request, 'create');

        // Auto-invalidate cache if enabled
        if ($this->isCacheEnabled()) {
            \Mk\Director\Managers\CacheManager::flush($this->getCacheTags());
        }

        // Auto transform with resource
        $data = $this->autoTransform($model);

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data, 'Creado con éxito', 201);
    }

    /**
     * PUT/PATCH /resource/{id} - Actualizar
     */
    public function update(Request $request, int $id)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        $model = $modelClass::findOrFail($id);

        // Get input
        $input = $request->all();

        // Plugin Hook: beforeSave
        $this->getPluginManager()->fireBeforeSave($request, $input, 'update');

        // Apply service hook beforeUpdate
        if ($service && method_exists($service, 'beforeUpdate')) {
            $input = $service->beforeUpdate($request, $id, $input) ?? $input;
        }

        // Apply DTO validation (type safety + enum validation)
        $input = $this->applyDTOValidation($input);

        // Filter input to only fillable fields
        $fillable = $this->getFillable();
        $input = array_intersect_key($input, array_flip($fillable));

        // Update model
        $model->update($input);
        $model = $model->fresh();

        // Apply service hook afterUpdate
        if ($service && method_exists($service, 'afterUpdate')) {
            $service->afterUpdate($request, $model, $input, $id);
        }

        // Plugin Hook: afterSave
        $this->getPluginManager()->fireAfterSave($model, $request, 'update');

        // Auto-invalidate cache if enabled
        if ($this->isCacheEnabled()) {
            \Mk\Director\Managers\CacheManager::flush($this->getCacheTags());
        }

        // Auto transform with resource
        $data = $this->autoTransform($model);

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data, 'Actualizado con éxito');
    }

    /**
     * DELETE /resource/{id} - Eliminar
     */
    public function destroy(Request $request, int $id)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        $model = $modelClass::findOrFail($id);

        // Plugin Hook: beforeDelete
        $this->getPluginManager()->fireBeforeDelete($model, $request);

        // Apply service hook beforeDelete
        if ($service && method_exists($service, 'beforeDelete')) {
            $canDelete = $service->beforeDelete($request, $model, $id);
            if ($canDelete === false) {
                return $this->sendError('No se puede eliminar este registro');
            }
        }

        $model->delete();

        // Apply service hook afterDelete
        if ($service && method_exists($service, 'afterDelete')) {
            $service->afterDelete($request, $model, $id);
        }

        // Plugin Hook: afterDelete
        $this->getPluginManager()->fireAfterDelete($model, $request);

        // Auto-invalidate cache if enabled
        if ($this->isCacheEnabled()) {
            \Mk\Director\Managers\CacheManager::flush($this->getCacheTags());
        }

        return $this->sendResponse(true, 'Eliminado con éxito');
    }

    /**
     * Aplicar validación de DTO (type safety + enum validation)
     */
    protected function applyDTOValidation(array $input): array
    {
        $modelClass = $this->getModel();
        $dtoClass = $this->getDTOClass();
        $enumMap = $this->getEnumMap();

        try {
            return DTOFactory::makeFromArray($input, $modelClass, $dtoClass, $enumMap);
        } catch (\InvalidArgumentException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'payload' => $e->getMessage()
            ]);
        }
    }

    /**
     * Auto transformar con resource si está configurado
     */
    protected function autoTransform($data)
    {
        $resourceClass = $this->getResource();

        if (!$resourceClass || !class_exists($resourceClass)) {
            return $data;
        }

        // Single model
        if ($data instanceof Model) {
            return new $resourceClass($data);
        }

        // Collection or Paginator wrappers
        if ($data instanceof \Illuminate\Support\Collection || 
            $data instanceof \Illuminate\Contracts\Pagination\Paginator || 
            $data instanceof \Illuminate\Contracts\Pagination\CursorPaginator) {
            return $resourceClass::collection($data);
        }

        return $data;
    }
}
