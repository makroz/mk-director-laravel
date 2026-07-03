<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Pivots;

/**
 * MkAbilityUserPivot — pivot para `ability_user` con auto-set de `user_type`.
 *
 * R-PKG-020 HALLAZGO-NEW-01: aplicada via `->using(MkAbilityUserPivot::class)`
 * en {@see \Mk\Director\Auth\Concerns\HasAbilities::directAbilities()}.
 * Garantiza que cualquier consumer que use `$user->directAbilities()->attach([$id])`
 * directo setee `user_type = get_class($user)` automáticamente cuando
 * la pivot tiene la columna.
 *
 * Tabla fija: `ability_user`. Los consumers que usen un nombre de tabla
 * distinto deben declarar su propio Pivot class con `use` del trait
 * base {@see MkPivot}.
 *
 * @see \Mk\Director\Auth\Pivots\MkPivot para la lógica del listener.
 */
class MkAbilityUserPivot extends MkPivot
{
    /**
     * Nombre de la tabla pivot. Fijo para esta clase concreta.
     */
    protected $table = 'ability_user';
}
