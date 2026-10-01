<?php

declare(strict_types=1);

namespace Mk\Director\Push\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\PushMessage;

/**
 * Resuelve las direcciones de los dueños y se las entrega al proveedor activo.
 *
 * Las direcciones se leen ACÁ y no al llamar `send()`: el job puede correr más
 * tarde, y para entonces el teléfono pudo haber pasado a otra persona.
 */
final class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** @param list<array{0: string, 1: string}> $owners [morph class, key] */
    public function __construct(public PushMessage $message, public array $owners) {}

    public function handle(PushProvider $provider): void
    {
        // 🔴 Sin dueños no hay `where`, y la consulta de abajo devolvería
        // TODOS los teléfonos del sistema.
        if ($this->owners === []) {
            return;
        }

        $query = MkPushDevice::query()->where(function (Builder $q): void {
            foreach ($this->owners as [$type, $id]) {
                $q->orWhere(fn (Builder $o) => $o->where('owner_type', $type)->where('owner_id', $id));
            }
        });

        // Un servicio sólo puede usar las direcciones que emitió él. `log` y
        // `null` no son un servicio: no emiten direcciones, así que con ellos
        // se toman todas las del dueño (es lo que el desarrollador quiere ver).
        if (in_array($provider->name(), MkPushDevice::PROVIDERS, true)) {
            $query->where('provider', $provider->name());
        }

        $addresses = $query->pluck('address')->all();

        if ($addresses === []) {
            return;
        }

        $provider->send($this->message, $addresses);
    }
}
