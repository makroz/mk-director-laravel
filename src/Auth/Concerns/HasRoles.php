<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkRoleUserPivot;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;

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
     *
     * R-PKG-021 BUG-NEW-31 (RC9 regression of HALLAZGO-NEW-01):
     * La relation retorna `MkBelongsToMany` (no `BelongsToMany` stock).
     * Esta relation custom override `attach()` y `attachNew()` para
     * inyectar `user_type = $this->getMorphClass()` automáticamente cuando
     * la pivot tiene la columna. Cubre TODAS las mutations nativas de
     * Eloquent (`attach`, `detach`, `sync`, `syncWithoutDetaching`,
     * `toggle`, `updateExistingPivot`) — no solo los helpers.
     *
     * R-PKG-020 HALLAZGO-NEW-01 (defense-in-depth):
     * `using(MkRoleUserPivot::class)` también aplica un listener
     * `creating` en `MkPivot::boot()` que setea `user_type` si el listener
     * se dispara (es la segunda capa de defensa). En la práctica, la
     * primera capa (MkBelongsToMany::attach) cubre runtime; la segunda
     * cubre cualquier edge case donde la relation stock cree la pivot
     * directamente.
     *
     * Si el consumer override esta relation con su propio `->using(...)`,
     * su pivot gana (BC preservada). Si el consumer quiere opt-out de la
     * auto-inyección, override la relation en su modelo sin retornar
     * `MkBelongsToMany`.
     */
    public function roles(): BelongsToMany
    {
        // Crea la BelongsToMany stock via Laravel, luego promueve a
        // MkBelongsToMany via reflection-based state copy (R-PKG-021).
        // Esto preserva el setup interno de Laravel (query builder,
        // related model, keys) y solo cambia el tipo de la instance
        // para que `newPivot()` override aplique via dynamic dispatch.
        $relation = $this->belongsToMany(
            Role::class,
            'role_user',
        )
            ->using(MkRoleUserPivot::class)
            ->withTimestamps();

        return MkBelongsToMany::from($relation);
    }

    /**
     * Asigna un rol al usuario por nombre. Si el rol no existe,
     * lo crea con el guard del `auth_scope` del usuario.
     *
     * Acepta string|Role: si pasás un Role ya materializado, se
     * usa directo.
     */
    /**
     * Asigna un rol al usuario por nombre. Si el rol no existe,
     * lo crea con el guard del `auth_scope` del usuario.
     *
     * Acepta string|Role: si pasás un Role ya materializado, se
     * usa directo.
     *
     * R-PKG-016 BUG-NEW-16 fix: cuando la pivot `role_user` tiene la
     * columna `user_type` (caso MME-polimórfico, `mk:make:auth-user X
     * --with-crud` con FK overrides en el modelo concreto), las mutaciones
     * DEBEN setear `user_type = static::class` para que el INSERT no rompa
     * con `NOT NULL violation on column user_type`. Antes solo hacía
     * `syncWithoutDetaching([$id])` sin extras, lo que dejaba la pivot en NULL.
     *
     * La fix detecta via `Schema::hasColumn()` si la pivot tiene la columna
     * y agrega los extras al payload. BC-safe: si la pivot NO tiene `user_type`,
     * el comportamiento es idéntico al previo (sin extras).
     */
    public function assignRole(string|Role $role): void
    {
        $roleModel = $role instanceof Role
            ? $role
            : Role::query()->firstOrCreate(
                ['name' => $role],
                ['guard' => $this->getAuthScope() ?? 'web'],
            );

        $payload = $this->pivotExtras();
        $this->roles()->syncWithoutDetaching(
            $payload === [] ? [$roleModel->id] : [$roleModel->id => $payload]
        );

        // Roles feed into abilities via ability_role; invalidate the
        // ability cache so the next canMk() re-resolves.
        if (method_exists($this, 'invalidateAbilityCache')) {
            $this->invalidateAbilityCache();
        }
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

        if (method_exists($this, 'invalidateAbilityCache')) {
            $this->invalidateAbilityCache();
        }
    }

    /**
     * Alias de {@see removeRole()} — el 4R audit se refería a revokeRole
     * (R4-001 invalidation contract); ambos nombres están soportados
     * para que el código legacy y el código nuevo sean intercambiables.
     */
    public function revokeRole(string|Role $role): void
    {
        $this->removeRole($role);
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
    /**
     * Sincroniza los roles del usuario (reemplaza los existentes).
     *
     * R-PKG-016 BUG-NEW-16 fix: idem assignRole() — incluye `user_type`
     * cuando la pivot lo requiere.
     *
     * @param  array<int, string|Role>  $roles
     */
    public function syncRoles(array $roles): void
    {
        $ids = [];
        $payload = $this->pivotExtras();

        foreach ($roles as $role) {
            $roleModel = $role instanceof Role
                ? $role
                : Role::query()->firstOrCreate(
                    ['name' => $role],
                    ['guard' => $this->getAuthScope() ?? 'web'],
                );
            // sync() espera `[id => extras]` cuando hay extras — la firma con
            // array_indexado también funciona pero perderíamos los extras si la
            // pivot tiene timestamps auto.
            $ids[$roleModel->id] = $payload;
        }

        $this->roles()->sync($ids);

        if (method_exists($this, 'invalidateAbilityCache')) {
            $this->invalidateAbilityCache();
        }
    }

    /**
     * Devuelve los extras a adjuntar a las mutaciones de pivot (R-PKG-016 BUG-NEW-16).
     *
     * Si la pivot `role_user` tiene columna `user_type`, retorna
     * `['user_type' => static::class]` para mantener MME-polimórfico.
     * Si NO la tiene, retorna `[]` (BC: idéntico al comportamiento previo).
     *
     * Schema detection está cacheada en memoria del proceso para evitar
     * un query a information_schema por cada attach/detach.
     *
     * R-PKG-017 BUG-NEW-22: visibilidad cambiada de `protected` a `public`
     * (BC-safe: solo agrega visibilidad, no rompe ningún caller existente).
     * Esto permite que el Repository scaffoldeado por
     * `mk:make:auth-user X --with-crud` consuma este helper directamente
     * en `syncRoles($admin, [...])` para evitar el hardcodeo manual de
     * `['user_type' => Admin::class]` que el consumer tenía que hacer.
     * Antes el consumer tenía que hardcodear el FQCN (DRY violation +
     * acoplamiento a una clase concreta del paquete) o usar reflection
     * (frágil). Con el helper público, el scaffolder emite:
     *
     *     $admin->roles()->sync(
     *         $roles->pluck('id')->mapWithKeys(
     *             fn ($id) => [$id => $admin->pivotExtras()]
     *         )->all()
     *     );
     *
     * Si la pivot NO tiene `user_type` (consumer legacy), `pivotExtras()`
     * retorna `[]` y el comportamiento es idéntico al previo
     * (`sync($ids, [])` ≈ `sync($ids)` con array_indexado).
     */
    public function pivotExtras(): array
    {
        static $hasUserType = null;

        if ($hasUserType === null) {
            try {
                $hasUserType = Schema::hasColumn('role_user', 'user_type');
            } catch (\Throwable) {
                // Si la tabla no existe todavía (consumer no migró), no agregar
                // extras. Cuando migre, el próximo attach ya tendrá el cache miss
                // y re-detectará.
                $hasUserType = false;
            }
        }

        return $hasUserType ? ['user_type' => static::class] : [];
    }

    /**
     * Scope del usuario. Lo provee `AuthUser::getAuthScope()`. Las
     * clases que usen este trait deben declarar este método.
     */
    abstract public function getAuthScope(): ?string;
}
