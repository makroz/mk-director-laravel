<?php

declare(strict_types=1);

namespace Mk\Director\Push\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mk\Director\Models\MkReaction;
use Mk\Director\Push\Jobs\SendPushJob;

/**
 * Un teléfono registrado para recibir push. Tabla: `mk_push_devices`.
 *
 * Extiende Eloquent directo y NO `Mk\Director\Models\Model`, por lo mismo que
 * {@see MkReaction}: la base del paquete cachea las consultas, y una lista de
 * direcciones cacheada le seguiría mandando los avisos de alguien al teléfono
 * que ya pasó a otra persona.
 *
 * 🔴 El dueño NO está en `$fillable`: lo pone el controller con el usuario
 * autenticado, nunca el body del request.
 *
 * @property string $id
 * @property string $owner_type
 * @property string $owner_id
 * @property string $provider
 * @property string $address
 * @property string $platform
 */
class MkPushDevice extends EloquentModel
{
    use HasUuids;

    /**
     * Los servicios que emiten direcciones. Es la lista blanca del registro y
     * también lo que separa un servicio de verdad de los drivers `log` y `null`
     * en {@see SendPushJob}.
     */
    public const PROVIDERS = ['fcm', 'onesignal'];

    public const PLATFORMS = ['ios', 'android'];

    protected $table = 'mk_push_devices';

    protected $fillable = ['provider', 'address', 'platform', 'last_seen_at'];

    protected $casts = ['last_seen_at' => 'datetime'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, EloquentModel $owner): Builder
    {
        return $query
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey());
    }
}
