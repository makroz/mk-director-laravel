<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Access;

use BackedEnum;
use Illuminate\Contracts\Auth\Authenticatable;
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
 *
 * Cada `assert*` tira {@see AccessGrantDeniedException}, que se renderiza 403.
 */
class AccessGrantGuard
{
    public const ERR_SELF_ACCESS_CHANGE = 'ERR_SELF_ACCESS_CHANGE';

    public const ERR_TARGET_OUTRANKS_ACTOR = 'ERR_TARGET_OUTRANKS_ACTOR';

    public const ERR_ACCESS_NOT_HELD = 'ERR_ACCESS_NOT_HELD';

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
