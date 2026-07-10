<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Enums;

/**
 * ScopeStatus — enum canónico (SSoT) del paquete mk-director.
 *
 * ============================================================
 *  Patrón R-PKG-038 + R-PKG-047 D4
 * ============================================================
 *
 * Antes de R-PKG-047, cada scope scaffoldeado generaba su propio
 * `{Scope}Status` enum (`int`-backed, configurable via `--status-values`,
 * con valores 1..N asignados en orden de declaración). Drift entre scopes
 * + flag extra requerida + fail-fast contra typos = fricción operacional.
 *
 * Post-R-PKG-047 D4, el paquete expone UN enum canónico `ScopeStatus`
 * (`string`-backed, 4 estados pineados por la agencia) que los scopes
 * scaffoldeados reusan sin override. Los 4 estados cubren los casos
 * comunes de negocio:
 *
 *   - **Active**:    usuario con permiso de autenticarse normalmente.
 *   - **Inactive**:  usuario explícitamente dado de baja (no se loguea).
 *   - **Blocked**: usuario bloqueado temporalmente por admin (ban).
 *   - **Pending**:   usuario creado pero pendiente de aprobación / verify.
 *
 * El scaffolder `mk:make:auth-user {Scope}` pine un **thin wrapper**
 * (`{Scope}Status` que extiende `ScopeStatus`) para preservar BC con
 * consumers que pinean `use App\Modules\Admin\Enums\AdminStatus;` directo.
 * El thin wrapper solo override `values()` y `default()` (que mantienen
 * los 4 estados canónicos via delegation a ScopeStatus).
 *
 * ============================================================
 *  Compat / BC
 * ============================================================
 *
 * - **Pre-D4 `{Scope}Status` int-backed**: NO hay migrador automático
 *   incluido en este commit. El helper `migrateIsActiveToStatus()` (per
 *   `php artisan mk:migrate-is-active Admin`) maneja la transición
 *   `is_active` boolean → `status` enum para scopes pre-D4.
 *
 * - **Scope sin `ScopeStatus` cast (pre-D4)**: BaseAuthController::userHasValidStatus()
 *   fallback a `is_active` boolean via `Schema::hasColumn()` (BC).
 *
 * - **Scope que ya pineaba `{Scope}Status` int-backed**: el thin wrapper
 *   per-scope pineado post-D4 es compatible si el consumer NO dependía
 *   del valor numérico. Si dependía (e.g. `match($status->value) { 1 => ... }`),
 *   el consumer debe migrar a `match($status) { AdminStatus::Active => ... }`
 *   — BC break aceptable per R-G-033 (RETO regenera + clean rebuild).
 *
 * - **Hardcoded `is_active` boolean en query custom del consumer**: el
 *   helper `migrateIsActiveToStatus()` convierte la columna in-place
 *   (conserva data, cambia tipo). Después el consumer debe migrar queries
 *   de `where('is_active', true)` a `where('status', ScopeStatus::Active)`.
 *
 * - **Drift footgun prevention**: el modelo concreto del scope (e.g.
 *   `Admin extends AuthUser`) pine el cast `protected $casts = [
 *   'status' => ScopeStatus::class]`. Si se olvida, el cast retorna null
 *   y `userHasValidStatus()` fallback a `is_active` boolean (BC fallback).
 */
enum ScopeStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Blocked = 'blocked';
    case Pending = 'pending';

    /**
     * ¿Este status permite al usuario autenticarse?
     *
     * Por convención de la agencia: solo `Active` puede auth. Los demás
     * estados (`Inactive`, `Blocked`, `Pending`) bloquean en /login,
     * /refresh, /me — antes de emitir tokens.
     *
     * Si el consumer quiere agregar un estado `canAuthenticate === true`
     * (e.g. un estado `Guest` con login de solo lectura), override
     * este método en el thin wrapper per-scope `{Scope}Status`.
     */
    public function canAuthenticate(): bool
    {
        return $this === self::Active;
    }

    /**
     * Status por default al crear un user nuevo.
     *
     * Convention de la agencia: `Active` (login funcional por default).
     * Si el consumer quiere `Pending` (workflow de aprobación admin),
     * override este método en el thin wrapper per-scope.
     */
    public static function default(): self
    {
        return self::Active;
    }

    /**
     * Lista de values string del enum (en orden de declaración canónico).
     *
     * Útil para migration `enum` column + dropdown UI select + API filter.
     * Shape preservada cross-stack: `@makroz/web` `@makroz/mobile` pueden
     * consumir este array directamente como `ScopeStatus.values()` sin
     * introspection PHP-side.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Label human-readable para UI / responses (español default).
     *
     * Pineable en `__extraData.status_label` del /me response si el
     * consumer quiere exponerlo (override via `customizeMePayload()`).
     */
    public function label(): string
    {
        return match ($this) {
            self::Active    => 'Activo',
            self::Inactive  => 'Inactivo',
            self::Blocked => 'Bloqueado',
            self::Pending   => 'Pendiente',
        };
    }
}
