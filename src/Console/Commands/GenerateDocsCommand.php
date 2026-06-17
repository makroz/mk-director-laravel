<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Mk\Director\Services\OpenApiGeneratorService;

class GenerateDocsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'mk:generate-docs {--output= : Ruta para escribir el JSON (Por defecto: public/openapi.json)}';

    /**
     * The console command description.
     */
    protected $description = 'Auto-descubre constructores MK y genera la documentación OpenAPI statica.';

    /**
     * Execute the console command.
     */
    public function handle(OpenApiGeneratorService $generator)
    {
        $this->info("🔍 Iniciando Escaneo Dinámico de SmartControllers e Introspección...");

        $spec = $generator->generate();

        // Guardar Spec al JSON estático
        $outputPath = $this->option('output');
        if (!$outputPath) {
            $outputPath = public_path('openapi.json');
        }

        // Asegurarse de que el directorio exista
        $directory = dirname($outputPath);
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        
        File::put($outputPath, json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->info("✅ Documentación B2B OpenAPI 3.x forjada con éxito en: {$outputPath}");

        return Command::SUCCESS;
    }
}
