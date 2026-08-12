<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Models\MkReport;

/**
 * Se lleva los reportes vencidos, con su archivo.
 *
 * 🔴 **Borra la fila Y el archivo, en ese orden y con el archivo primero.** Un
 * limpiador que sólo borre filas deja el disco creciendo para siempre con
 * archivos que ya nadie puede pedir — no hay fila que los nombre. Es una fuga
 * que no da síntomas hasta que el disco se llena.
 *
 * ⚠️ El criterio es UNO: `expires_at`. En el motor original convivía con un
 * barrido por antigüedad, y como los dos se aplicaban ganaba el más corto:
 * subir la retención en un lugar no cambiaba nada visible.
 */
final class CleanReportsCommand extends Command
{
    protected $signature = 'mk:reports-clean {--dry-run : Muestra qué borraría, sin borrar}';

    protected $description = 'Borra los reportes vencidos y sus archivos.';

    public function handle(): int
    {
        $seco = (bool) $this->option('dry-run');
        $disk = Storage::disk((string) config('mk_director.export.disk', 'local'));

        $vencidos = MkReport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());

        $filas = 0;
        $archivos = 0;

        foreach ($vencidos->cursor() as $reporte) {
            if ($reporte->file_path && $disk->exists($reporte->file_path)) {
                if (! $seco) {
                    $disk->delete($reporte->file_path);
                }

                $archivos++;
            }

            if (! $seco) {
                $reporte->delete();
            }

            $filas++;
        }

        $this->info($seco
            ? "Se borrarían {$filas} reportes y {$archivos} archivos."
            : "Borrados {$filas} reportes y {$archivos} archivos.");

        return self::SUCCESS;
    }
}
