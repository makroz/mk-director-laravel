<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Tenancy\TenantContext;

/**
 * Role model — groups abilities and is assigned to users.
 */
class Role extends Model
{
    protected $table = 'roles';

    /**
     * `description` (nullable) fue AGREGADA por la migración
     * `2026_07_14_000001_add_description_to_roles_table` — los roles describen
     * QUÉ hacen (UI de gestión). Revierte F10-B07 (que la había sacado porque la
     * tabla original no tenía la columna). `Ability` también tiene `description`
     * (columna real propia) — ambas coexisten sin confusión.
     */
    protected $fillable = [
        'name',
        'guard',
        'description',
        'is_fixed',
    ];

    protected $casts = [
        'is_fixed' => FixedStatus::class,
    ];

    /**
     * ROLES POR TENANT — `mk_director.tenant.roles_per_tenant` (opt-in).
     *
     * La config se lee DENTRO del scope y del hook, no al registrarlos: `booted()`
     * corre una vez por proceso, y un flag leído ahí quedaría congelado.
     *
     * 🔴 Con contexto, el scope es «de la plataforma O de este tenant», nunca
     * «de este tenant» a secas: los roles de la plataforma (`super-admin`,
     * `base`, los que siembra el consumer) tienen que seguir resolviéndose por
     * nombre en `assignRole()` y `hasRole()` desde adentro de un tenant.
     *
     * Sin contexto no filtra: la consola, los seeders y las migraciones que dan
     * permisos a un rol por nombre no tienen tenant, y tienen que ver los de la
     * plataforma.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant_or_platform', function (Builder $query): void {
            $tenant = self::actingTenant();

            if ($tenant === null) {
                return;
            }

            $column = $query->qualifyColumn('tenant_id');
            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $tenant));
        });

        static::creating(function (self $role): void {
            $tenant = self::actingTenant();

            if ($tenant !== null && $role->getAttribute('tenant_id') === null) {
                $role->setAttribute('tenant_id', $tenant);
            }
        });
    }

    /** ¿Los roles tienen dueño? */
    public static function perTenant(): bool
    {
        return (bool) config('mk_director.tenant.roles_per_tenant', false);
    }

    /**
     * El tenant desde el que se está actuando, o `null` si los roles no tienen
     * dueño o no hay tenant en el contexto.
     */
    public static function actingTenant(): string|int|null
    {
        return self::perTenant() ? app(TenantContext::class)->current() : null;
    }

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
