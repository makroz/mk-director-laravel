<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * MkComment — un comentario de un autor sobre un contenido.
 *
 * Spec: Comunicaciones Fase 1, PR 3. Tabla: `mk_comments` (configurable).
 *
 * 🔴 `SoftDeletes` DE VERDAD, no sólo la columna.
 * El legacy tenía `deleted_at` en la tabla y NO usaba el trait (problema 23):
 * el `delete()` borraba la fila físicamente mientras la columna sugería lo
 * contrario. Es el peor de los dos mundos — nadie se entera hasta que hay que
 * restaurar algo y no quedó nada.
 *
 * Extiende Eloquent directo y NO `Mk\Director\Models\Model`, por lo mismo que
 * {@see MkMedia} y {@see MkReaction}: la base del paquete instala
 * `BaseModelBuilder` con caché, y un hilo de comentarios cacheado sin
 * invalidación explícita significa comentar y no ver el comentario propio.
 */
class MkComment extends EloquentModel
{
    use SoftDeletes;

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'author_type',
        'author_id',
        'parent_id',
        'body',
    ];

    protected $casts = [
        'parent_id' => 'integer',
    ];

    /**
     * El nombre de tabla se lee de config en runtime (no como propiedad
     * estática) para que un consumer pueda renombrarla y para que los tests
     * puedan cambiarla sin tocar el modelo.
     */
    public function getTable(): string
    {
        return $this->table ?? config('mk_director.comments.table', 'mk_comments');
    }

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * El comentario que esta respuesta contesta. Null si es raíz.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Las respuestas a este comentario, más viejas primero — un hilo se lee en
     * el orden en que se escribió, al revés que el feed.
     *
     * `id` como desempate: dos respuestas del mismo segundo salen en un orden
     * que depende del motor y el test que las compare se vuelve flaky.
     *
     * Por el anidamiento de un solo nivel (ver `HasMkComments::addComment()`),
     * una respuesta NUNCA tiene respuestas propias: acá siempre da vacío.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * ¿Es un comentario raíz (no una respuesta)?
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Propaga el SOFT delete a las respuestas.
     *
     * La FK `parent_id` tiene `cascadeOnDelete`, pero eso sólo cubre el
     * borrado FÍSICO: un soft delete es un UPDATE de `deleted_at` y la base no
     * dispara ninguna FK. Sin esto, borrar un comentario raíz dejaba sus
     * respuestas visibles y colgando de un padre que ya no se ve — un hilo
     * roto en pantalla.
     *
     * En `forceDelete` no hacemos nada a propósito: ahí la FK sí actúa y
     * duplicar el borrado a mano sería trabajo redundante.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $comment): void {
            if ($comment->isForceDeleting()) {
                return;
            }

            $comment->replies()->get()->each->delete();
        });
    }
}
