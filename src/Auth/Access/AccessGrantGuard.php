<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Access;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;

/**
 * Frena la escalada de privilegios por el CRUD de usuarios.
 *
 * 🔴 Medido en el piloto NetPizza, por la cadena HTTP real: un encargado cuya
 * ÚNICA ability era `admin.admins.update` hizo
 * `POST /api/admins/{su propio id}/abilities` con abilities que no tenía → 200,
 * y las tuvo. Las rutas de acceso generadas (`/{id}/access`, `/roles`,
 * `/abilities`) sólo exigían `update`. Tener `update` sobre usuarios no puede
 * significar poder darse cualquier permiso.
 *
 * ── LAS REGLAS ─────────────────────────────────────────────────────────────
 *
 * Aplican cuando actor y usuario objetivo son del MISMO scope de auth:
 *
 *  1. Nadie cambia SU PROPIO acceso (roles, abilities directas ni `status`)
 *     por el CRUD → `ERR_SELF_ACCESS_CHANGE`. Tampoco quien tiene `*`: un
 *     super-admin que se quita el rol por error queda afuera.
 *  2. Nadie actúa (editar, bloquear, borrar, cambiar acceso) sobre un usuario
 *     con MÁS acceso que él: si las abilities efectivas del objetivo no están
 *     todas cubiertas por las del actor → `ERR_TARGET_OUTRANKS_ACTOR`. Si no, un
 *     encargado bloquea al dueño o le cambia el email.
 *  3. Sólo se concede o QUITA lo que el actor tiene: cada ability directa que
 *     cambia, y cada ability de cada rol que se agrega o se saca, tiene que
 *     estar cubierta por el actor → `ERR_ACCESS_NOT_HELD`. Quitar también
 *     cuenta: si no, un encargado desarma a quien tiene más. `*` cubre todo.
 *
 * "Cubierta" es la misma semántica que `canMk()`: `*`, el nombre exacto, o
 * `recurso.*`. Se mira lo que el actor tiene por roles y grants directos, no
 * las abilities de su token.
 *
 * Fuera de las reglas, a propósito:
 *  - Actor de OTRO scope (el recurso managed: un admin administrando meseros).
 *    Las abilities de otro scope son otro espacio de nombres; lo que el manager
 *    puede hacer lo decide `mk.ability:{manager}.{recurso}.*` en la ruta.
 *  - Sin actor autenticado (consola, seeders): no hay cuenta que escale.
 *
 * Uso (el scaffolder ya lo cablea en el controller y el Service generados):
 *
 *     app(AccessGrantGuard::class)->assertCanChangeAccess($request->user(), $target, $roleNames, $abilityNames);
 *     app(AccessGrantGuard::class)->assertCanUpdate($request->user(), $target, $input);
 *     app(AccessGrantGuard::class)->assertCanDelete($request->user(), $target);
 *     app(AccessGrantGuard::class)->assertCanChangeRole($request->user(), $role, $abilityNames);
 *
 * Cada `assert*` tira {@see AccessGrantDeniedException}, que se renderiza 403.
 */
class AccessGrantGuard
{
    public const ERR_SELF_ACCESS_CHANGE = 'ERR_SELF_ACCESS_CHANGE';

    public const ERR_TARGET_OUTRANKS_ACTOR = 'ERR_TARGET_OUTRANKS_ACTOR';

    public const ERR_ACCESS_NOT_HELD = 'ERR_ACCESS_NOT_HELD';

    public const ERR_FIXED_ROLE = 'ERR_FIXED_ROLE';

