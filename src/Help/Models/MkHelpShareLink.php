<?php

declare(strict_types=1);

namespace Mk\Director\Help\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Mk\Director\Help\MkHelpShareLinks;

/**
 * Un enlace público y temporal a un manual o a un artículo. Lo crea y lo
 * resuelve {@see MkHelpShareLinks}; nada de esta tabla llega de un request.
 *
 * Tabla: `mk_director.help.share_links_table` (default `mk_help_share_links`).
 *
 * @property string $id
 * @property string $token_hash sha256 del token: el token crudo no se guarda
 * @property string $target `slug:<slug>` o `manual:<audience>`
 * @property string $created_by_type
 * @property string $created_by_id
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property int $uses
 * @property Carbon $created_at
 */
class MkHelpShareLink extends EloquentModel
{
    use HasUuids;

    protected $fillable = [
        'token_hash',
        'target',
        'created_by_type',
        'created_by_id',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'uses',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'uses' => 'integer',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('mk_director.help.share_links_table', 'mk_help_share_links');
    }

    /** Los enlaces de UN creador. Toda búsqueda por id del diálogo pasa por acá: un id ajeno no matchea. */
    public function scopeCreatedBy(Builder $query, EloquentModel $creator): Builder
    {
        return $query->where('created_by_type', $creator->getMorphClass())
            ->where('created_by_id', (string) $creator->getKey());
    }

    /** `revoked` gana a `expired`: lo revocó alguien, aunque además haya vencido. */
    public function status(): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }
}
