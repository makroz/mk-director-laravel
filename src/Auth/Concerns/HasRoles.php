<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mk\Director\Auth\Models\Role;

/**
 * HasRoles — relación many-to-many entre AuthUser y Role.
 *
 * Spec: MK-LAR-1.0.2.
 *
 * Tablas involucradas:
 *  - `roles`       (modelo Role)
 *  - `role_user`   (pivot: user_id, role_id)
 */
trait HasRoles
{
    /**
     * Roles asignados al usuario.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user',
        )->withTimestamps();
    }

    /**
     * Asigna un rol al usuario por nombre. Si el rol no existe,
     * lo crea con el guard del `auth_scope` del usuario.
     *
     * Acepta string|Role: si pasás un Role ya materializado, se
     * usa directo.
     */
    public function assignRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->firstOrCreate(
                ['name' => $role],
                ['guard' => $this->getAuthScope() ?? 'web'],
            );

        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    /**
     * Quita un rol del usuario. Acepta string|Role.
     */
    public function removeRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->where('name', $role)->first();

        if ($roleModel === null) {
            return;
        }

        $this->roles()->detach($roleModel->id);
    }

    /**
     * ¿El usuario tiene alguno de los roles dados?
     *
     * @param  string|array<int, string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        $candidates = (array) $roles;

        return $this->roles->whereIn('name', $candidates)->isNotEmpty();
    }

    /**
     * Devuelve el primer rol con el nombre dado, o null.
     */
    public function role(string $name): ?Role
    {
        return $this->roles->firstWhere('name', $name);
    }

    /**
     * Sincroniza los roles del usuario (reemplaza los existentes).
     *
     * @param  array<int, string|Role>  $roles
     */
    public function syncRoles(array $roles): void
    {
        $ids = [];

        foreach ($roles as $role) {
            $roleModel = $role instanceof Role
                ? $role
                : Role::query()->firstOrCreate(
                    ['name' => $role],
                    ['guard' => $this->getAuthScope() ?? 'web'],
                );
            $ids[] = $roleModel->id;
        }

        $this->roles()->sync($ids);
    }

    /**
     * Scope del usuario. Lo provee `AuthUser::getAuthScope()`. Las
     * clases que usen este trait deben declarar este método.
     */
    abstract public function getAuthScope(): ?string;
}
