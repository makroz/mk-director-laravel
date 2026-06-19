<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeDTOCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:dto {name : El nombre del DTO (Ej: SurveyData)} {--module= : El nombre del módulo (opcional)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea un DTO extendiendo MkDTO (MK-Director Standard)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name = $this->argument('name');
        $module = $this->option('module');

        if (!str_ends_with($name, 'Data') && !str_ends_with($name, 'DTO')) {
            $name .= 'Data';
        }

        $moduleName = $module ? Str::studly($module) : str_replace(['Data', 'DTO'], '', $name);

        $stubPath = __DIR__ . '/../../Stubs/dto.stub';
        if (!File::exists($stubPath)) {
            $this->error("❌ Stub no encontrado: {$stubPath}");
            return Command::FAILURE;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}', $moduleName, $content);
        $content = str_replace('{{moduleNameLower}}', Str::snake($moduleName), $content);
        $content = str_replace('{{moduleNamePluralLower}}', Str::plural(Str::snake($moduleName, '-')), $content);

        $targetFolder = $module ? app_path("Modules/{$moduleName}/DTOs") : app_path("DTOs");
        
        // Ensure standard MK-API paths if module is not provided but name implies it
        if (!$module && File::exists(app_path("Modules/{$moduleName}"))) {
             $targetFolder = app_path("Modules/{$moduleName}/DTOs");
        }

        if (!File::exists($targetFolder)) {
            File::makeDirectory($targetFolder, 0755, true);
        }

        $targetPath = "{$targetFolder}/{$name}.php";

        if (File::exists($targetPath)) {
            $this->error("❌ El DTO {$name} ya existe en {$targetFolder}.");
            return Command::FAILURE;
        }

        File::put($targetPath, $content);

        $this->info("✅ DTO {$name} creado exitosamente.");
        
        return Command::SUCCESS;
    }
}
