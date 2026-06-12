<?php

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeServiceCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:service {name : El nombre del Service (Ej: SurveyService)} {--module= : El nombre del módulo (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea un Service con MkModuleServiceInterface (MK-Director Standard)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $module = $this->option('module');

        if (!str_ends_with($name, 'Service')) {
            $name .= 'Service';
        }

        $moduleName = $module ? Str::studly($module) : str_replace('Service', '', $name);

        $stubPath = __DIR__ . '/../../Stubs/service.stub';
        if (!File::exists($stubPath)) {
            $this->error("❌ Stub no encontrado: {$stubPath}");
            return Command::FAILURE;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}', $moduleName, $content);
        $content = str_replace('{{moduleNameLower}}', Str::snake($moduleName), $content);
        $content = str_replace('{{moduleNamePluralLower}}', Str::plural(Str::snake($moduleName, '-')), $content);

        $targetFolder = $module ? app_path("Modules/{$moduleName}/Services") : app_path("Services");
        
        // Ensure standard MK-API paths if module is not provided but name implies it
        if (!$module && File::exists(app_path("Modules/{$moduleName}"))) {
             $targetFolder = app_path("Modules/{$moduleName}/Services");
        }

        if (!File::exists($targetFolder)) {
            File::makeDirectory($targetFolder, 0755, true);
        }

        $targetPath = "{$targetFolder}/{$name}.php";

        if (File::exists($targetPath)) {
            $this->error("❌ El Service {$name} ya existe en {$targetFolder}.");
            return Command::FAILURE;
        }

        File::put($targetPath, $content);

        $this->info("✅ Service {$name} creado exitosamente.");
        
        return Command::SUCCESS;
    }
}
