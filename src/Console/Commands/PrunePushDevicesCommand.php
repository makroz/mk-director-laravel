<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Mk\Director\Push\Models\MkPushDevice;

/**
 * Borra los teléfonos que no se vieron en N días
 * (`mk_director.push.prune_after_days`, default 60; 0 o null la apaga).
 *
 * 🔴 Por qué existe: los servicios no avisan siempre de una dirección muerta.
 * FCM sí (404 UNREGISTERED, el job la poda), pero OneSignal NO devuelve las
 * inválidas cuando el request mezcla válidas e inválidas, y un teléfono cuya
 * app nunca se volvió a abrir tampoco da error. Sin esto, esas filas se quedan
 * para siempre y cada envío las sigue mandando.
 *
 * «Visto» es `last_seen_at`, que el registro refresca en cada POST; la app lo
 * hace al arrancar con sesión. Una fila sin `last_seen_at` se juzga por
 * `created_at`.
 *
 * Borra de a tandas (`mk_director.push.chunk`) y el `DELETE` repite el corte:
 * un teléfono que se re-registra entre la lectura y el borrado no se pierde.
 */
final class PrunePushDevicesCommand extends Command
{
    protected $signature = 'mk:push:prune
        {--days= : Días sin verse; pisa mk_director.push.prune_after_days}
        {--dry-run : Cuenta cuántos borraría, sin borrar}';

    protected $description = 'Borra los teléfonos de push que no se vieron en N días.';

    public function handle(): int
    {
        $days = $this->option('days') ?? config('mk_director.push.prune_after_days', 60);

        if ($days !== null && $days !== '' && ! ctype_digit((string) $days)) {
            $this->error('--days tiene que ser un número entero de días, 0 o más.');

            return self::FAILURE;
        }

        // 🔴 Por el entero, no por el texto: '00' compara distinto de '0' y
        // con 0 días el corte es «ahora» — borraría todos los teléfonos.
        $days = (int) $days;

        if ($days === 0) {
            $this->info('La poda de teléfonos está apagada (prune_after_days = 0).');

            return self::SUCCESS;
        }

        $stale = $this->staleQuery(now()->subDays($days));

        if ($this->option('dry-run')) {
            $this->info("Se borrarían {$stale->count()} teléfonos sin verse en {$days} días.");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) config('mk_director.push.chunk', 500));
        $deleted = 0;

        while (($ids = (clone $stale)->limit($chunk)->pluck('id'))->isNotEmpty()) {
            $deleted += (clone $stale)->whereIn('id', $ids)->delete();
        }

        $this->info("Borrados {$deleted} teléfonos sin verse en {$days} días.");

        return self::SUCCESS;
    }

    /** @return Builder<MkPushDevice> */
    private function staleQuery(CarbonInterface $cutoff): Builder
    {
        return MkPushDevice::query()->where(fn (Builder $q) => $q
            ->where('last_seen_at', '<', $cutoff)
            ->orWhere(fn (Builder $q) => $q->whereNull('last_seen_at')->where('created_at', '<', $cutoff)));
    }
}
