<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Mk\Director\Help\MkHelpSync;

/**
 * Indexa los manuales (`mk_director.help.content_path`) en `mk_help_articles`.
 * Todo o nada: con un archivo inválido, un `related` o una pantalla que no
 * existen no escribe nada y sale con 1. Sube las imágenes al rol público.
 *
 * Corrélo en cada deploy, después de `migrate`.
 */
final class HelpSyncCommand extends Command
{
    protected $signature = 'mk:help:sync
        {--dry-run : Cuenta qué haría, sin tocar la base}';

    protected $description = 'Indexa los manuales en Markdown en la tabla de ayuda.';

    public function handle(MkHelpSync $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $sync->run($dryRun);

        if ($report->failed()) {
            $this->error('El manual tiene errores; no se escribió nada:');
            foreach ($report->errors as $error) {
                $this->line("  - {$error}");
            }
            foreach ($report->unknownScreens as $screen) {
                $this->line("  - pantalla que no está en screens.yaml: {$screen}");
            }

            return self::FAILURE;
        }

        $this->table(['Creados', 'Actualizados', 'Borrados', 'Sin cambios', 'Imágenes subidas'], [[
            $report->created, $report->updated, $report->deleted, $report->unchanged, $report->imagesUploaded,
        ]]);

        if ($report->uncoveredScreens !== []) {
            $this->warn('Pantallas sin artículo ('.count($report->uncoveredScreens).'):');
            foreach ($report->uncoveredScreens as $screen) {
                $this->line("  - {$screen}");
            }
        }

        if ($dryRun) {
            $this->info('--dry-run: no se escribió nada.');
        }

        return self::SUCCESS;
    }
}
