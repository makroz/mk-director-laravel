<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Mk\Director\Enums\MkCommentReportReason;
use Mk\Director\Enums\MkCommentReportStatus;

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
        'hidden_at' => 'datetime',
        'moderation_reason' => MkCommentReportReason::class,
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

    // ─── Reportes y moderación (Comunicaciones Fase 2, PR 1) ────────────────

    /**
     * Los reportes que recibió este comentario, más nuevos primero.
     */
    public function reports(): HasMany
    {
        return $this->hasMany(MkCommentReport::class, 'comment_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Qué admin ocultó este comentario. Null si está visible. El prefijo de las
     * columnas (`moderated_by_type`/`_id`) lo deriva Eloquent del nombre.
     */
    public function moderatedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * ¿Está oculto por moderación? Es un estado DISTINTO de borrado: oculto
     * sigue existiendo, es reversible y su autor lo ve como lápida. Ver la
     * migración `add_moderation_to_mk_comments`.
     */
    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    /**
     * Reporta este comentario en nombre de `$reporter`.
     *
     * IDEMPOTENTE por el UNIQUE de la tabla, misma lógica que
     * {@see \Mk\Director\Traits\HasMkReactions::react()}: intentamos insertar y,
     * si la base rechaza el duplicado, es que esta persona YA lo había
     * reportado — devolvemos ese reporte en vez de reventar. No pre-chequeamos
     * con un SELECT porque entre el SELECT y el INSERT hay una ventana en la que
     * el otro request ya insertó; la única defensa real es el UNIQUE.
     *
     * 🔴 EL INSERT VA EN `DB::transaction`, NO SUELTO. Si el reporte corre
     * dentro de una transacción externa (un request con varias escrituras, o
     * `RefreshDatabase` en los tests contra Postgres), un INSERT que falla por
     * el UNIQUE aborta la transacción ENTERA —"current transaction is aborted"—
     * y el SELECT del catch muere con ella. Envuelto, el fallo revierte sólo el
     * SAVEPOINT anidado y la transacción externa sobrevive, así que el catch
     * puede consultar el reporte existente. Es el mismo motivo por el que
     * `react()` hace el toggle adentro de una transacción.
     */
    public function reportBy(EloquentModel $reporter, MkCommentReportReason $reason, ?string $note = null): MkCommentReport
    {
        try {
            /** @var MkCommentReport $report */
            $report = DB::transaction(fn (): MkCommentReport => $this->reports()->create([
                'reporter_type' => $reporter->getMorphClass(),
                'reporter_id' => (string) $reporter->getKey(),
                'reason' => $reason,
                'note' => $note,
                'status' => MkCommentReportStatus::Pending,
            ]));

            return $report;
        } catch (UniqueConstraintViolationException) {
            /** @var MkCommentReport $existing */
            $existing = $this->reports()
                ->where('reporter_type', $reporter->getMorphClass())
                ->where('reporter_id', (string) $reporter->getKey())
                ->first();

            return $existing;
        }
    }

    /**
     * Oculta el comentario del muro público y resuelve sus reportes pendientes
     * como "accionados", TODO en una transacción para que la cola de admin
     * nunca quede a medio camino (oculto pero con reportes todavía pendientes,
     * o al revés).
     *
     * El motivo y la nota son los que verá el AUTOR en la lápida; `moderator`
     * queda en `moderated_by` para la auditoría.
     */
    public function hideForModeration(EloquentModel $moderator, MkCommentReportReason $reason, ?string $note = null): void
    {
        DB::transaction(function () use ($moderator, $reason, $note): void {
            $this->forceFill([
                'hidden_at' => now(),
                'moderation_reason' => $reason,
                'moderation_note' => $note,
                'moderated_by_type' => $moderator->getMorphClass(),
                'moderated_by_id' => (string) $moderator->getKey(),
            ])->save();

            $this->resolvePendingReports($moderator, MkCommentReportStatus::Actioned);
        });
    }

    /**
     * Vuelve a mostrar un comentario oculto. NO re-abre los reportes: el admin
     * ya decidió sobre ellos; mostrar de nuevo el comentario es una decisión
     * nueva que no debería resucitar denuncias viejas.
     */
    public function unhide(): void
    {
        $this->forceFill([
            'hidden_at' => null,
            'moderation_reason' => null,
            'moderation_note' => null,
            'moderated_by_type' => null,
            'moderated_by_id' => null,
        ])->save();
    }

    /**
     * Descarta los reportes pendientes sin tocar el comentario: el admin lo
     * revisó y decidió que no viola nada. El comentario sigue visible.
     */
    public function dismissReports(EloquentModel $moderator): void
    {
        $this->resolvePendingReports($moderator, MkCommentReportStatus::Dismissed);
    }

    /**
     * Comentarios visibles para `$viewer`: los NO ocultos, más los propios del
     * viewer aunque estén ocultos (para que vea su lápida). Con `$viewer` null
     * (anónimo), sólo los no ocultos.
     *
     * Es un scope y no un `where` suelto para componerse con la query del hilo
     * sin duplicar la condición. PR 2 lo aplica también al eager load de
     * `replies`: sin eso, una RESPUESTA oculta se colaría igual.
     */
    public function scopeVisibleTo(Builder $query, ?EloquentModel $viewer): Builder
    {
        return $query->where(function (Builder $q) use ($viewer): void {
            $q->whereNull($this->qualifyColumn('hidden_at'));

            if ($viewer !== null) {
                $q->orWhere(function (Builder $own) use ($viewer): void {
                    $own->where($this->qualifyColumn('author_type'), $viewer->getMorphClass())
                        ->where($this->qualifyColumn('author_id'), (string) $viewer->getKey());
                });
            }
        });
    }

    /**
     * Marca los reportes pendientes con un estado terminal (Actioned o
     * Dismissed) y registra quién y cuándo. Los ya resueltos no se tocan: su
     * desenlace histórico es inmutable.
     */
    protected function resolvePendingReports(EloquentModel $moderator, MkCommentReportStatus $status): void
    {
        $this->reports()
            ->where('status', MkCommentReportStatus::Pending->value)
            ->update([
                'status' => $status->value,
                'resolved_by_type' => $moderator->getMorphClass(),
                'resolved_by_id' => (string) $moderator->getKey(),
                'resolved_at' => now(),
            ]);
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
