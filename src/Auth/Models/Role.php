<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mk\Director\Auth\Enums\FixedStatus;

/**
 * Role model — groups abilities and is assigned to users.
 */
class Role extends Model
{
    protected $table = 'roles';

    /**
     * `description` (nullable) fue AGREGADA por la migración
     * `2026_07_14_000001_add_description_to_roles_table` — los roles describen
     * QUÉ hacen (UI de gestión). Revierte F10-B07 (que la había sacado porque la
     * tabla original no tenía la columna). `Ability` también tiene `description`
     * (columna real propia) — ambas coexisten sin confusión.
     */
    protected $fillable = [
        'name',
        'guard',
        'description',
        'is_fixed',
    ];

    protected $casts = [
        'is_fixed' => FixedStatus::class,
    ];

    /**
     * @return BelongsToMany<Ability>
     */
    public function abilities(): BelongsToMany
    {
        return $this->belongsToMany(
            Ability::class,
            'ability_role',
            'role_id',
            'ability_id',
        );
    }
}
