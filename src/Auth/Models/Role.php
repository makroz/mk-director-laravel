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
     * FEEDBACK10 F10-B07: `description` fue removido — la tabla `roles`
     * (ver `2026_06_10_000002_create_roles_table.php`) NUNCA tuvo esa
     * columna (solo `id/name/guard/is_fixed/timestamps`). Mass-assignment
     * de `description` no fallaba silenciosamente (Eloquent no valida
     * $fillable contra el schema real) — el INSERT/UPDATE explotaba con
     * `SQLSTATE[42703] column "description" does not exist` apenas
     * `RoleResource`/el consumer mandaban ese campo. `Ability` SÍ tiene
     * `description` (columna real, ver Ability::$fillable) — no confundir.
     */
    protected $fillable = [
        'name',
        'guard',
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
