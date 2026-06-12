<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Role model — groups abilities and is assigned to users.
 */
class Role extends Model
{
    protected $table = 'roles';

    protected $fillable = [
        'name',
        'guard',
        'description',
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
