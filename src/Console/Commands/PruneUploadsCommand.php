<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Models\MkUpload;
use Mk\Director\Storage\DirectUpload\MkDirectUpload;
use Throwable;

/**
 * Limpia las subidas directas (`mk_uploads`) que nadie va a terminar ni usar:
 *
 *   - Started vencidas (`expires_at` pasado): se aborta el multipart.
 *   - Completing trabadas: un `complete()` cuyo proceso murió a mitad de camino
 *     (ver {@see MkDirectUpload::complete()}). Vencidas Y sin tocar hace una
 *     hora: un `complete()` vivo de un video de GBs puede estar uniendo partes
 *     justo cuando vence. Se aborta el multipart y se borra el objeto.
 *   - Completed que nadie adjuntó en `prune_unattached_after_hours` horas
 *     (default 24, o `--hours`): el formulario nunca se guardó. Se borra el objeto.
 *
 * Todas quedan Aborted. Las Attached ya son media y NO se tocan nunca.
 *
 * 🔴 PRIMERO LA FILA, DESPUÉS EL STORAGE. Cada fila se toma con un UPDATE
 * condicional (`WHERE status = <el que se leyó>` → Aborted) antes de tocar el
 * storage: si un `attachUpload()` o un `complete()` la tomó entre la lectura y
 * la poda, el UPDATE no cambia nada y la fila se saltea. Sin eso, la poda
 * borraría el objeto de una media recién creada. Si el storage falla, la fila
 * vuelve a su estado y la próxima corrida la reintenta.
 *
 * Programalo una vez por día, en un solo servidor:
 * `Schedule::command('mk:uploads:prune')->daily()->withoutOverlapping()->onOneServer()`.
 */
final class PruneUploadsCommand extends Command
{
    private const CHUNK = 500;

    /** Lo que se le da a un `complete()` en curso antes de juzgarlo muerto. */
    private const COMPLETING_GRACE_MINUTES = 60;

    protected $signature = 'mk:uploads:prune
        {--hours= : Horas que una subida terminada espera a que la adjunten; pisa mk_director.storage.prune_unattached_after_hours}
        {--dry-run : Cuenta qué haría, sin tocar el storage ni la base}';

    protected $description = 'Aborta las subidas directas vencidas y borra las terminadas que nadie adjuntó.';

    private int $failed = 0;

    public function handle(): int
    {
        $hours = $this->option('hours') ?? config('mk_director.storage.prune_unattached_after_hours', 24);

        // 🔴 Al menos una hora: con 0, una subida terminada hace un segundo
        // —el formulario todavía no se guardó— perdería su archivo.
        if (! ctype_digit((string) $hours) || (int) $hours < 1) {
            $this->error('--hours tiene que ser un número entero de horas, 1 o más.');

            return self::FAILURE;
        }

        $hours = (int) $hours;
        $now = now();

        $this->prune(
            MkUploadStatus::Started,
            fn (Builder $q) => $q->where('expires_at', '<', $now),
            'subidas vencidas sin terminar',
        );
        $this->prune(
            MkUploadStatus::Completing,
            fn (Builder $q) => $q->where('expires_at', '<', $now)
                ->where('updated_at', '<', $now->copy()->subMinutes(self::COMPLETING_GRACE_MINUTES)),
            'subidas trabadas verificando',
        );
        $this->prune(
            MkUploadStatus::Completed,
            fn (Builder $q) => $q->where('updated_at', '<', $now->copy()->subHours($hours)),
            "subidas sin adjuntar en {$hours} h",
        );

        if ($this->failed > 0) {
            $this->error("{$this->failed} no se pudieron limpiar: quedan como estaban y se reintentan en la próxima corrida.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param  Closure(Builder<MkUpload>): Builder<MkUpload>  $where */
    private function prune(MkUploadStatus $status, Closure $where, string $label): void
    {
        $query = $where(MkUpload::query()->where('status', $status->value));

        if ($this->option('dry-run')) {
            $this->info("Se limpiarían {$query->count()} {$label}.");

            return;
        }

        $uploads = app(MkDirectUpload::class);
        $done = 0;

        $query->chunkById(self::CHUNK, function (Collection $chunk) use ($uploads, $status, &$done): void {
            foreach ($chunk as $upload) {
                $claimed = MkUpload::query()->whereKey($upload->id)
                    ->where('status', $status->value)
                    ->update(['status' => MkUploadStatus::Aborted->value]);

                if ($claimed !== 1) {
                    continue;
                }

                try {
                    $uploads->discard($upload);
                    $done++;
                } catch (Throwable $e) {
                    MkUpload::query()->whereKey($upload->id)
                        ->where('status', MkUploadStatus::Aborted->value)
                        ->update(['status' => $status->value]);
                    $this->failed++;
                    $this->warn("No se pudo limpiar la subida {$upload->id} ({$upload->disk}:{$upload->key}): {$e->getMessage()}");
                }
            }
        });

        $this->info("Limpiadas {$done} {$label}.");
    }
}
