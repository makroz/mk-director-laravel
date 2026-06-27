<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;

/**
 * MkPivot — base para pivots MME-polimórficas del paquete.
 *
 * R-PKG-020 HALLAZGO-NEW-01: cuando una pivot (`role_user`, `ability_user`)
 * tiene la columna `user_type` (MME-polimórfico, R-MK-001), las mutaciones
 * nativas de Eloquent (`attach`, `detach`, `sync`, `syncWithoutDetaching`,
 * `toggle`, `updateExistingPivot`) NO setean `user_type` automáticamente.
 * Antes, esto se mitigaba en métodos helper (`assignRole`, `syncRoles`,
 * `giveAbilityTo`, `syncDirectAbilities`) que pasan `pivotExtras()` al
 * payload, pero un consumer que use `roles()->attach([$id])` directo
 * seguía rompiendo con `NOT NULL constraint failed: role_user.user_type`.
 *
 * Solución de raíz: extender `Pivot` con un listener `creating` que setea
 * `user_type = get_class($pivot->pivotParent)` (el FQCN del modelo concreto,
 * ej: `App\Modules\Admin\Models\Admin`) automáticamente, MIENTRAS:
 *
 *  - La pivot tiene la columna `user_type` (chequeo via `Schema::hasColumn()`,
 *    cacheado en memoria del proceso).
 *  - El consumer NO pasó `user_type` explícitamente (respetar override).
 *  - Hay un parent asociado (caso típico de `attach()` directo).
 *
 * Si la pivot NO tiene `user_type` (consumer legacy, no MME), el listener
 * es no-op. BC-safe total: el comportamiento default es idéntico al previo.
 *
 * Aplica vía `->using(MkRoleUserPivot::class)` y `->using(MkAbilityUserPivot::class)`
 * en las relaciones `roles()` y `directAbilities()`. Si el consumer override
 * estas relaciones con su propio `->using(...)`, su pivot gana (BC preservada).
 *
 * @see HasRoles::roles()
 * @see HasAbilities::directAbilities()
 */
abstract class MkPivot extends Pivot
{
    /**
     * Cache de `Schema::hasColumn($table, 'user_type')` por tabla.
     * Evitamos un query a information_schema por cada attach/detach.
     *
     * @var array<string, bool>|null
     */
    private static array $userTypeColumnCache = [];

    /**
     * Boot del modelo. Registra el listener `creating` que auto-setea
     * `user_type` cuando la pivot lo requiere.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $pivot): void {
            // 1. Si el consumer ya pasó `user_type` explícitamente, respetar.
            if ($pivot->user_type !== null) {
                return;
            }

            // 2. Si la pivot NO tiene columna `user_type`, no hacer nada
            //    (consumer legacy, comportamiento idéntico al previo).
            $table = $pivot->getTable();

            if (! isset(self::$userTypeColumnCache[$table])) {
                try {
                    self::$userTypeColumnCache[$table] = Schema::hasColumn($table, 'user_type');
                } catch (\Throwable) {
                    // Tabla no existe todavía (consumer no migró), no forzar.
                    self::$userTypeColumnCache[$table] = false;
                }
            }

            if (! self::$userTypeColumnCache[$table]) {
                return;
            }

            // 3. Si hay parent (caso típico de attach/sync directo), setear
            //    `user_type` con el FQCN del modelo concreto (MME-polimórfico).
            //    `getMorphClass()` retorna el FQCN a menos que el modelo override
            //    el método (caso raro). Fallback a get_class() si getMorphClass()
            //    no está disponible.
            if ($pivot->pivotParent !== null) {
                $pivot->user_type = $pivot->pivotParent->getMorphClass();
            }
        });
    }

    /**
     * Helper para tests que necesitan limpiar el cache entre casos.
     * No es parte del API público; usar solo desde test code.
     *
     * @internal
     */
    public static function clearUserTypeCache(): void
    {
        self::$userTypeColumnCache = [];
    }
}
