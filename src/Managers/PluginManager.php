<?php

declare(strict_types=1);

namespace Mk\Director\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mk\Director\Contracts\MkPluginInterface;
use Mk\Director\Utils\MkDebugConfig;

/**
 * Class PluginManager
 *
 * Manages the registration and execution of MK-Director plugins.
 *
 * **R-PKG-046 F9-B07 fix — Lazy boot (no constructor-time plugin loading)**:
 *
 * Pre-fix, `__construct()` llamó a `loadPluginsFromConfig()` que resuelve
 * `FileStoragePlugin` via container. Como `FileStoragePlugin::__construct(PluginManager)`
 * requiere `PluginManager`, el container entraba en una **dependencia circular**:
 *
 *   MkServiceProvider:50 PluginManager __construct
 *     PluginManager:29 loadPluginsFromConfig
 *       PluginManager:38 registerPlugins
 *         PluginManager:47 registerPlugin
 *           PluginManager:64 app('Mk\\Director\\Plu...')  ← FileStoragePlugin
 *             FileStoragePlugin::__construct(PluginManager)  ← loop
 *
 * Resultado: la app NO booteaba con `MK_FILE_STORAGE_PLUGIN=true` (auto-register
 * pineado en R-PKG-045 D2). Consumer tenía que pinear `MK_FILE_STORAGE_PLUGIN=false`
 * como workaround.
 *
 * Post-fix: el constructor solo pinear estado (collection vacía). El método
 * público `boot()` carga los plugins. `MkServiceProvider::boot()` llama
 * `$pluginManager->boot()` después de que el singleton YA está construido.
 *
 *   1. `MkServiceProvider::register()` registra singleton (no ejecuta).
 *   2. `MkServiceProvider::boot()` → `$this->app->make(PluginManager::class)`
 *      ejecuta el closure `new PluginManager` → solo pinear `plugins = collect()`.
 *   3. `$pluginManager->boot()` carga config y llama `app(FileStoragePlugin::class)`.
 *   4. Container resuelve FileStoragePlugin buscando PluginManager → encuentra
 *      el singleton YA CONSTRUIDO. Inyecta OK. No hay loop.
 */
class PluginManager
{
    /** @var Collection<int, MkPluginInterface> */
    protected Collection $plugins;

    /** @var array Controller specific configuration */
    protected array $controllerConfig = [];

    /** @var bool Lazy boot guard — evita doble-load si boot() se llama más de una vez. */
    protected bool $booted = false;

    /**
     * Modelo persistido que está por mutarse (solo `update`).
     *
     * `beforeSave()` recibe el payload entrante pero NO el modelo, así que un
     * plugin no puede ver el estado previo — y sin estado previo no puede, por
     * ejemplo, borrar el archivo que está reemplazando. Extender la firma de
     * `MkPluginInterface::beforeSave()` sería BC-breaking para todo plugin
     * custom del consumer, así que el contexto viaja por el manager y los
     * plugins lo leen solo si les interesa.
     *
     * Lo pinea el caller (`CRUDSmart::update()` y los auth controllers
     * scaffoldeados) ANTES de `fireBeforeSave()`, y se limpia en
     * `fireAfterSave()`.
     */
    protected mixed $contextModel = null;

    public function __construct()
    {
        $this->plugins = collect();
        // R-PKG-046 F9-B07: NO cargar plugins aquí. Ver docblock de la clase.
    }

    /**
     * R-PKG-046 F9-B07 — Lazy boot: carga los plugins pineados en config.
     *
     * Idempotente: si ya se llamó una vez, retorna sin recargar.
     *
     * Llamado por `MkServiceProvider::boot()` después de que el singleton
     * YA está construido. Tests que instancian `new PluginManager()`
     * directamente deben llamar `$manager->boot()` después.
     *
     * @see PluginManager class docblock para la secuencia completa.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->loadPluginsFromConfig();
        $this->booted = true;
    }

    /**
     * Load plugins listed in mk_director.php config.
     */
    protected function loadPluginsFromConfig(): void
    {
        $pluginClasses = config('mk_director.plugins', []);
        $this->registerPlugins($pluginClasses);
    }

    /**
     * Register an array of plugin classes.
     *
     * F10-B16 (R-PKG-050): skip defensivo de valores que NO son string.
     * Pre-fix, si un caller (e.g. `CRUDSmart::resolveFormRequest()` →
     * `$manager->registerPlugins($this->mkConfig['plugins'])`) pasaba un
     * array asociativo con config per-plugin (e.g.
     * `['file_storage' => ['fields' => ['avatar' => 'avatar_path']]]`),
     * el foreach llamaba `registerPlugin(['file_storage' => [...]])` con
     * un array como argumento → `TypeError: registerPlugin(): Argument
     * #1 ($class) must be of type string, array given`.
     *
     * Post-fix: skip non-string values con `continue`. Los configs per-plugin
     * (que son arrays) NO deberían pinearse via este método — se acceden
     * via `getConfigValue('plugins_config.<name>', [])` (per
     * FileStoragePlugin). Pero defense-in-depth por si el controller
     * scaffoldeado pinea la forma incorrecta.
     */
    public function registerPlugins(array $classes): void
    {
        foreach ($classes as $class) {
            if (! is_string($class)) {
                continue;  // skip arrays (configs) u otros non-class values
            }
            $this->registerPlugin($class);
        }
    }

