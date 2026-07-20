<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Enums\MkMediaKind;

/**
 * MkMedia — una pieza de media adjunta a cualquier modelo del consumer.
 *
 * Spec: Comunicaciones Fase 1, PR 1. Tabla: {@see mk_media} (configurable).
 *
 * Extiende Eloquent directo, igual que `Role` y `Ability`, y NO
 * `Mk\Director\Models\Model`: la base del paquete instala el
 * `BaseModelBuilder` con cache, y una galería que se cachea sin invalidación
 * explícita es una fuente de bugs difícil de ver (subís una foto, no aparece).
 * Opt-in después si algún consumer lo pide con un caso medido.
 */
class MkMedia extends EloquentModel
{
    protected $fillable = [
        'mediable_type',
        'mediable_id',
        'collection',
        'kind',
        'disk',
        'path',
        'provider',
        'provider_id',
        'source_url',
        'thumbnail_url',
        'mime_type',
        'size',
        'width',
        'height',
        'duration',
        'position',
        'meta',
    ];

    protected $casts = [
        'kind' => MkMediaKind::class,
        'meta' => 'array',
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'position' => 'integer',
    ];

    /**
     * El nombre de tabla se lee de config en runtime (no como propiedad
     * estática) para que un consumer pueda renombrarla y para que los tests
     * puedan cambiarla sin tocar el modelo.
     */
    public function getTable(): string
    {
        return $this->table ?? config('mk_director.media.table', 'mk_media');
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * URL pública de esta media, sea archivo propio o embed.
     *
     * Para Image/Video resuelve contra el disk de LA FILA — no contra un disk
     * global. Ésa es una de las limitaciones concretas de `FileStoragePlugin`,
     * que lee `$config['disk']` una sola vez para todos los archivos.
     *
     * Para Embed no hay archivo nuestro: devuelve la `source_url` original.
     */
    public function getUrlAttribute(): ?string
    {
        if (! $this->kind instanceof MkMediaKind) {
            return null;
        }

        if (! $this->kind->hasStoredFile()) {
            return $this->source_url;
        }

        if ($this->path === null) {
            return null;
        }

        return Storage::disk($this->disk ?: config('filesystems.default'))->url($this->path);
    }
}
