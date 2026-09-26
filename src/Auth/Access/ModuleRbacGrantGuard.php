<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Frena la escalada de privilegios en los módulos de `mk:module --with-rbac`.
 *
 * 🔴 Medido por el Kernel real en un módulo generado: con SÓLO
 * `{modulo}.{usuarios}.assignRole` un actor se asignaba `super-admin` (200) y
 * cualquier rol con abilities que no tenía; con `revokeRole` le quitaba
 * `super-admin` al dueño; con `roles.syncAbilities` se reescribía SU rol con
 * todas las abilities, y las del rol `super-admin`. Las Policies sólo miran si
 * el actor tiene la ability del ENDPOINT, no QUÉ concede.
 *
 * Es el equivalente de {@see AccessGrantGuard} para este pack, que no se puede
 * reusar tal cual: aquel está tipado al `AuthUser` y al `Role` centrales. Acá
 * el usuario extiende `Authenticatable`, sus abilities salen SÓLO de sus roles
 * con nombre exacto (`hasAbility()`, sin `*` ni `recurso.*`), y el bypass es el
 * rol `super-admin` por NOMBRE (el `before()` de las Policies generadas). Las
 * clases del módulo las genera el consumer, así que se trabaja con `Model` y
 * sus relaciones `roles()`, `abilities()` y `users()`.
 *
 * ── LAS REGLAS ─────────────────────────────────────────────────────────────
 *
 *  1. `super-admin` es FIJO: no se le sincronizan abilities, no se renombra, no
 *     se borra, y ningún rol se renombra a `super-admin` → `ERR_FIXED_ROLE`.
 *     Ni siquiera un super-admin: el `before()` de las Policies le da todo a
 *     quien tenga un rol con ese nombre.
 *  2. Nadie cambia SUS roles, ni las abilities de un rol que tiene →
 *     `ERR_SELF_ACCESS_CHANGE`. Los roles propios, tampoco un super-admin,
 *     igual que en `AccessGrantGuard`: el que se quita el rol queda afuera.
 *  3. Nadie toca a un usuario con MÁS acceso (asignarle o quitarle roles,
 *     editarlo —su contraseña incluida— o borrarlo), ni un rol que tiene
 *     alguien con más acceso → `ERR_TARGET_OUTRANKS_ACTOR`. `super-admin`
 *     supera a todo el que no lo es.
 *  4. Sólo se asigna, se quita o se sincroniza lo que el actor tiene: cada
 *     ability del rol que asigna o revoca, cada ability que agrega o saca de un
 *     rol; asignar o revocar `super-admin` exige serlo → `ERR_ACCESS_NOT_HELD`.
 *
 * Un actor `super-admin` queda afuera de las reglas 3 y 4.
 *
 * Fuera de las reglas, a propósito: sin actor (consola, seeders) o un actor
 * que no es el usuario del módulo. A ese lo frena la Policy generada, tipada
 * al usuario del módulo: con otra clase, Laravel deniega.
 *
 * Uso (los controllers generados ya lo cablean):
 *
 *     app(ModuleRbacGrantGuard::class)->assertCanChangeUserRole($request->user(), $user, $role);
 *     app(ModuleRbacGrantGuard::class)->assertCanChangeUser($request->user(), $user);
 *     app(ModuleRbacGrantGuard::class)->assertCanChangeRole($request->user(), $role, $abilityNames, $newName);
 *
 * Cada `assert*` tira {@see AccessGrantDeniedException}, que se renderiza 403.
 */
class ModuleRbacGrantGuard
{
    public const SUPER_ADMIN = 'super-admin';

