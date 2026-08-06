<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin;

/**
 * HasTenantScope — model trait that auto-registers {@see TenantScope}
 * on the model's `booted()` lifecycle hook.
 *
 * Spec: MK-LAR-1.0.6 (Capa 4) + proposal M-1 + audit R2-006.
 *
 * Usage in a concrete model:
 * ```php
 * use Mk\Director\Tenancy\HasTenantScope;
 *
 * class Survey extends Model
 * {
 *     use HasTenantScope;
 *
 *     // R2-006: explicit per-model opt-in. Default is FALSE so existing
 *     // single-tenant apps are unaffected.
 *     protected static bool $usesTenant = true;
 *
 *     // Optional: override the column name (default 'tenant_id').
 *     public function getTenantKey(): string
 *     {
 *         return 'tenant_id';
 *     }
 * }
 * ```
 *
 * 🔴 Ese ejemplo es EJECUTABLE, y hasta hoy no lo era: el trait declaraba
 * `protected static bool $usesTenant = false` y redeclararla en el modelo con
 * otro valor inicial es un fatal de composición de traits ("the definition
 * differs and is considered incompatible"). El trait ya no la declara — la
 * lee con `property_exists` — así que el camino documentado compila. Lo pinea
 * `tests/Feature/Tenancy/HasTenantScopeDocumentedOptInTest.php`, que EXTRAE la
 * línea de este mismo docblock y la corre en un proceso aparte.
 *
 * Opt-in semantics (per ADR-003 + R2-006):
 *  - The scope is only registered if BOTH conditions hold:
 *      (a) el modelo optó: declaró `protected static bool $usesTenant = true`
 *          o llamó a {@see setTenantEnabled()}. Ver {@see isTenantEnabled()}.
 *      (b) `mk_director.tenant.enabled` is true in config.
 *    When either is false, the trait is a no-op.
 *  - The scope is also a no-op if no tenant id is resolved from the
 *    current {@see TenantContext}. This keeps console / queue jobs
 *    from accidentally hiding all rows.
 *  - The scope can be bypassed per-query with:
 *    `Model::withoutGlobalScope('tenant')`.
 *  - The actual filter logic lives in {@see TenantScope}; this trait
 *    only handles the opt-in wiring and column override. This keeps
 *    the scope itself reusable from non-model call sites (custom
 *    queries, reports, etc.).
 */
trait HasTenantScope
{
    /**
     * Override en runtime del opt-in, por clase compositora.
     * `null` = "nadie lo tocó en runtime, mirá lo que declaró el modelo".
     *
     * 🔴 POR QUÉ EL TRAIT YA NO DECLARA `$usesTenant`.
     *
     * Lo declaraba (`protected static bool $usesTenant = false`) y el docblock
     * de arriba pedía redeclararlo en el modelo con `= true`. Eso es un FATAL
     * de PHP: cuando la clase compositora y el trait declaran la misma
     * propiedad con distinto valor inicial, la composición no compila
     * ("the definition differs and is considered incompatible"). O sea que el
     * único camino documentado para prender el aislamiento por modelo era
     * imposible de seguir — y `mk:update` imprimía el mismo consejo.
     *
     * Al no declararla el trait, la propiedad del modelo deja de chocar con
     * nada y el ejemplo de la documentación compila. Los modelos que no la
     * declaran siguen en `false`, que es el default opt-in de R2-006.
     */
    protected static ?bool $tenantEnabledOverride = null;

    /**
     * Boot the trait.
     *
     * Registers the {@see TenantScope} as a global scope on this
     * model. The scope is instantiated without a tenant id so it
     * resolves the current tenant from the {@see TenantContext}
     * singleton on every query — this matters because the same
     * model can be queried in different requests (CLI, queue) and
     * the tenant context can change mid-process (Octane).
     */
    public static function bootHasTenantScope(): void
    {
        if (! static::isTenantEnabled()) {
            return;
        }

        if (! self::tenantEnabled()) {
            return;
        }

        static::addGlobalScope('tenant', new TenantScope);
    }

    /**
     * Return the column name used to store the tenant id on this
     * model. Default: 'tenant_id'. Override in the model to use
     * a different column (e.g. 'org_id' for org-based isolation).
     */
    public function getTenantKey(): string
    {
        return 'tenant_id';
    }

    /**
     * Whether this concrete model has opted in to tenant scoping.
     *
     * R-PKG-022 BUG-NEW-32 + HALLAZGO-NEW-05: previously, external
     * callers (e.g. {@see MkMultiTenantPlugin})
     * accessed `$usesTenant` via ReflectionProperty + setAccessible(),
     * which is deprecated since PHP 8.1 and emits warnings since PHP 8.5.
     *
     * Solución de raíz: exponer el flag como accessor público. Es:
     *  - API limpia (no reflection tricks)
     *  - BC-safe (agregar método público es no-op para consumers)
     *  - Performance: O(1) sin overhead de reflection
     *
     * Implementación: late static binding, con dos fuentes en este orden:
     *  1. El override de runtime que escribe {@see setTenantEnabled()}.
     *  2. La propiedad `protected static bool $usesTenant` que declara el
     *     modelo — el camino documentado. `property_exists()` porque el trait
     *     ya NO la declara: si la declarara, redeclararla en el modelo sería un
     *     fatal de composición (ver {@see $tenantEnabledOverride}).
     * Si no hay ninguna de las dos, el default es false (opt-in, R2-006).
     */
    public static function isTenantEnabled(): bool
    {
        if (static::$tenantEnabledOverride !== null) {
            return static::$tenantEnabledOverride;
        }

        if (property_exists(static::class, 'usesTenant')) {
            return (bool) static::$usesTenant;
        }

        return false;
    }

    /**
     * Set the per-model opt-in flag at runtime.
     *
     * R-PKG-022: complemento de {@see isTenantEnabled()}. Permite toggle
     * del flag sin reflection, útil para:
     *  - Testing: setup de fixtures con flag on/off por test.
     *  - Runtime: consumers que necesitan deshabilitar tenancy en
     *    contextos específicos (CLI commands, queue workers, reports).
     *
     * BC-safe (agregar método público es no-op).
     *
     * Escribe en el override de runtime y no en `$usesTenant`: el modelo puede
     * no declarar esa propiedad, y PHP no permite agregar estáticas en runtime.
     * El override tiene PRECEDENCIA sobre lo declarado — que es lo que se espera
     * de un setter.
     *
     * ⚠️ Una estática vive en la clase que COMPONE el trait. Si el trait está en
     * una clase base y los modelos son sus hijas, llamar a este setter en una
     * hija lo cambia para TODAS sus hermanas (escriben la misma estática de la
     * base). Es el mismo comportamiento que tenía `$usesTenant`, no una
     * regresión — pero para prender el opt-in modelo por modelo el camino es la
     * propiedad declarada, no este setter.
     */
    public static function setTenantEnabled(bool $enabled): void
    {
        static::$tenantEnabledOverride = $enabled;
    }

    /**
     * Whether the tenant feature is enabled in config. Tied to
     * the boot guard so the trait is a no-op when opted out.
     */
    protected static function tenantEnabled(): bool
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return false;
        }

        return (bool) config('mk_director.tenant.enabled', false);
    }
}
