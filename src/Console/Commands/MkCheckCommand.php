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
    protected $signature = 'mk:status
        {--module= : Filtrar por un módulo específico}
        {--response-shape : R-PKG-024 (v1.7.0): audit controllers for legacy `data.data` nesting or nested `__extraData` in sendResponse() calls}';

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
        // R-PKG-024 (v1.7.0 GA): --response-shape option dispatches to a
        // dedicated audit that walks every controller and ERRORS about
        // legacy `sendResponse(['data' => $paginator, '__extraData' => ...])`
        // calls — endpoints that emit `data.data` nesting in the JSON response.
        if ($this->option('response-shape')) {
            return $this->auditResponseShape();
        }

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
     * R-PKG-024 (v1.7.0 GA) — audit response envelope shape.
     *
     * Walks every controller in app/Http/Controllers and app/Modules/* and
     * reports ANY of these legacy patterns:
     *
     *   1. `sendResponse([ 'data' => ..., '__extraData' => ... ])` — legacy
     *      nested shape (rc11 and earlier; legacy rc12 with flag off).
     *   2. `sendResponse([ 'data' => $paginator_or_paginate_call ])` — legacy
     *      Laravel paginator nested inside the envelope `data` key, which
     *      produces `data: { data: [...], links, meta }` (the `data.data`
     *      shape that R-PKG-024 prohibits).
     *   3. Any hand-crafted `sendResponse([...])` where the array contains
     *      a `data` key whose value is itself an array (heuristic: matches
     *      `$paginate*`, `$paginator*`, `$cursor*`, or `->paginate(`/`->cursorPaginate(`).
     *
     * All findings are reported as `error` (not `warning`) post-GA — the
     * single-level envelope is non-negotiable per R-PKG-024.
     */
    protected function auditResponseShape(): int
    {
        $this->info("\n🔍 Auditando response shape (single-level envelope) — R-PKG-024 (v1.7.0 GA)...\n");

        $paths = [
            app_path('Http/Controllers'),
            app_path('Modules'),
        ];

        $findings = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $finder = new \Symfony\Component\Finder\Finder();
            $finder->files()->in($path)->name('*.php');

            foreach ($finder as $file) {
                $contents = (string) file_get_contents($file->getRealPath());
                $relative = ltrim(str_replace(base_path() . '/', '', $file->getRealPath()), '/');

                // Pattern 1: legacy nested __extraData inside sendResponse([ ... ])
                if (preg_match_all(
                    '/sendResponse\s*\(\s*\[\s*[\s\S]{0,500}?[\'"]__extraData[\'"]/',
                    $contents,
                    $matches,
                    PREG_OFFSET_CAPTURE
                )) {
                    foreach ($matches[0] as $match) {
                        $offset = $match[1];
                        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                        $findings[] = [
                            'file'    => $relative,
                            'line'    => $line,
                            'snippet' => $match[0],
                            'issue'   => 'legacy nested __extraData',
                            'migration' => 'migrate to: sendResponse($data, \'\', 200, $extra)',
                        ];
                    }
                }

                // Pattern 2: `data` key in sendResponse([ ... ]) wrapping a
                // paginator / paginate() call. Produces `data: { data: [...], links, meta }`.
                // Heuristic: the `data` value (within 500 chars after the `[`)
                // contains `$paginat*`, `$cursor*`, or `->paginate(` / `->cursorPaginate(`.
                if (preg_match_all(
                    '/sendResponse\s*\(\s*\[[\s\S]{0,500}?[\'"]data[\'"]\s*=>\s*(\$[a-zA-Z_]*pag[a-zA-Z_]*|\$[a-zA-Z_]*cursor[a-zA-Z_]*|[^,]+->paginate\s*\([^)]*\)|[^,]+->cursorPaginate\s*\([^)]*\))/',
                    $contents,
                    $matches,
                    PREG_OFFSET_CAPTURE
                )) {
                    foreach ($matches[0] as $match) {
                        $offset = $match[1];
                        $line = substr_count(substr($contents, 0, $offset), "\n") + 1;
                        $findings[] = [
                            'file'    => $relative,
                            'line'    => $line,
                            'snippet' => $match[0],
                            'issue'   => 'data.data nesting (paginator wrapped in `data` key)',
                            'migration' => 'migrate to: sendResponse($paginator, \'\', 200, $extra) — BaseController auto-flattens items to `data` and pagination meta to `__extraData` top-level',
                        ];
                    }
                }
            }
        }

        if (empty($findings)) {
            $this->info('✅ All controllers use the single-level envelope (no `data.data`, no nested `__extraData`).');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                $f['file'],
                $f['line'],
                "<fg=red>{$f['issue']}</>",
                $f['migration'],
            ];
        }

        $this->table(['File', 'Line', 'Issue', 'Migration hint'], $rows);
        $this->error(sprintf(
            "\n❌ %d response envelope violation(s) found (R-PKG-024).\n" .
            "   The single-level envelope is NON-NEGOTIABLE post-GA (v1.7.0).\n" .
            "   Each violation produces `data.data` nesting in the JSON response — see CHANGELOG.md `## [v1.7.0]` for the migration guide.\n",
            count($findings)
        ));

        return self::FAILURE;
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
