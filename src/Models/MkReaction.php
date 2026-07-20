<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mk\Director\Enums\MkReactionType;

/**
 * MkReaction — la reacción de UN autor sobre UN contenido.
 *
 * Spec: Comunicaciones Fase 1, PR 2. Tabla: `mk_reactions` (configurable).
 *
 * Extiende Eloquent directo y NO `Mk\Director\Models\Model`, por lo mismo que
 * {@see MkMedia}: la base del paquete instala `BaseModelBuilder` con caché, y
 * un contador de likes cacheado sin invalidación explícita es justo el bug que
 * esta tabla existe para evitar. Das like y el número no se mueve.
 *
 * NO tiene timestamps de borrado ni SoftDeletes a propósito: sacar un like es
 * sacarlo. Guardar el historial de likes retirados es otro requerimiento, y no
 * es éste.
 */
class MkReaction extends EloquentModel
{
    protected $fillable = [
        'reactable_type',
        'reactable_id',
        'author_type',
        'author_id',
        'type',
    ];

    protected $casts = [
        'type' => MkReactionType::class,
    ];

    /**
     * El nombre de tabla se lee de config en runtime (no como propiedad
     * estática) para que un consumer pueda renombrarla y para que los tests
     * puedan cambiarla sin tocar el modelo.
     */
    public function getTable(): string
    {
        return $this->table ?? config('mk_director.reactions.table', 'mk_reactions');
    }

    public function reactable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
