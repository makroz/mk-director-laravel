<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Enums;

/**
 * ScopeStatus — enum canónico (SSoT) del paquete mk-director. INT-BACKED.
 *
 * ============================================================
 *  Historia: R-PKG-047 D4 y su corrección (2026-07-19)
 * ============================================================
 *
 * Antes de R-PKG-047, cada scope scaffoldeado generaba su propio
 * `{Scope}Status` int-backed **configurable via `--status-values`**, con
 * valores 1..N asignados en orden de declaración. Eso sí era un problema
 * real: el `1` del Admin no significaba lo mismo que el `1` del Member.
 *
 * R-PKG-047 D4 arregló ese drift, pero en el mismo commit (af62e81) viajaron
 * DOS decisiones independientes empaquetadas como una:
 *
 *   1. Fijar 4 estados canónicos en un SSoT y eliminar `--status-values`.
 *      → CORRECTA y necesaria. Es lo único que el drift exigía.
 *   2. Cambiar el backing type de int a string.
 *      → Se subió de colado. El argumento del drift NO la justifica: con un
 *        único enum canónico, `Active=1..Pending=4` tiene drift cero porque
 *        no hay un segundo enum con el cual desincronizarse.
 *
 * La revisión del 2026-07-19 no encontró NINGÚN bug que motivara (2): todas
 * las menciones de "int-backed" en el paquete son descriptivas o notas de BC,
 * ninguna reporta un defecto. Y el cambio, que decía eliminar drift, lo creó
 * un nivel más arriba: la épica S6.5 de Condaty migró todo `char → numérico`,
 * así que el paquete quedó apuntando al lado contrario del otro sistema de
 * la agencia.
 *
 * Por eso este enum vuelve a int-backed, conservando (1): los 4 estados
 * siguen pineados como SSoT y `--status-values` sigue eliminado.
 *
 * **NO revertir a string sin leer esto primero.** Si aparece una razón
 * genuina, que quede documentada acá con el caso concreto.
 *
 * ============================================================
 *  Convención de valores
 * ============================================================
 *
 * Los valores arrancan en **1, no en 0** (regla de la agencia, FEEDBACK4).
 * El 0 es indistinguible de `null`/`false` en un montón de bordes — casts
 * flojos, `empty()`, query strings — y esa ambigüedad ya nos mordió antes.
 *
 * ============================================================
 *  Compat / BC
 * ============================================================
 *
 * - **Consumers post-D4 con la columna `status` string** (es el caso de
 *   RETO): `php artisan mk:migrate-status-to-int {Scope}` convierte la
 *   columna y la data in-place. Usa {@see self::fromLegacyString()}.
 * - **Cross-stack**: `values()` vuelve a devolver `int[]`. Los frontends
 *   (`@makroz/web` / `@makroz/mobile`) comparan contra números; si alguno
 *   pineaba el string, hay que regenerar.
 * - **Drift footgun**: el modelo concreto del scope pine el cast
 *   `protected $casts = ['status' => ScopeStatus::class]`. Si se olvida,
 *   el cast retorna null y `userHasValidStatus()` cae al fallback
 *   `is_active` boolean.
 */
enum ScopeStatus: int
{
    case Active = 1;
    case Inactive = 2;
    case Blocked = 3;
    case Pending = 4;

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
     * Convención de la agencia: `Active` (login funcional por default).
     * Si el consumer quiere `Pending` (workflow de aprobación admin),
     * override este método en el thin wrapper per-scope.
     */
    public static function default(): self
    {
        return self::Active;
    }

    /**
     * Lista de values int del enum (en orden de declaración canónico).
     *
     * Útil para la regla de validación, el dropdown de UI y los filtros de
     * API. Shape preservada cross-stack: los frontends consumen este array
     * directamente sin introspection PHP-side.
     *
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    /**
     * Mapa `value => label` listo para poblar un select.
     *
     * Existe para que el consumer no tenga que reconstruirlo a mano y se le
     * escape un estado cuando agreguemos uno.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Traduce el value STRING de la era D4 al case int-backed actual.
     *
     * Lo usa `mk:migrate-status-to-int` para convertir la data de consumers
     * que alcanzaron a migrar a string. Acepta también 'suspended', que fue
     * el nombre del tercer estado antes de que R-PKG-050 (F10-B12) lo
     * renombrara a 'blocked': hay bases con ese valor escrito.
     */
    public static function fromLegacyString(string $legacy): self
    {
        return match (strtolower(trim($legacy))) {
            'active' => self::Active,
            'inactive' => self::Inactive,
            'blocked', 'suspended' => self::Blocked,
            'pending' => self::Pending,
            default => throw new \ValueError("Status legacy desconocido: {$legacy}"),
        };
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
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
            self::Blocked => 'Bloqueado',
            self::Pending => 'Pendiente',
        };
    }
}
