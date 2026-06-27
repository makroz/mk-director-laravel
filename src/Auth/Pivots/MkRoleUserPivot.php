<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Pivots;

/**
 * MkRoleUserPivot — pivot para `role_user` con auto-set de `user_type`.
 *
 * R-PKG-020 HALLAZGO-NEW-01: aplicada via `->using(MkRoleUserPivot::class)`
 * en {@see \Mk\Director\Auth\Concerns\HasRoles::roles()}. Garantiza que
 * cualquier consumer que use `$user->roles()->attach([$id])` directo
 * (sin pasar `pivotExtras()`) setee `user_type = get_class($user)`
 * automáticamente cuando la pivot tiene la columna.
 *
 * Tabla fija: `role_user`. Los consumers que usen un nombre de tabla
 * distinto deben declarar su propio Pivot class con `use` del trait
 * base {@see MkPivot}.
 *
 * @see \Mk\Director\Auth\Pivots\MkPivot para la lógica del listener.
 */
class MkRoleUserPivot extends MkPivot
{
    /**
     * Nombre de la tabla pivot. Fijo para esta clase concreta.
     */
    protected $table = 'role_user';
}
