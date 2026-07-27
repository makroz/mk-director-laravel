<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Enums\FixedStatus;

/**
 * Ability model — `resource.action` style, e.g. `users.view`.
 */
class Ability extends Model
{
    protected $table = 'abilities';

    protected $fillable = [
        'name',
        'description',
        'is_fixed',
        'is_baseline',
    ];

    protected $casts = [
        'is_fixed' => FixedStatus::class,
        'is_baseline' => 'boolean',
    ];

    /**
     * Abilities BASELINE: las que tiene cualquier usuario autenticado del scope
     * por el solo hecho de existir. Las declara `#[Ability(..., baseline: true)]`
     * y las persiste `mk:discover-abilities`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBaseline($query, bool $baseline = true)
    {
        return $query->where('is_baseline', $baseline);
    }
}