    /**
     * Register a single plugin class if not already registered.
     */
    public function registerPlugin(string $class): void
    {
        if (! class_exists($class)) {
            return;
        }

        // Check if already registered (by class name)
        $exists = $this->plugins->contains(fn ($plugin) => is_a($plugin, $class));

        if (! $exists) {
            $plugin = app($class);
            if ($plugin instanceof MkPluginInterface) {
                $plugin->boot();
                $this->plugins->push($plugin);
            }
        }
    }

    /**
     * Trigger beforeQuery hook for all registered plugins.
     */
    public function fireBeforeQuery(Builder $query, Request $request): void
    {
        $this->plugins->each(fn (MkPluginInterface $plugin) => $plugin->beforeQuery($query, $request));
    }

    /**
     * Pinea el modelo persistido que está por mutarse, para que los plugins
     * puedan consultar el estado previo durante `beforeSave()`.
     *
     * @see PluginManager::$contextModel
     */
    public function setContextModel(mixed $model): void
    {
        $this->contextModel = $model;
    }

    /**
     * Modelo previo a la mutación, o `null` en `create` (o si el caller no lo
     * pineó). Los plugins DEBEN tolerar el `null`.
     */
    public function getContextModel(): mixed
    {
        return $this->contextModel;
    }

    /**
     * Trigger beforeSave hook for all registered plugins.
     */
    public function fireBeforeSave(Request $request, array &$data, string $mode = 'create'): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->beforeSave($request, $data, $mode);
        }
    }

    /**
     * Trigger afterSave hook for all registered plugins.
     *
     * Limpia el context model al terminar: el manager es singleton, así que
     * dejarlo pineado filtraría el modelo de una request a la siguiente.
     */
    public function fireAfterSave($model, Request $request, string $mode = 'create'): void
    {
        $this->plugins->each(fn (MkPluginInterface $plugin) => $plugin->afterSave($model, $request, $mode));

        $this->contextModel = null;
    }

    /**
     * Trigger beforeDelete hook for all registered plugins.
     */
    public function fireBeforeDelete($model, Request $request): void
    {
        $this->plugins->each(fn (MkPluginInterface $plugin) => $plugin->beforeDelete($model, $request));
    }

    /**
     * Trigger afterDelete hook for all registered plugins.
     */
    public function fireAfterDelete($model, Request $request): void
    {
        $this->plugins->each(fn (MkPluginInterface $plugin) => $plugin->afterDelete($model, $request));
    }

    /**
     * Trigger afterResponse hook for all registered plugins.
     */
    public function fireAfterResponse(&$responseData): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->afterResponse($responseData);
        }
    }

    /**
     * Set the controller-specific MK-Director config.
     */
    public function setControllerConfig(array $config): void
    {
        $this->controllerConfig = $config;
    }

    /**
     * Get the controller-specific MK-Director config.
     */
    public function getControllerConfig(): array
    {
        return $this->controllerConfig;
    }

    /**
     * Get a specific value from the controller config.
     */
    public function getConfigValue(string $key, $default = null)
    {
        return data_get($this->controllerConfig, $key, $default);
    }

    public function auditRequirements(array $mkConfig, array $fillable): array
    {
        $findings = [];

        foreach ($this->plugins as $plugin) {
            $requirements = $plugin->getRequirements();

            // Check required fields
            $addedFields = $requirements['fields_added'] ?? [];
            foreach ($addedFields as $field) {
                if (! in_array($field, $fillable)) {
                    $findings[] = [
                        'plugin' => get_class($plugin),
                        'type' => 'error',
                        'message' => "Requiere el campo '{$field}' en el modelo, pero no es fillable.",
                    ];
                }
            }

            // Check required config keys.
            //
            // R-PKG-045 D3: distinguir "key missing" (error) vs "key empty"
            // (info). Pre-fix, !data_get(...) trataba `[]` (array vacío pineado
            // a propósito, e.g. `plugins_config.file_storage.fields: []`) como
            // "missing" → warning falso en `mk:status`.
            //
            // Post-fix:
            //   - !Arr::has($mkConfig, $key) → 'error' (key realmente no existe).
            //   - empty(data_get($mkConfig, $key)) → 'info' (key existe pero
            //     está vacía — decisión intencional del consumer, no warning).
            //   - otherwise → no finding.
            $requiredConfig = $requirements['required_config'] ?? [];
            foreach ($requiredConfig as $key) {
                if (! Arr::has($mkConfig, $key)) {
                    $findings[] = [
                        'plugin' => get_class($plugin),
                        'type' => 'error',
                        'message' => "Falta la llave de configuración '{$key}' en \$mkConfig.",
                    ];
                } elseif (empty(data_get($mkConfig, $key))) {
                    $findings[] = [
                        'plugin' => get_class($plugin),
                        'type' => 'info',
                        'message' => "La llave '{$key}' existe pero está vacía — el plugin está registrado sin fields configurados.",
                    ];
                }
            }
        }

        return $findings;
    }

    public function validateRequirements(array $fillable): void
    {
        if (! MkDebugConfig::enabled()) {
            return;
        }

        $findings = $this->auditRequirements($this->controllerConfig, $fillable);
        foreach ($findings as $finding) {
            // R-PKG-045 D3: mapear cada tipo a su nivel de log correspondiente.
            // 'info' (e.g. fields: [] pineado a propósito) NO debe loguear como
            // 'warning' — sería ruido. 'error' y 'warning' mantienen comportamiento
            // pre-R-PKG-045.
            $level = match ($finding['type']) {
                'error' => 'error',
                'info' => 'info',
                default => 'warning',
            };
            Log::$level("Plugin Diagnosis: [{$finding['plugin']}] {$finding['message']}");
        }
    }
}
