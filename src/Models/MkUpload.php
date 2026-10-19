<?php

declare(strict_types=1);

namespace Mk\Director\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Storage\DirectUpload\MkDirectUpload;

/**
 * Una subida directa al storage: el navegador sube las partes a links firmados
 * y la API nunca ve los bytes. Ciclo de vida en {@see MkDirectUpload}.
 *
 * Tabla: `mk_director.storage.uploads_table` (default `mk_uploads`). Extiende
 * Eloquent directo, sin la cache de la base del paquete, igual que {@see MkMedia}.
 *
 * Todos los campos los escribe el servidor: ninguno llega de un request.
 *
 * @property string $id
 * @property string $disk
 * @property string $key
 * @property string $multipart_id
 * @property int $parts
 * @property string $profile
 * @property string|null $mime
 * @property int|null $bytes los REALES del objeto guardado; null hasta el `complete()`
 * @property int|null $width
 * @property int|null $height
 * @property MkUploadStatus $status
 * @property string $owner_type
 * @property string $owner_id
 * @property Carbon $expires_at
 */
class MkUpload extends EloquentModel
{
    use HasUuids;

    protected $fillable = [
        'id',
        'disk',
        'key',
        'multipart_id',
        'parts',
        'profile',
        'mime',
        'bytes',
        'width',
        'height',
        'status',
        'owner_type',
        'owner_id',
        'expires_at',
    ];

    protected $casts = [
        'status' => MkUploadStatus::class,
        'parts' => 'integer',
        'bytes' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('mk_director.storage.uploads_table', 'mk_uploads');
    }

    /** Las subidas de UN dueño. Toda búsqueda por id pasa por acá: un id ajeno no matchea. */
    public function scopeOwnedBy(Builder $query, EloquentModel $owner): Builder
    {
        return $query->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey());
    }
}
