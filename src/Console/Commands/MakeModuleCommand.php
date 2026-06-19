<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:module {name : El nombre del módulo en singular (Ej: Survey)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold completo de un módulo MK-API Standard (Controller, Model, Service, Repository, DTO, Contracts, etc.)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name       = $this->argument('name');
        $moduleName = Str::studly($name);

        $this->info("🚀 Iniciando generación del módulo MK-API: {$moduleName}");

        $basePath = app_path("Modules/{$moduleName}");

        if (File::exists($basePath)) {
            $this->error("El módulo {$moduleName} ya existe en {$basePath}.");
            return Command::FAILURE;
        }

        // ─── Crear estructura de directorios (MK-API Standard R-A-001) ──────────
        $directories = [
            'Controllers',
            'Contracts',            // Interface del Repository — obligatoria (R-A-007)
            'DTOs',
            'Enums',
            'Models',
            'Repositories',        // Solo queries DB — sin lógica, sin caché (R-A-003)
            'Requests',
            'Resources',
            'Routes',
            'Services',            // Business logic + Cache (R-A-004)
            'Database/Migrations',
        ];

        foreach ($directories as $dir) {
            File::makeDirectory("{$basePath}/{$dir}", 0755, true);
            $this->line("  📁 {$dir}/");
        }

        $this->newLine();
        $this->info('📄 Generando archivos...');

        // ─── Generar archivos desde stubs ────────────────────────────────────────

        // Capa HTTP
        $this->generateFile($moduleName, 'controller.stub',           'Controllers',  "{$moduleName}Controller.php");
        $this->generateFile($moduleName, 'route.stub',                'Routes',       'api.php');

        // Capa de Dominio
        $this->generateFile($moduleName, 'model.stub',                'Models',       "{$moduleName}.php");
        $this->generateFile($moduleName, 'enum.stub',                 'Enums',        "{$moduleName}Status.php");

        // Capa de Datos / Contratos (MK-API Standard)
        $this->generateFile($moduleName, 'dto.stub',                  'DTOs',         "{$moduleName}Data.php");
        $this->generateFile($moduleName, 'repository-interface.stub', 'Contracts',    "{$moduleName}RepositoryInterface.php");
        $this->generateFile($moduleName, 'repository.stub',           'Repositories', "{$moduleName}Repository.php");
        $this->generateFile($moduleName, 'service.stub',              'Services',     "{$moduleName}Service.php");

        // ServiceProvider con binding Interface → Implementation
        $this->generateFile($moduleName, 'provider.stub',             '',             "{$moduleName}ModuleServiceProvider.php");

        // ─── Auto-registrar el ServiceProvider ──────────────────────────────────
        $this->registerServiceProvider($moduleName);

        $this->newLine();
        $this->info("✅ Módulo {$moduleName} generado con el estándar MK-API:");
        $this->line("   Flujo: FormRequest → Controller → DTO → Service → Repository → Model → Resource");
        $this->newLine();
        $this->warn("   ⚠️  Recuerda completar los TODOs en:");
        $this->line("      - DTOs/{$moduleName}Data.php (propiedades tipadas)");
        $this->line("      - Services/{$moduleName}Service.php (getSearchable, getWith)");
        $this->line("      - Crear la migración en Database/Migrations/");

        return Command::SUCCESS;
    }

    protected function registerServiceProvider(string $moduleName): void
    {
        $providerClass = "App\\Modules\\{$moduleName}\\{$moduleName}ModuleServiceProvider::class";

        // Laravel 11+ checking
        $bootstrapPath = base_path('bootstrap/providers.php');
        if (File::exists($bootstrapPath)) {
            $content = File::get($bootstrapPath);
            if (!str_contains($content, $providerClass)) {
                if (preg_match('/return\s*\[\s*(.*?)\s*\];/s', $content, $matches)) {
                    $insertTarget = "];";
                    $providerLine = "    {$providerClass},";
                    $newContent   = str_replace($insertTarget, "{$providerLine}\n];", $content);
                    File::put($bootstrapPath, $newContent);
                    $this->info(" - Auto-registrado en bootstrap/providers.php");
                }
            }
            return;
        }

        // Laravel 10 and below checking
        $configPath = config_path('app.php');
        if (File::exists($configPath)) {
            $content = File::get($configPath);
            if (!str_contains($content, $providerClass)) {
                $search = "/*\n         * Application Service Providers...\n         */";
                if (str_contains($content, $search)) {
                    $replace    = "/*\n         * Application Service Providers...\n         */\n        {$providerClass},";
                    $newContent = str_replace($search, $replace, $content);
                    File::put($configPath, $newContent);
                    $this->info(" - Auto-registrado en config/app.php");
                } else {
                    $this->warn("No se pudo auto-registrar. Agrega manualmente: {$providerClass}");
                }
            }
            return;
        }
    }

    protected function generateFile(string $moduleName, string $stubName, string $folder, string $fileName): void
    {
        $stubPath = __DIR__ . '/../../Stubs/' . $stubName;

        if (!File::exists($stubPath)) {
            $this->error("  ❌ Stub no encontrado: {$stubPath}");
            return;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}',            $moduleName,                               $content);
        $content = str_replace('{{moduleNameLower}}',       Str::snake($moduleName),                   $content);
        $content = str_replace('{{moduleNamePluralLower}}', Str::plural(Str::snake($moduleName, '-')), $content);

        $targetFolder = app_path("Modules/{$moduleName}");
        if (!empty($folder)) {
            $targetFolder .= "/{$folder}";
        }

        $targetPath  = "{$targetFolder}/{$fileName}";
        File::put($targetPath, $content);

        $displayName = !empty($folder) ? "{$folder}/{$fileName}" : $fileName;
        $this->line("   ✅ {$displayName}");
    }
}
