<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\DTOs\DTOFactory;
use Mk\Director\Managers\CacheManager;
use Mk\Director\Managers\ListManager;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Tenancy\TenantContext;

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
     * Obtener el service desde configuración.
     *
     * FEEDBACK10 (F10-B03): pre-fix, esto solo resolvía cuando
     * `app()->bound($serviceClass)` era true — pero el scaffolder pinea
     * `'service' => {Scope}Service::class` en `$mkConfig` SIN bindearlo
     * nunca en el ServiceProvider (concrete class auto-resolvible, no
     * necesita bind explícito). Resultado: `bound()` es SIEMPRE false
     * out-of-the-box → `getService()` retorna `null` SIEMPRE → TODOS los
     * hooks (`beforeSearch`, `beforeShow`, `beforeCreate`, `setExtraData`,
     * etc.) quedan muertos silenciosamente, sin error visible.
     *
     * Fix: además de un binding explícito (`bound()`), resolver también
     * clases concretas auto-resolvibles (`class_exists()`) vía
     * `app()->make()` — el container de Laravel ya sabe instanciar
     * concrete classes con dependencias resolvibles sin bind explícito.
     */
    protected function getService(): ?MkModuleServiceInterface
    {
        $serviceClass = $this->mkConfig['service'] ?? null;

        if (! $serviceClass) {
            return null;
        }

        // Si es un string, resolver del container: bindeado explícito O
        // clase concreta auto-resolvible.
        if (is_string($serviceClass) && (app()->bound($serviceClass) || class_exists($serviceClass))) {
            return app()->make($serviceClass);
        }

        if ($serviceClass instanceof MkModuleServiceInterface) {
            return $serviceClass;
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
        // Global toggle acts as a master switch. If disabled globally, cache is off.
        if (! config('mk_director.features.auto_cache', false)) {
            return false;
        }
        $features = $this->getListFeatures();

        return $features['auto_cache'] ?? true;
    }

    /**
     * Obtener tiempo de vida del caché en segundos
     */
    protected function getCacheTTL(): int
    {
        return $this->mkConfig['cache_ttl'] ?? config('mk_director.cache.default_ttl', 3600);
    }

    /**
     * Obtener etiquetas (tags) para el caché, por defecto es el nombre de la tabla.
     *
     * R-PKG-024 P0-FIX-1: when a tenant is active (via TenantContext),
     * the tag array includes `tenant:{id}` so that cache invalidation
     * on write operations is scoped per-tenant instead of global
     * per-table. Without this, Tenant A's write flushes Tenant B's
     * cache — a performance bug in multi-tenant deployments.
     */
    protected function getCacheTags(): array
    {
        if (isset($this->mkConfig['cache_tags'])) {
            $tags = (array) $this->mkConfig['cache_tags'];
        } else {
            $modelClass = $this->getModel();
            $tags = [(new $modelClass)->getTable()];
        }

        // Scope tags to the active tenant when available
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->current();
        if ($tenantId !== null) {
            $tags[] = 'tenant:'.$tenantId;
        }

        return $tags;
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
     * Cached PluginManager instance — avoids redundant setup per hook call.
     */
    private ?PluginManager $pluginManagerInstance = null;

    /**
     * Obtener el PluginManager
     */
    protected function getPluginManager(): PluginManager
    {
        if ($this->pluginManagerInstance !== null) {
            return $this->pluginManagerInstance;
        }

        $manager = app(PluginManager::class);

        // Set the controller context
        $manager->setControllerConfig($this->mkConfig);

        // Register local plugins if configured in the controller
        if (isset($this->mkConfig['plugins']) && is_array($this->mkConfig['plugins'])) {
            $manager->registerPlugins($this->mkConfig['plugins']);
        }

        // Validate Requirements (Only in debug mode)
        $manager->validateRequirements($this->getFillable());

        $this->pluginManagerInstance = $manager;

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
        $cacheKey = md5($query->toSql().serialize($query->getBindings()).'page:'.$page.'cursor:'.$cursor.'perPage:'.$perPage);

        $resolver = function () use ($query, $perPage, $listFeatures) {
            $paginationType = $listFeatures['pagination_type'] ?? config('mk_director.features.pagination_type', 'length_aware');
            if ($paginationType === 'cursor') {
                return $query->cursorPaginate($perPage);
            }

            return $query->paginate($perPage);
        };

        $paginator = $this->isCacheEnabled()
            ? CacheManager::remember($cacheKey, $this->getCacheTags(), $this->getCacheTTL(), $resolver)
            : $resolver();

        // R-PKG-036 HALLAZGO-NEW-FASE15-06 fix (extension): si el modelo
        // extiende `AuthUser` (R-PKG-015 BUG-NEW-06 + R-PKG-022),
        // pinear `loadMissing(['roles', 'directAbilities'])` después del
        // paginator para que `getEffectiveAbilities()` (HALLAZGO-06 fix
        // pineado en v1.8.3-rc0) funcione en el index, NO solo en
        // single resource (show/me). Defense-in-depth: ZERO costo runtime
        // para non-AuthUser models (instanceof check).
        if (
            is_subclass_of($modelClass, AuthUser::class)
            || $modelClass === AuthUser::class
        ) {
            foreach ($paginator->items() as $item) {
                $item->loadMissing(['roles', 'directAbilities']);
            }
        }

        // R-PKG-024 (v1.7.0 GA) — single-level envelope. We pass the
        // paginator directly to sendResponse(); the BaseController
        // auto-extracts items to `data` and pagination metadata to
        // `__extraData` top-level. No flag, no opt-in, no `data.data`.
        //
        // afterList() TRANSFORMS/REPLACES the data rows (default: passthrough);
        // its return REPLACES `data`. setExtraData() runs AFTER afterList and
        // builds the `__extraData` metadata block (default: []). Its keys are
        // merged by BaseController AFTER the auto-extracted pagination metadata,
        // so service keys win on conflict. Cursor pagination cursors are
        // auto-extracted by BaseController::extractPaginationMetadata() for
        // CursorPaginator instances.
        // Hooks resolve to the CONTROLLER ($this) when it overrides them, else
        // the service. This lets a SmartController subclass provide afterList /
        // setExtraData directly (e.g. per-resource metadata) even when several
        // controllers share one service.
        $total = method_exists($paginator, 'total') ? $paginator->total() : null;
        $afterListHook = method_exists($this, 'afterList')
            ? $this
            : (($service && method_exists($service, 'afterList')) ? $service : null);
        if ($afterListHook) {
            $rows = $afterListHook->afterList($request, $paginator->items(), $total);
            if ($rows !== null && method_exists($paginator, 'setCollection')) {
                $paginator->setCollection(collect($rows));
            }
        }

        // setExtraData() only runs when the client asks for it (query param
        // `__extraData`) OR the controller config forces it
        // (`$mkConfig['extraDataForce'] => true`). The front
        // (useMkList/useMkInfiniteList) sends `__extraData=1` only on the first
        // list load and caches the result, so this domain metadata — which
        // rarely changes — is not recomputed on every page/refetch. Pagination
        // metadata is ALWAYS auto-emitted by BaseController.
        $extra = [];
        $extraDataForce = $this->mkConfig['extraDataForce'] ?? false;
        if ($request->boolean('__extraData') || $extraDataForce) {
            $extraDataHook = method_exists($this, 'setExtraData')
                ? $this
                : (($service && method_exists($service, 'setExtraData')) ? $service : null);
            if ($extraDataHook) {
                $extra = $extraDataHook->setExtraData($request, $paginator->items()) ?? [];
            }
        }

        // Plugin Hook: afterResponse (receives the raw paginator so plugins
        // can read total / currentPage / etc. without unwrapping).
        $this->getPluginManager()->fireAfterResponse($paginator);

        return $this->sendResponse($paginator, '', 200, $extra);
    }

    /**
     * GET /resource/{id} - Ver detalle
     *
     * R-PKG-016 BUG-NEW-20 fix: el parámetro `$id` ahora acepta `string|int`
     * porque consumers que usan `HasUuids` (RETO, otros) generan IDs string
     * tipo `01HXYZ...`. La firma previa `int $id` lanzaba TypeError al primer
     * GET /api/{scope}/{uuid} después de migrar a UUIDs.
     *
     * El casteo se hace internamente vía `findOrFail` que acepta ambos tipos.
     */
    public function show(Request $request, string|int $id)
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

        $resolver = function () use ($id, $query) {
            return $query->findOrFail($id);
        };

        $cacheKey = md5($query->toSql().serialize($query->getBindings()).'show_id:'.$id);

        $model = $this->isCacheEnabled()
            ? CacheManager::remember($cacheKey, $this->getCacheTags(), $this->getCacheTTL(), $resolver)
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
     *
     * R-PKG-046 F9-B08: si `$mkConfig['store_request']` está pineado (típicamente
     * por el scaffolder `mk:make:auth-user X --with-crud`), el FormRequest se
     * resuelve, valida y reemplaza `$request` ANTES de `$request->all()`. Esto
     * cierra el bug donde el scaffolder pineaba `store_request` en `$mkConfig`
     * pero `CRUDSmart::store()` hacía `$request->all()` directo sin validar —
     * la validación scaffoldeada era dead code. Post-fix, una validación fallida
     * produce 422 (ValidationException canónica) en lugar de 500 (SQL constraint
     * violation).
     */
    public function store(Request $request)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        // R-PKG-046 F9-B08 — Resolver FormRequest si está configurado.
        $request = $this->resolveFormRequest(
            configKey: 'store_request',
            request: $request,
            routeParamName: null,
            routeParamValue: null,
        );

        // Apply service hook beforeCreate
        $input = $request->all();

        // FEEDBACK (bulk) — if the payload is a list of objects, do a
        // transactional bulk insert instead of a single create. This pairs
        // with the frontend `useMkCrud().createMany(items[])`, which sends
        // ONE POST with an array so the consumer doesn't fire N concurrent
        // requests. All-or-nothing: any failing item rolls back the batch.
        if ($this->isBulkPayload($input)) {
            return $this->storeMany($request, $input);
        }

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
            CacheManager::flush($this->getCacheTags());
        }

        // Auto transform with resource
        $data = $this->autoTransform($model);

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data, 'Creado con éxito', 201);
    }

    /**
     * FEEDBACK (bulk) — ¿el payload es una lista de objetos (bulk create)?
     *
     * `true` solo si es un array list-style (claves 0..n) y TODOS los elementos
     * son arrays. Un objeto single (`{name: ...}`) es un array asociativo →
     * `array_is_list()` es false → NO se trata como bulk (BC intacto).
     */
    protected function isBulkPayload(array $input): bool
    {
        if ($input === [] || ! array_is_list($input)) {
            return false;
        }

        foreach ($input as $item) {
            if (! is_array($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * FEEDBACK (bulk) — inserta N registros en UNA transacción. Cada item pasa
     * por el MISMO pipeline que el store single (plugins beforeSave/afterSave,
     * service beforeCreate/afterCreate, DTO validation, filtro fillable), así
     * que las invariantes por-registro (tenant, enums, hooks) se respetan.
     * Si cualquier item falla, la transacción entera hace rollback (todo-o-nada)
     * y la ValidationException/QueryException se propaga al handler estándar.
     *
     * Devuelve la colección creada con el envelope single-level (data = T[]).
     */
    protected function storeMany(Request $request, array $items)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();
        $fillable = $this->getFillable();

        $created = DB::transaction(function () use ($request, $items, $modelClass, $service, $fillable) {
            $models = [];

            foreach ($items as $raw) {
                $data = $raw;

                $this->getPluginManager()->fireBeforeSave($request, $data, 'create');

                if ($service && method_exists($service, 'beforeCreate')) {
                    $data = $service->beforeCreate($request, $data) ?? $data;
                }

                $data = $this->applyDTOValidation($data);
                $data = array_intersect_key($data, array_flip($fillable));

                $model = $modelClass::create($data);

                if ($service && method_exists($service, 'afterCreate')) {
                    $service->afterCreate($request, $model, $data);
                }

                $this->getPluginManager()->fireAfterSave($model, $request, 'create');

                $models[] = $model;
            }

            return $models;
        });

        // Cache invalidation once for the whole batch.
        if ($this->isCacheEnabled()) {
            CacheManager::flush($this->getCacheTags());
        }

        // Single-level envelope: data = list of transformed items.
        $data = $this->autoTransform(new Collection($created));

        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data, count($created).' creados con éxito', 201);
    }

    /**
     * PUT/PATCH /resource/{id} - Actualizar
     *
     * R-PKG-016 BUG-NEW-20 fix: ver show() — acepta string|int para UUIDs.
     *
     * LAR-01 IDOR fix (2026-07-03 audit): mirror the `show()` pattern —
     * build a query, fire `beforeQuery` plugins (so MkMultiTenantPlugin can
     * inject the tenant filter), THEN `findOrFail`. The previous
     * `$modelClass::findOrFail($id)` static call bypassed every
     * `beforeQuery` hook, letting any authenticated tenant write
     * another tenant's row.
     *
     * R-PKG-046 F9-B08: si `$mkConfig['update_request']` está pineado,
     * el FormRequest se resuelve, valida y reemplaza `$request` ANTES de
     * `$request->all()`. Cierra el mismo bug que `store()` — la validación
     * scaffoldeada era dead code. Para `update`, también se pinea el route
     * resolver con `{resource} = $id` para que `Rule::unique(...)->ignore($this->route('admin'))`
     * funcione correctamente.
     */
    public function update(Request $request, string|int $id)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        // R-PKG-046 F9-B08 — Resolver FormRequest si está configurado.
        // Para update, pineamos route resolver con {resource} = $id para que
        // `Rule::unique('admins', 'email')->ignore($this->route('admin'))`
        // funcione correctamente (ignorar el row actual al validar unique).
        $request = $this->resolveFormRequest(
            configKey: 'update_request',
            request: $request,
            routeParamName: $this->getResourceRouteParam(),
            routeParamValue: (string) $id,
        );

        // Build query + eager loading (mirrors show() so any beforeQuery
        // plugin sees the same builder shape).
        $query = $modelClass::query();
        $query->with($this->getWith());
        $query->withCount($this->getWithCount());

        // Plugin Hook: beforeQuery — MUST run before findOrFail so plugins
        // like MkMultiTenantPlugin can scope the lookup (LAR-01 IDOR fix).
        $this->getPluginManager()->fireBeforeQuery($query, $request);

        $model = $query->findOrFail($id);

        // Get input
        $input = $request->all();

        // Plugin Hook: beforeSave — el modelo persistido va como contexto para
        // que los plugins puedan ver el estado previo (e.g. FileStoragePlugin
        // borrando el archivo que reemplaza). Se limpia en fireAfterSave().
        $this->getPluginManager()->setContextModel($model);
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
            CacheManager::flush($this->getCacheTags());
        }

        // Auto transform with resource
        $data = $this->autoTransform($model);

        // Plugin Hook: afterResponse
        $this->getPluginManager()->fireAfterResponse($data);

        return $this->sendResponse($data, 'Actualizado con éxito');
    }

    /**
     * DELETE /resource/{id} - Eliminar
     *
     * R-PKG-016 BUG-NEW-20 fix: ver show() — acepta string|int para UUIDs.
     *
     * LAR-01 IDOR fix (2026-07-03 audit): mirror the `show()` pattern —
     * build a query, fire `beforeQuery` plugins (so MkMultiTenantPlugin can
     * inject the tenant filter), THEN `findOrFail`. Without this, any
     * authenticated tenant could delete another tenant's row.
     */
    public function destroy(Request $request, string|int $id)
    {
        $modelClass = $this->getModel();
        $service = $this->getService();

        // Build query + eager loading (mirrors show() so any beforeQuery
        // plugin sees the same builder shape).
        $query = $modelClass::query();
        $query->with($this->getWith());
        $query->withCount($this->getWithCount());

        // Plugin Hook: beforeQuery — MUST run before findOrFail so plugins
        // like MkMultiTenantPlugin can scope the lookup (LAR-01 IDOR fix).
        $this->getPluginManager()->fireBeforeQuery($query, $request);

        $model = $query->findOrFail($id);

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
            CacheManager::flush($this->getCacheTags());
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
            throw ValidationException::withMessages([
                'payload' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Auto transformar con resource si está configurado.
     *
     * @deprecated since R-PKG-044 v2.0.0 — use `parent::autoTransform()`
     * (BaseController::autoTransform) which is the canonical SSoT.
     *
     * The canonical pattern (v2.0.0+) is per-model `public $apiResource`
     * declaration (see HALLAZGO-NEW-FASE15-07 + R-PKG-035/036):
     * - BaseController::autoTransform() uses `property_exists($data, 'apiResource')`
     *   + recursive array handling for nested Model values.
     * - Each model owns its own Resource (DRY, scaffolder-driven).
     *
     * This wrapper keeps BC for legacy consumers that still use the
     * `mkConfig['resource']` per-controller pattern. When `$this->getResource()`
     * is set, this legacy path is used. Otherwise we delegate to the
     * parent (BaseController::autoTransform) which checks per-model
     * `$apiResource`.
     *
     ** MIGRATION (RETO 2.0.0+):
     *   - Replace `protected array $mkConfig = ['resource' => FooResource::class]`
     *     on SmartController subclasses with `public $apiResource = FooResource::class`
     *     on the Eloquent model itself (the scaffolder already does this since R-PKG-035).
     *   - Drop any custom `autoTransform()` override in your controllers —
     *     BaseController::autoTransform handles Model / Collection / Paginator
     *     / array-with-nested-Model uniformly.
     */
    protected function autoTransform($data)
    {
        $resourceClass = $this->getResource();

        if ($resourceClass && class_exists($resourceClass)) {
            // Legacy BC path: per-controller `mkConfig['resource']`.
            // Single model
            if ($data instanceof Model) {
                return new $resourceClass($data);
            }

            // Collection or Paginator wrappers
            if ($data instanceof Collection ||
                $data instanceof Paginator ||
                $data instanceof CursorPaginator) {
                return $resourceClass::collection($data);
            }
        }

        // Canonical path (R-PKG-044 v2.0.0): delegate to BaseController::autoTransform
        // which uses per-model `public $apiResource` + recursive array handling
        // (HALLAZGO-NEW-FASE15-07). This is the path RETO and all v2.0.0+ consumers
        // should rely on.
        return parent::autoTransform($data);
    }

    /**
     * R-PKG-046 F9-B08 — Resolver y validar FormRequest si está pineado en `$mkConfig`.
     *
     * Pre-fix, el scaffolder pineaba `'store_request' => StoreXRequest::class` en
     * `$mkConfig` pero `CRUDSmart::store()` hacía `$request->all()` directo sin
     * validar. La validación scaffoldeada era dead code — un POST sin email
     * producía `Integrity constraint violation: NOT NULL constraint failed`
     * (500 SQL) en vez de `ValidationException` (422 con errores estructurados).
     *
     * Post-fix, este helper:
     *   1. Lee `$mkConfig[$configKey]` (e.g. `store_request`, `update_request`).
     *   2. Si no está pineado o está vacío → retorna `$request` sin tocar (BC).
     *   3. Si está pineado:
     *      a. Resuelve via `app($formRequestClass)` (DI completa).
     *      b. `setContainer(app())` + `setRedirector(app('redirect'))` para que
     *         `validateResolved()` route correctamente las failures.
     *      c. Si `$routeParamName !== null` (caso update) → setRouteResolver
     *         con un Route pineado que devuelve el `$id` para `{resource}`.
     *         Esto permite que `Rule::unique('admins', 'email')->ignore($this->route('admin'))`
     *         funcione.
     *      d. `validateResolved()` corre las `rules()` del FormRequest. Si falla,
     *         `ValidationException` se lanza → 422 canónico.
     *      e. Retorna el FormRequest validado (que ahora tiene `$request->validated()`
     *         y `$request->all()` filtrados por las reglas).
     *
     * @param  string  $configKey  'store_request' o 'update_request'
     * @param  Request  $request  El request original
     * @param  string|null  $routeParamName  Nombre del route param (e.g. 'admin', 'member'). Null para store.
     * @param  string|null  $routeParamValue  Valor del route param (el $id). Null para store.
     * @return Request El FormRequest validado (mismo tipo que el original).
     *
     * @throws ValidationException Si las rules() fallan.
     */
    protected function resolveFormRequest(
        string $configKey,
        Request $request,
        ?string $routeParamName,
        ?string $routeParamValue,
    ): Request {
        $formRequestClass = $this->mkConfig[$configKey] ?? null;

        if (! is_string($formRequestClass) || $formRequestClass === '') {
            return $request;
        }

        if (! class_exists($formRequestClass)) {
            return $request;
        }

        /** @var FormRequest $formRequest */
        $formRequest = app($formRequestClass);

        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));

        if ($routeParamName !== null && $routeParamValue !== null) {
            // R-PKG-046 F9-B08 — Route resolver para FormRequest de update.
            //
            // FormRequest usa `$this->route('admin')` para reglas como
            // `Rule::unique('admins', 'email')->ignore($this->route('admin'))`.
            // Pineamos un Route mock que devuelve `$routeParamValue` para
            // `$routeParamName`. Esto es suficiente para que `Rule::ignore`
            // extraiga el ID correcto y excluya el row actual del unique check.
            $formRequest->setRouteResolver(function () use ($routeParamName, $routeParamValue) {
                $route = new Route(
                    ['PUT', 'PATCH'],
                    '/api/{scope}/{resource}/'.$routeParamValue,
                    [],
                );
                $route->setParameter($routeParamName, $routeParamValue);

                return $route;
            });
        }

        // validateResolved() corre las rules() del FormRequest. Si fallan,
        // ValidationException se lanza → manejado por el framework → 422.
        $formRequest->validateResolved();

        // Post-validación, el FormRequest tiene `validated()` + `all()` filtrados.
        // El controller sigue trabajando con `$request->all()` etc. — ahora filtrado.
        return $formRequest;
    }

    /**
     * R-PKG-046 F9-B08 — Helper: nombre del route param para el resource.
     *
     * Convention: el scaffolder pine el route como `/api/{scope}/{resources}/{resource}`
     * (e.g. `/api/admin/admins/{admin}`). El nombre del param es el singular del
     * resource name (e.g. `admin` para `Admin` scope, `member` para `Member` scope).
     *
     * Pineado en `$mkConfig['resource_route_param']` por el scaffolder (auto).
     * Fallback: derivado de `$mkConfig['resource']` o `null` (en cuyo caso
     * el route resolver no se pinea y FormRequest asume que no necesita `$this->route(...)`).
     *
     * Si el consumer pineó `update_request` con reglas que usan
     * `$this->route('admin')`, DEBE pinear `resource_route_param` también.
     */
    protected function getResourceRouteParam(): ?string
    {
        return $this->mkConfig['resource_route_param'] ?? null;
    }
}
