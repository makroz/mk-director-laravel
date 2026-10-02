<?php

declare(strict_types=1);

namespace Mk\Director\Push\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\PushMessage;

/**
 * Resuelve las direcciones de los dueños o de los suscriptos a un tema, se las
 * entrega al proveedor activo y borra las que el servicio reportó inválidas.
 *
 * Las direcciones se leen ACÁ y no al llamar `send()`: el job puede correr más
 * tarde, y para entonces el teléfono pudo haber pasado a otra persona.
 */
final class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * 🔴 Propiedad con default y NO promovida en el constructor: un job que la
     * cola serializó con el corte 4 no trae `topic`, y al deserializarlo (que
     * no llama al constructor) una promovida quedaría sin inicializar y el
     * job moriría al leerla.
     */
    public ?string $topic = null;

    /** @param list<array{0: string, 1: string}> $owners [morph class, key] */
    public function __construct(public PushMessage $message, public array $owners = [], ?string $topic = null)
    {
        $this->topic = $topic;
    }

    public function handle(PushProvider $provider): void
    {
        $query = $this->devices();

        // 🔴 Sin dueños ni tema no hay `where`, y la consulta devolvería TODOS
        // los teléfonos del sistema.
        if ($query === null) {
            return;
        }

        // Un servicio sólo puede usar las direcciones que emitió él. `log` y
        // `null` no son un servicio: no emiten direcciones, así que con ellos
        // se toman todas las del dueño (es lo que el desarrollador quiere ver).
        $isService = in_array($provider->name(), MkPushDevice::PROVIDERS, true);

        if ($isService) {
            $query->where('mk_push_devices.provider', $provider->name());
        }

        // De a tandas: un tema de miles no se carga entero en memoria. Por id
        // (keyset), así borrar las inválidas de una tanda no corre a la siguiente.
        //
        // ponytail: los temas viven en nuestra base, y FCM v1 es un request por
        // token: 50.000 suscriptos son 50.000 requests desde la cola. Para
        // crecer: los temas nativos de FCM (`message.topic`) o los segmentos
        // de OneSignal, que mandan en un solo request.
        $query->select('mk_push_devices.id', 'mk_push_devices.address')->chunkById(
            max(1, (int) config('mk_director.push.chunk', 500)),
            function (Collection $devices) use ($provider, $isService): void {
                $addresses = $devices->pluck('address')->all();
                $result = $provider->send($this->message, $addresses);

                // Lo que el servicio rechazó para siempre se borra, para no
                // volver a mandarle nunca. Acotado al proveedor activo (la misma
                // dirección de OTRO servicio es otro teléfono) y a esta tanda:
                // un proveedor no puede borrar lo que no se le mandó, ni un
                // teléfono de una tanda que todavía no salió. `log` y `null` no
                // limpian nunca.
                $invalid = array_values(array_intersect($result->invalid, $addresses));

                if ($isService && $invalid !== []) {
                    MkPushDevice::query()
                        ->where('provider', $provider->name())
                        ->whereIn('address', $invalid)
                        ->delete();
                }
            },
            'mk_push_devices.id',
            'id',
        );
    }

    /** @return Builder<MkPushDevice>|null */
    private function devices(): ?Builder
    {
        if ($this->topic !== null) {
            // La suscripción es de la PERSONA: cualquier teléfono suyo recibe.
            return MkPushDevice::query()
                ->join('mk_push_topic_subscriptions as subscription', function (JoinClause $join): void {
                    $join->on('subscription.owner_type', '=', 'mk_push_devices.owner_type')
                        ->on('subscription.owner_id', '=', 'mk_push_devices.owner_id');
                })
                ->where('subscription.topic', $this->topic);
        }

        if ($this->owners === []) {
            return null;
        }

        return MkPushDevice::query()->where(function (Builder $q): void {
            foreach ($this->owners as [$type, $id]) {
                $q->orWhere(fn (Builder $o) => $o->where('mk_push_devices.owner_type', $type)->where('mk_push_devices.owner_id', $id));
            }
        });
    }
}
