<?php

declare(strict_types=1);

namespace Mk\Director\Push\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * La suscripción de una persona a un tema. Tabla: `mk_push_topic_subscriptions`.
 *
 * La tabla no tiene `id`: la identidad es `(owner_type, owner_id, topic)`, que
 * es su índice único. Se escribe y se borra por esas tres columnas.
 *
 * @property string $owner_type
 * @property string $owner_id
 * @property string $topic
 */
class MkPushTopicSubscription extends EloquentModel
{
    protected $table = 'mk_push_topic_subscriptions';

    protected $primaryKey = null;

    public $incrementing = false;

    protected $fillable = ['topic'];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
