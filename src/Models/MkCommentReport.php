<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mk\Director\Enums\MkCommentReportReason;
use Mk\Director\Enums\MkCommentReportStatus;

/**
 * MkCommentReport — la denuncia de UN reporter sobre UN comentario.
 *
 * Spec: Comunicaciones Fase 2, PR 1. Tabla: `mk_comment_reports` (configurable).
 *
 * Extiende Eloquent directo y NO `Mk\Director\Models\Model`, por lo mismo que
 * {@see MkComment} y {@see MkReaction}: la base del paquete instala
 * `BaseModelBuilder` con caché, y una cola de moderación cacheada sin
 * invalidación mostraría reportes ya resueltos como pendientes.
 *
 * NO usa SoftDeletes a propósito: un reporte no se borra, se RESUELVE (cambia
 * de status). El histórico de quién denunció qué es justamente lo que la
 * moderación necesita auditar. Se va sólo cuando el comentario se borra
 * físicamente, por la FK `cascadeOnDelete`.
 */
class MkCommentReport extends EloquentModel
{
    protected $fillable = [
        'comment_id',
        'reporter_type',
        'reporter_id',
        'reason',
        'note',
        'status',
        'resolved_by_type',
        'resolved_by_id',
        'resolved_at',
    ];

    protected $casts = [
        'comment_id' => 'integer',
        'reason' => MkCommentReportReason::class,
        'status' => MkCommentReportStatus::class,
        'resolved_at' => 'datetime',
    ];

    /**
     * El nombre de tabla se lee de config en runtime (no como propiedad
     * estática) para que un consumer pueda renombrarla y para que los tests
     * puedan cambiarla sin tocar el modelo.
     */
    public function getTable(): string
    {
        return $this->table ?? config('mk_director.comment_reports.table', 'mk_comment_reports');
    }

    /**
     * El comentario denunciado.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(MkComment::class, 'comment_id');
    }

    /**
     * Quién reportó. Polimórfico: Admin o Member en RETO.
     */
    public function reporter(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Qué admin lo resolvió. Null mientras está pendiente.
     */
    public function resolvedBy(): MorphTo
    {
        return $this->morphTo();
    }
}
