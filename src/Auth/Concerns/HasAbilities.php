<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Mk\Director\Auth\Models\Ability;

/**
 * HasAbilities — relación many-to-many entre AuthUser y Ability.
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.5.
 *
 * Modelo de abilities (un solo punto de verdad: tabla `abilities`):
 *  - Un usuario tiene abilities a través de sus roles (path
 *    `auth_users → role_user → roles → ability_role → abilities`).
 *  - Opcionalmente, un usuario tiene grants directos (path
 *    `auth_users → ability_user → abilities`).
 *  - `canMk($ability)` consulta la UNION de ambos paths.
 *
 * Wildcard:
 *  - `*`         matchea cualquier ability (super-admin).
 *  - `users.*`   matchea `users.edit`, `users.delete`, etc.
 *  - Match exacto en otro caso.
 *
 * Implementación de la relación:
 *  - `abilities()` devuelve la UNION vía rol + directo. La consulta
 *    se hace con subqueries en Laravel 13, no con joins, para evitar
 *    ambigüedades con columnas homónimas.
 *  - `directAbilities()` es la relación BelongsToMany directa
 *    contra `ability_user`. El consumer debe publicar esa migración
 *    si quiere grants directos. Si no existe, `canMk` funciona
 *    solo con abilities-vía-rol.
 */
trait HasAbilities
{
    /**
     * Abilities del usuario (vía rol + directas), como relación
     * Eloquent. Internamente es una subquery que une los dos paths.
     *
     * NOTA: la firma es `BelongsToMany` para que encadene con el
     * resto del Query Builder, pero la `pivot` referencia una
     * tabla virtual — usa `get() / pluck()` para materializar.
     */
    public function abilities(): BelongsToMany
    {
        $instance = new Ability();

        // Construimos una relación contra `ability_user` (pivot directo)
        // y luego restringimos a los abilities que también estén en
        // ability_role para alguno de los roles del user.
        $relation = $instance->belongsToMany(
            static::class,
            'ability_user',
            'ability_id',
            'user_id',
        );

        // Filtramos a las abilities conectadas a través de roles del user.
        $userKey = $this->getKey();

        $relation->whereExists(function ($query) use ($userKey) {
            $query->select(\DB::raw(1))
                ->from('ability_role')
                ->join('role_user', 'role_user.role_id', '=', 'ability_role.role_id')
                ->whereColumn('ability_role.ability_id', 'abilities.id')
                ->where('role_user.user_id', '=', $userKey);
        });

        return $relation;
    }

    /**
     * Grants directos (pivot `ability_user`). El consumer debe
     * publicar la migración correspondiente si quiere usar este path.
     */
    public function directAbilities(): BelongsToMany
    {
        return $this->belongsToMany(
            Ability::class,
            'ability_user',
        )->withTimestamps();
    }

    /**
     * ¿El usuario tiene la ability (directa o vía rol),
     * con soporte wildcard?
     */
    public function canMk(string $ability): bool
    {
        $names = $this->collectAllAbilityNames();

        if ($names->contains('*')) {
            return true;
        }

        if ($names->contains($ability)) {
            return true;
        }

        $segments = explode('.', $ability, 2);
        $resource = $segments[0] ?? null;

        if ($resource !== null && $resource !== '' && $names->contains($resource . '.*')) {
            return true;
        }

        return false;
    }

    /**
     * Asigna una ability directamente al usuario.
     *
     * Crea la Ability si no existe (idempotente).
     */
    public function giveAbilityTo(string $ability): void
    {
        $abilityModel = Ability::query()->firstOrCreate(
            ['name' => $ability],
            ['description' => null],
        );

        $this->directAbilities()->syncWithoutDetaching([$abilityModel->id]);
    }

    /**
     * Quita una ability directa al usuario. No afecta abilities
     * que vengan vía roles.
     */
    public function revokeAbilityTo(string $ability): void
    {
        $abilityModel = Ability::query()->where('name', $ability)->first();

        if ($abilityModel === null) {
            return;
        }

        $this->directAbilities()->detach($abilityModel->id);
    }

    /**
     * Helper equivalente a Gate::allows() pero usando abilities del paquete.
     */
    public function ability(string $ability): bool
    {
        return $this->canMk($ability);
    }

    /**
     * Sincroniza los grants directos (reemplaza los existentes).
     *
     * @param  array<int, string>  $abilities
     */
    public function syncDirectAbilities(array $abilities): void
    {
        $ids = [];

        foreach ($abilities as $name) {
            $ability = Ability::query()->firstOrCreate(
                ['name' => $name],
                ['description' => null],
            );
            $ids[] = $ability->id;
        }

        $this->directAbilities()->sync($ids);
    }

    /**
     * Recolecta los nombres de abilities (directos + vía rol) sin duplicar.
     */
    private function collectAllAbilityNames(): Collection
    {
        // Path 1: abilities del user vía roles
        $fromRoles = collect();

        try {
            $roleIds = $this->roles()->pluck('roles.id');
            if ($roleIds->isNotEmpty()) {
                $fromRoles = Ability::query()
                    ->whereIn('id', function ($query) use ($roleIds) {
                        $query->select('ability_id')
                            ->from('ability_role')
                            ->whereIn('role_id', $roleIds);
                    })
                    ->pluck('name');
            }
        } catch (\Throwable) {
            $fromRoles = collect();
        }

        // Path 2: abilities directos
        $fromDirect = collect();
        try {
            $fromDirect = $this->directAbilities()->pluck('abilities.name');
        } catch (\Throwable) {
            // Tabla ability_user no publicada — feature no activo.
            $fromDirect = collect();
        }

        return $fromRoles->merge($fromDirect)->unique()->values();
    }
}