    /**
     * Antes de sincronizar roles y/o abilities directas de `$target`.
     *
     * @param  array<int, string>|null  $roleNames  El conjunto COMPLETO que va a quedar; null = no se tocan roles.
     * @param  array<int, string>|null  $abilityNames  Idem para abilities directas; null = no se tocan.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanChangeAccess(?Authenticatable $actor, AuthUser $target, ?array $roleNames, ?array $abilityNames): void
    {
        if (! $actor instanceof AuthUser) {
            return;
        }

        if ($this->isSameAccount($actor, $target)) {
            throw new AccessGrantDeniedException('No podés cambiar tu propio acceso.', self::ERR_SELF_ACCESS_CHANGE);
        }

        if (! $this->isSameScope($actor, $target)) {
            return;
        }

        $this->assertDoesNotOutrank($actor, $target);

        if ($roleNames !== null) {
            $current = $target->roles->pluck('name')->all();
            $changed = $this->symmetricDifference($current, $roleNames);

            $roles = Role::query()
                ->where('guard', $target->getAuthScope())
                ->whereIn('name', $changed)
                ->with('abilities')
                ->get();

            foreach ($roles as $role) {
                $this->assertHoldsAll($actor, $role->abilities->pluck('name')->all());
            }
        }

        if ($abilityNames !== null) {
            $current = $target->directAbilities->pluck('name')->all();
            $this->assertHoldsAll($actor, $this->symmetricDifference($current, $abilityNames));
        }
    }

    /**
     * Antes de actualizar `$target` por el CRUD.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanUpdate(?Authenticatable $actor, AuthUser $target, array $input): void
    {
        if (! $actor instanceof AuthUser) {
            return;
        }

        if ($this->isSameAccount($actor, $target)) {
            // Mandar su propio status SIN cambiarlo es válido: un formulario de
            // edición manda el objeto entero.
            if (array_key_exists('status', $input) && $this->scalar($input['status']) !== $this->scalar($target->getAttribute('status'))) {
                throw new AccessGrantDeniedException('No podés cambiar tu propio estado.', self::ERR_SELF_ACCESS_CHANGE);
            }

            return;
        }

        if ($this->isSameScope($actor, $target)) {
            $this->assertDoesNotOutrank($actor, $target);
        }
    }

    /**
     * Antes de borrar `$target` por el CRUD.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanDelete(?Authenticatable $actor, AuthUser $target): void
    {
        if (! $actor instanceof AuthUser || $this->isSameAccount($actor, $target) || ! $this->isSameScope($actor, $target)) {
            return;
        }

        $this->assertDoesNotOutrank($actor, $target);
    }

    /**
     * Antes de editar, borrar o sincronizar las abilities de `$role` por el CRUD
     * de roles. Las mismas reglas, llevadas al rol: cambiarle las abilities a un
     * rol es cambiárselas a TODOS los que lo tienen.
     *
     * 🔴 Medido en RETO: con sólo `{scope}.roles.update`, un encargado le
     * agregaba abilities a su PROPIO rol por `PUT /roles/{id}/abilities` y las
     * tenía (200). Las reglas de arriba cubrían el CRUD de usuarios, no este.
     *
     *  - Un rol FIJO (`is_fixed`) es del sistema: no se toca, ni con `*`
     *    → `ERR_FIXED_ROLE`.
     *  - Nadie cambia las abilities de un rol que TIENE → `ERR_SELF_ACCESS_CHANGE`.
     *  - Nadie toca un rol que tiene alguien con MÁS acceso: editarlo (el
     *    `guard`) o borrarlo también le cambia el acceso → `ERR_TARGET_OUTRANKS_ACTOR`.
     *  - Sólo se agrega o se quita lo que el actor tiene → `ERR_ACCESS_NOT_HELD`.
     *
     * Fuera, como en las otras reglas: sin actor, quien tiene `*`, y un rol de
     * OTRO scope que el del actor (un admin armando los roles de los meseros).
     *
     * @param  array<int, string>|null  $nextAbilities  El conjunto COMPLETO que va a quedar; null = no se tocan (editar). Borrar es `[]`.
     *
     * @throws AccessGrantDeniedException
     */
    public function assertCanChangeRole(?Authenticatable $actor, Role $role, ?array $nextAbilities): void
    {
        if ($role->is_fixed === FixedStatus::Fixed) {
            throw new AccessGrantDeniedException('Es un rol del sistema: no se modifica desde acá.', self::ERR_FIXED_ROLE);
        }

        if (! $actor instanceof AuthUser || $actor->getAuthScope() !== $role->guard || $this->holds($actor, '*')) {
            return;
        }

        if ($nextAbilities !== null && $actor->roles()->whereKey($role->getKey())->exists()) {
            throw new AccessGrantDeniedException('No podés cambiar las abilities de un rol que tenés.', self::ERR_SELF_ACCESS_CHANGE);
        }

        $holders = $actor::query()->whereHas('roles', fn ($q) => $q->whereKey($role->getKey()))->get();
        foreach ($holders as $holder) {
            $this->assertDoesNotOutrank($actor, $holder);
        }

        if ($nextAbilities !== null) {
            $this->assertHoldsAll($actor, $this->symmetricDifference($role->abilities()->pluck('name')->all(), $nextAbilities));
        }
    }

    /**
     * ¿`$actor` tiene `$ability` por sus roles o grants directos? Misma
     * semántica que `canMk()` (`*`, exacta, `recurso.*`), sin el atajo del token.
     */
    public function holds(AuthUser $actor, string $ability): bool
    {
        $names = $actor->getEffectiveAbilities();

        if (in_array('*', $names, true) || in_array($ability, $names, true)) {
            return true;
        }

        $resource = explode('.', $ability, 2)[0];

        return $resource !== '' && $resource !== '*' && in_array($resource.'.*', $names, true);
    }

    private function assertDoesNotOutrank(AuthUser $actor, AuthUser $target): void
    {
        foreach ($target->getEffectiveAbilities() as $ability) {
            if (! $this->holds($actor, $ability)) {
                throw new AccessGrantDeniedException('No podés modificar a un usuario con más acceso que el tuyo.', self::ERR_TARGET_OUTRANKS_ACTOR);
            }
        }
    }

    /** @param  array<int, string>  $abilities */
    private function assertHoldsAll(AuthUser $actor, array $abilities): void
    {
        foreach ($abilities as $ability) {
            if (! $this->holds($actor, $ability)) {
                throw new AccessGrantDeniedException("No podés conceder ni quitar un acceso que no tenés: {$ability}.", self::ERR_ACCESS_NOT_HELD);
            }
        }
    }

    private function isSameAccount(AuthUser $actor, AuthUser $target): bool
    {
        return $actor::class === $target::class && (string) $actor->getKey() === (string) $target->getKey();
    }

    private function isSameScope(AuthUser $actor, AuthUser $target): bool
    {
        $scope = $actor->getAuthScope();

        return $scope !== null && $scope === $target->getAuthScope();
    }

    /**
     * @param  array<int, string>  $current
     * @param  array<int, string>  $next
     * @return array<int, string>
     */
    private function symmetricDifference(array $current, array $next): array
    {
        return array_values(array_unique(array_merge(array_diff($current, $next), array_diff($next, $current))));
    }

    private function scalar(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return $value === null ? null : (string) $value;
    }
}
