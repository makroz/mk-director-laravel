<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Controllers\SmartController;

class MkCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:status {--module= : Filtrar por un módulo específico}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnosticar el estado y configuración de los controladores MK-Director';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("\n🔍 Analizando ecosistema MK-Director...\n");

        $controllers = $this->findSmartControllers();

        if (empty($controllers)) {
            $this->warn("No se encontraron controladores que usen SmartController.");
            return;
        }

        $headers = ['Controlador', 'Modelo', 'Plugins', 'Estado'];
        $rows = [];

        foreach ($controllers as $controllerClass => $controllerSource) {
            $rows[] = $this->auditController($controllerClass, $controllerSource);
        }

        $this->table($headers, $rows);

        $this->info("\n✅ Análisis completado.\n");
    }

    /**
     * Buscar controladores que extiendan SmartController.
     */
    protected function findSmartControllers(): array
    {
        $path = app_path('Http/Controllers');
        $modulePath = app_path('Modules'); // Soporte para arquitectura modular
        
        $files = [];
        if (File::exists($path)) {
            $files = array_merge($files, File::allFiles($path));
        }
        if (File::exists($modulePath)) {
            $files = array_merge($files, File::allFiles($modulePath));
        }

        $smartControllers = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;

            $content = File::get($file->getRealPath());
            if (str_contains($content, 'extends SmartController')) {
                $namespace = $this->getNamespace($content);
                $className = str_replace('.php', '', $file->getFilename());
                $fullClass = $namespace . '\\' . $className;

                if (class_exists($fullClass)) {
                    $smartControllers[$fullClass] = $content;
                }
            }
        }

        return $smartControllers;
    }

    /**
     * Extraer namespace de un archivo PHP.
     */
    protected function getNamespace($content): ?string
    {
        if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Auditar un controlador específico.
     *
     * @param  string  $controllerClass
     * @param  string|null  $controllerSource  Raw PHP source of the controller
     *                                          (used for source-parsing the
     *                                          auth middleware presence).
     */
    protected function auditController(string $controllerClass, ?string $controllerSource = null): array
    {
        try {
            /** @var SmartController $instance */
            $instance = app($controllerClass);
            $config = $instance->getMkConfig();
            $modelClass = $config['model'] ?? 'N/A';
            $plugins = $config['plugins'] ?? [];

            $findings = [];

            if ($modelClass !== 'N/A' && class_exists($modelClass)) {
                $model = new $modelClass;
                $fillable = $model->getFillable();

                $manager = app(PluginManager::class);
                $manager->setControllerConfig($config);
                $manager->registerPlugins($plugins);

                $findings = array_merge($findings, $this->auditConfig($config));
                $findings = array_merge($findings, $manager->auditRequirements($config, $fillable));
            }

            // T1.2 hardening (1.2.2): SmartController does not enforce auth
            // by itself (BC: pre-existing apps rely on it being a
            // pass-through). Warn the developer if the controller source
            // contains no obvious auth wiring so the issue is at least
            // surfaced — it is still the developer's responsibility to add
            // a middleware (e.g. `MkAuthenticate`, `auth:sanctum`) in
            // their routes file or the controller's constructor.
            if ($controllerSource !== null) {
                $hasAuthSignal = (bool) preg_match(
                    '/(middleware\s*\(|MkAuthenticate|MkAbility|->middleware\(|public\s+function\s+__construct\s*\([^)]*Auth)/i',
                    $controllerSource
                );
                if (! $hasAuthSignal) {
                    $findings[] = [
                        'plugin' => 'Core',
                        'type' => 'warning',
                        'message' => "SmartController no enforce auth: agregar middleware (auth / MkAuthenticate) en rutas o en el constructor.",
                    ];
                }
            }

            $statusText = "<fg=green>OK</>";
            if (!empty($findings)) {
                $statusText = "";
                foreach ($findings as $finding) {
                    $color = $finding['type'] === 'error' ? 'red' : 'yellow';
                    $statusText .= "<fg={$color}>• {$finding['message']}</>\n";
                }
            }

            return [
                class_basename($controllerClass),
                class_basename($modelClass),
                count($plugins),
                trim($statusText)
            ];

        } catch (\Throwable $e) {
            return [
                class_basename($controllerClass),
                'ERROR',
                '0',
                "<fg=red>Error al instanciar: {$e->getMessage()}</>"
            ];
        }
    }

    /**
     * Audit generic $mkConfig settings.
     */
    protected function auditConfig(array $config): array
    {
        $findings = [];

        // 1. Model Validation
        if (!isset($config['model'])) {
            $findings[] = ['plugin' => 'Core', 'type' => 'error', 'message' => "Falta la llave 'model' en \$mkConfig."];
        } else if (!class_exists($config['model'])) {
            $findings[] = ['plugin' => 'Core', 'type' => 'error', 'message' => "El modelo [{$config['model']}] no existe."];
        }

        // 2. Service Validation
        if (isset($config['service']) && !class_exists($config['service'])) {
            $findings[] = ['plugin' => 'Core', 'type' => 'warning', 'message' => "El servicio [{$config['service']}] no existe."];
        }

        // 3. Enum Validation
        if (isset($config['enumMap']) && is_array($config['enumMap'])) {
            foreach ($config['enumMap'] as $field => $enumClass) {
                if (!class_exists($enumClass)) {
                    $findings[] = ['plugin' => 'Core', 'type' => 'error', 'message' => "Enum [{$enumClass}] para el campo [{$field}] no existe."];
                }
            }
        }

        // 4. Searchable Validation
        if (isset($config['searchable']) && is_array($config['searchable'])) {
            $modelClass = $config['model'] ?? null;
            if ($modelClass && class_exists($modelClass)) {
                $model = new $modelClass;
                $table = $model->getTable();
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    foreach ($config['searchable'] as $field) {
                        if (!\Illuminate\Support\Facades\Schema::hasColumn($table, $field)) {
                            $findings[] = ['plugin' => 'Core', 'type' => 'warning', 'message' => "El campo searchable '{$field}' no existe en la tabla '{$table}'."];
                        }
                    }
                }
            }
        }

        return $findings;
    }
}