    /**
     * Antes de asignarle o quitarle `$role` a `$target`.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanChangeUserRole(?Authenticatable $actor, Model $target, Model $role): void
    {
        if (! $this->applies($actor, $target::class)) {
            return;
        }

        if ($this->isSameAccount($actor, $target)) {
            throw new AccessGrantDeniedException('No podés cambiar tus propios roles.', AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
        }

        if ($this->isSuperAdmin($actor)) {
            return;
        }

        $this->assertDoesNotOutrank($actor, $target);

        if ($role->getAttribute('name') === self::SUPER_ADMIN) {
            throw new AccessGrantDeniedException('Sólo un super-admin asigna o quita el rol super-admin.', AccessGrantGuard::ERR_ACCESS_NOT_HELD);
        }

        $this->assertHoldsAll($actor, $role->abilities()->pluck('name')->all());
    }

    /**
     * Antes de editar o borrar `$target` por el CRUD. Editarse o borrarse a sí
     * mismo no pasa por acá: es su cuenta.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanChangeUser(?Authenticatable $actor, Model $target): void
    {
        if (! $this->applies($actor, $target::class) || $this->isSameAccount($actor, $target) || $this->isSuperAdmin($actor)) {
            return;
        }

        $this->assertDoesNotOutrank($actor, $target);
    }

    /**
     * Antes de editar, borrar o sincronizar las abilities de `$role`.
     *
     * @param  array<int, string>|null  $nextAbilities  El conjunto COMPLETO que va a quedar; null = no se tocan (editar). Borrar es `[]`.
     * @param  string|null  $newName  El `name` que llega en el body; null = no viene.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanChangeRole(?Authenticatable $actor, Model $role, ?array $nextAbilities, ?string $newName = null): void
    {
        $isSuperAdminRole = $role->getAttribute('name') === self::SUPER_ADMIN;

        if ($isSuperAdminRole && ($nextAbilities !== null || ($newName !== null && $newName !== self::SUPER_ADMIN))) {
            throw new AccessGrantDeniedException('El rol super-admin es del sistema: no se modifica ni se borra.', AccessGrantGuard::ERR_FIXED_ROLE);
        }

        if (! $isSuperAdminRole && $newName === self::SUPER_ADMIN) {
            throw new AccessGrantDeniedException('Ningún rol se puede llamar super-admin.', AccessGrantGuard::ERR_FIXED_ROLE);
        }

        if (! $this->applies($actor, $role->users()->getRelated()::class) || $this->isSuperAdmin($actor)) {
            return;
        }

        $roleKey = $role->qualifyColumn($role->getKeyName());

        if ($nextAbilities !== null && $actor->roles()->where($roleKey, $role->getKey())->exists()) {
            throw new AccessGrantDeniedException('No podés cambiar las abilities de un rol que tenés.', AccessGrantGuard::ERR_SELF_ACCESS_CHANGE);
        }

        foreach ($role->users()->get() as $holder) {
            $this->assertDoesNotOutrank($actor, $holder);
        }

        if ($nextAbilities !== null) {
            $current = $role->abilities()->pluck('name')->all();
            $changed = array_merge(array_diff($current, $nextAbilities), array_diff($nextAbilities, $current));
            $this->assertHoldsAll($actor, array_values(array_unique($changed)));
        }
    }

    /** @phpstan-assert-if-true Model $actor */
    private function applies(?Authenticatable $actor, string $userClass): bool
    {
        return $actor instanceof Model && $actor::class === $userClass && method_exists($actor, 'roles');
    }

    private function isSameAccount(Model $actor, Model $target): bool
    {
        return (string) $actor->getKey() === (string) $target->getKey();
    }

    /** Por la base, no por la relación ya cargada: puede venir de antes del cambio. */
    private function isSuperAdmin(Model $user): bool
    {
        $roles = $user->roles();

        return $roles->where($roles->getRelated()->qualifyColumn('name'), self::SUPER_ADMIN)->exists();
    }

    /** @return array<int, string> */
    private function abilitiesOf(Model $user): array
    {
        return $user->roles()->with('abilities')->get()->flatMap->abilities->pluck('name')->unique()->values()->all();
    }

    private function assertDoesNotOutrank(Model $actor, Model $target): void
    {
        $outranks = $this->isSuperAdmin($target) || array_diff($this->abilitiesOf($target), $this->abilitiesOf($actor)) !== [];

        if ($outranks) {
            throw new AccessGrantDeniedException('No podés modificar a un usuario con más acceso que el tuyo.', AccessGrantGuard::ERR_TARGET_OUTRANKS_ACTOR);
        }
    }

    /** @param  array<int, string>  $abilities */
    private function assertHoldsAll(Model $actor, array $abilities): void
    {
        $held = $this->abilitiesOf($actor);

        foreach ($abilities as $ability) {
            if (! in_array($ability, $held, true)) {
                throw new AccessGrantDeniedException("No podés conceder ni quitar un acceso que no tenés: {$ability}.", AccessGrantGuard::ERR_ACCESS_NOT_HELD);
            }
        }
    }
}
