<?php

declare(strict_types=1);

namespace Mk\Director\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Mk\Director\Contracts\MkPluginInterface;

/**
 * Class PluginManager
 * 
 * Manages the registration and execution of MK-Director plugins.
 */
class PluginManager
{
    /** @var Collection<int, MkPluginInterface> */
    protected Collection $plugins;

    /** @var array Controller specific configuration */
    protected array $controllerConfig = [];

    public function __construct()
    {
        $this->plugins = collect();
        $this->loadPluginsFromConfig();
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
     */
    public function registerPlugins(array $classes): void
    {
        foreach ($classes as $class) {
            $this->registerPlugin($class);
        }
    }

    /**
     * Register a single plugin class if not already registered.
     */
    public function registerPlugin(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }

        // Check if already registered (by class name)
        $exists = $this->plugins->contains(fn($plugin) => is_a($plugin, $class));

        if (!$exists) {
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
        $this->plugins->each(fn(MkPluginInterface $plugin) => $plugin->beforeQuery($query, $request));
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
     */
    public function fireAfterSave($model, Request $request, string $mode = 'create'): void
    {
        $this->plugins->each(fn(MkPluginInterface $plugin) => $plugin->afterSave($model, $request, $mode));
    }

    /**
     * Trigger beforeDelete hook for all registered plugins.
     */
    public function fireBeforeDelete($model, Request $request): void
    {
        $this->plugins->each(fn(MkPluginInterface $plugin) => $plugin->beforeDelete($model, $request));
    }

    /**
     * Trigger afterDelete hook for all registered plugins.
     */
    public function fireAfterDelete($model, Request $request): void
    {
        $this->plugins->each(fn(MkPluginInterface $plugin) => $plugin->afterDelete($model, $request));
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
                if (!in_array($field, $fillable)) {
                    $findings[] = [
                        'plugin' => get_class($plugin),
                        'type' => 'error',
                        'message' => "Requiere el campo '{$field}' en el modelo, pero no es fillable."
                    ];
                }
            }

            // Check required config keys
            $requiredConfig = $requirements['required_config'] ?? [];
            foreach ($requiredConfig as $key) {
                if (!data_get($mkConfig, $key)) {
                    $findings[] = [
                        'plugin' => get_class($plugin),
                        'type' => 'warning',
                        'message' => "Falta la llave de configuración '{$key}' en \$mkConfig."
                    ];
                }
            }
        }

        return $findings;
    }

    public function validateRequirements(array $fillable): void
    {
        if (!config('mk_director.debug', false)) {
            return;
        }

        $findings = $this->auditRequirements($this->controllerConfig, $fillable);
        foreach ($findings as $finding) {
            $level = $finding['type'] === 'error' ? 'error' : 'warning';
            \Illuminate\Support\Facades\Log::$level("Plugin Diagnosis: [{$finding['plugin']}] {$finding['message']}");
        }
    }
}
