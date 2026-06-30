<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Pivots\MkAbilityUserPivot;
use Mk\Director\Auth\Services\AbilityResolver;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;

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
 * Implementación:
 *  - `canMk()` delega al {@see AbilityResolver} singleton para
 *    cache por usuario + Sanctum short-circuit (R4-001). Cae al
 *    path legacy inline si no hay container binded (unit tests).
 *  - Las mutaciones (`giveAbilityTo`, `revokeAbilityTo`,
 *    `syncDirectAbilities`) invalidan la cache del resolver.
 *  - Las mutaciones de roles (en HasRoles::assignRole/removeRole/
 *    syncRoles) también invalidan — los abilities derivados de
 *    roles dependen de la membresía.
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
     *
     * R-PKG-016 BUG-NEW-17 fix: el código previo hacía JOIN directo contra
     * `ability_user` y luego filtraba con `whereExists` apuntando a
     * `ability_role`. Esto retornaba CERO rows para usuarios que NO tienen
     * direct abilities (caso típico cuando las abilities vienen únicamente
     * por roles) — el JOIN a `ability_user` no matchea nada y el `whereExists`
     * queda sin rows donde aplicar. Resultado: `$user->abilities->pluck('name')`
     * retornaba `[]` aunque el user tuviera abilities vía roles.
     *
     * La fix correcta: NO hacer JOIN directo a `ability_user`. En vez de eso,
     * restringir `abilities.id` a la UNION de los dos paths via subqueries
     * (UNION ALL + dedup en memoria). Esto es portable cross-engine y
     * mantiene la firma `BelongsToMany` para que encadene con el resto del
     * Query Builder.
     */
    public function abilities(): BelongsToMany
    {
        $instance = new Ability;

        // Construimos una relation contra `ability_user` (sigue siendo el pivot
        // declarado en el modelo Ability), pero filtramos los ability IDs
        // a la UNION de los dos paths via subquery.
        $relation = $instance->belongsToMany(
            static::class,
            'ability_user',
            'ability_id',
            'user_id',
        );

        $userKey = $this->getKey();

        // R-PKG-016 BUG-NEW-17: filtrar `abilities.id` por la UNION de:
        //   Path 1 (direct):   `ability_user.user_id = ?`
        //   Path 2 (vía rol):  `ability_role` JOIN `role_user.user_id = ?`
        // Usamos `unionAll` + dedup en runtime (las dos subqueries pueden tener
        // solapamiento cuando un user tiene la misma ability por ambos paths).
        $relation->whereIn('abilities.id', function ($query) use ($userKey) {
            $query->select('ability_id')
                ->from('ability_user')
                ->where('user_id', $userKey)
                ->unionAll(
                    DB::table('ability_role')
                        ->join('role_user', 'role_user.role_id', '=', 'ability_role.role_id')
                        ->where('role_user.user_id', $userKey)
                        ->select('ability_role.ability_id'),
                );
        });

        return $relation;
    }

    /**
     * Grants directos (pivot `ability_user`). El consumer debe
     * publicar la migración correspondiente si quiere usar este path.
     *
     * R-PKG-021 BUG-NEW-31 (RC9 regression of HALLAZGO-NEW-01):
     * La relation retorna `MkBelongsToMany` (via reflection-based state copy).
     * Override de `newPivot()` inyecta `user_type = $this->getMorphClass()`
     * automáticamente cuando la pivot tiene la columna. Cubre todas las
     * mutations nativas de Eloquent.
     *
     * R-PKG-020 HALLAZGO-NEW-01 (defense-in-depth): `using(MkAbilityUserPivot::class)`
     * también aplica el listener `creating` en `MkPivot::boot()`. Como segunda
     * capa (cubre edge cases donde pivotParent SÍ está seteado).
     *
     * Si el consumer override esta relation con su propio `->using(...)`,
     * su pivot gana (BC preservada).
     */
    public function directAbilities(): BelongsToMany
    {
        // Crea la BelongsToMany stock via Laravel, luego promueve a
        // MkBelongsToMany via reflection-based state copy (R-PKG-021).
        $relation = $this->belongsToMany(
            Ability::class,
            'ability_user',
        )
            ->using(MkAbilityUserPivot::class)
            ->withTimestamps();

        return MkBelongsToMany::from($relation);
    }

    /**
     * ¿El usuario tiene la ability (directa o vía rol),
     * con soporte wildcard?
     *
     * Delegates to {@see AbilityResolver} so the result is cached per-user
     * and a Sanctum token's `can()` short-circuits before any DB query
     * (audit R4-001).
     */
    public function canMk(string $ability): bool
    {
        $resolver = $this->abilityResolver();

        if ($resolver === null) {
            // No container (e.g. unit test that did not boot Laravel):
            // fall back to the legacy inline path so the trait remains
            // usable in the narrow "no app" case.
            return $this->canMkLegacy($ability);
        }

        return $resolver->can($this, $ability);
    }

    /**
     * Asigna una ability directamente al usuario.
     *
     * Crea la Ability si no existe (idempotente).
     *
     * R-PKG-016 BUG-NEW-16 fix: cuando la pivot `ability_user` tiene la
     * columna `user_type` (caso MME-polimórfico), setea `user_type = static::class`
     * para que el INSERT no rompa con `NOT NULL violation`. Si la pivot NO
     * tiene `user_type`, el comportamiento es idéntico al previo.
     */
    public function giveAbilityTo(string $ability): void
    {
        $abilityModel = Ability::query()->firstOrCreate(
            ['name' => $ability],
            ['description' => null],
        );

        $payload = $this->abilityPivotExtras();
        $this->directAbilities()->syncWithoutDetaching(
            $payload === [] ? [$abilityModel->id] : [$abilityModel->id => $payload]
        );

        $this->invalidateAbilityCache();
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

        $this->invalidateAbilityCache();
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
     * R-PKG-016 BUG-NEW-16 fix: idem giveAbilityTo() — incluye `user_type`
     * cuando la pivot lo requiere.
     *
     * @param  array<int, string>  $abilities
     */
    public function syncDirectAbilities(array $abilities): void
    {
        $ids = [];
        $payload = $this->abilityPivotExtras();

        foreach ($abilities as $name) {
            $ability = Ability::query()->firstOrCreate(
                ['name' => $name],
                ['description' => null],
            );
            $ids[$ability->id] = $payload;
        }

        $this->directAbilities()->sync($ids);

        $this->invalidateAbilityCache();
    }

    /**
     * Devuelve los extras a adjuntar a las mutaciones de `ability_user` (R-PKG-016 BUG-NEW-16).
     *
     * Si la pivot `ability_user` tiene columna `user_type`, retorna
     * `['user_type' => static::class]`. Si NO, retorna `[]` (BC).
     *
     * Schema detection está cacheada en memoria del proceso.
     *
     * R-PKG-017 BUG-NEW-22: idem `HasRoles::pivotExtras()` — visibilidad
     * cambiada de `protected` a `public` para que el Repository scaffoldeado
     * pueda invocar `$admin->abilityPivotExtras()` directamente en
     * `syncDirectAbilities()` y emitir el payload correcto sin hardcodear
     * el FQCN. BC-safe.
     */
    public function abilityPivotExtras(): array
    {
        static $hasUserType = null;

        if ($hasUserType === null) {
            try {
                $hasUserType = Schema::hasColumn('ability_user', 'user_type');
            } catch (\Throwable) {
                $hasUserType = false;
            }
        }

        return $hasUserType ? ['user_type' => static::class] : [];
    }

    /**
     * Legacy inline implementation kept as a no-container fallback
     * for unit tests that did not boot a Laravel app.
     */
    private function canMkLegacy(string $ability): bool
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

        if ($resource !== null && $resource !== '' && $names->contains($resource.'.*')) {
            return true;
        }

        return false;
    }

    /**
     * Recolecta los nombres de abilities (directos + vía rol) sin duplicar.
     *
     * Used as a fallback by canMk() when no AbilityResolver is bound
     * (no container in the test). Resolver-bound callers should not use
     * this directly — go through AbilityResolver::can() to get caching.
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

    /**
     * Devuelve los nombres de abilities efectivos (directos + vía rol) sin
     * duplicar, como array plano `string[]`. Helper público para que
     * Resources scaffoldeados (e.g. `AdminResource::toArray()`) puedan
     * emitir `abilities: string[]` flat al top-level, cumpliendo el
     * contrato cross-stack con `@makroz/web AdminDto.abilities: string[]`
     * que consume `useMkAuth().hasAbility(ability)` y la sidebar
     * permission-gated.
     *
     * R-PKG-035 HALLAZGO-NEW-FASE15-06 fix (v1.8.3-rc0): sin este helper,
     * cada consumer tenía que aplanar manualmente en su Resource:
     *
     *     $eff = collect();
     *     foreach ($this->roles as $r) {
     *         if ($r->relationLoaded('abilities')) $eff = $eff->merge($r->abilities->pluck('name'));
     *     }
     *     if ($this->relationLoaded('directAbilities')) $eff = $eff->merge($this->directAbilities->pluck('name'));
     *     return ['abilities' => $eff->unique()->values()->all(), ...];
     *
     * Ahora el Resource scaffoldeado puede usar:
     *
     *     'abilities' => $this->whenLoaded('roles', fn () => $this->getEffectiveAbilities()),
     *
     * BC-safe: additive public method. No reemplaza `collectAllAbilityNames()`
     * (mantenido private como fallback para `canMkLegacy()`).
     *
     * @return array<int, string>
     */
    public function getEffectiveAbilities(): array
    {
        return $this->collectAllAbilityNames()->values()->all();
    }

    /**
     * Resolve the AbilityResolver from the container, or null if no
     * container is available (unit tests that did not boot the app).
     */
    private function abilityResolver(): ?AbilityResolver
    {
        if (! function_exists('app')) {
            return null;
        }

        try {
            $app = app();
        } catch (\Throwable) {
            return null;
        }

        if (! $app->bound(AbilityResolver::class)) {
            return null;
        }

        try {
            return $app->make(AbilityResolver::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Drop the cached ability set so the next canMk() call re-reads
     * from the source of truth. Called by every mutation path that
     * changes the user's grants (direct + role-derived).
     */
    private function invalidateAbilityCache(): void
    {
        $resolver = $this->abilityResolver();
        if ($resolver !== null && $this instanceof Authenticatable) {
            $resolver->invalidate($this);
        }
    }
}
