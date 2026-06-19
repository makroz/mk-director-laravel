<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
 * Opt-in semantics (per ADR-003 + R2-006):
 *  - The scope is only registered if BOTH conditions hold:
 *      (a) `static::$usesTenant` is true on the concrete model class.
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
     * R2-006: per-model opt-in flag. Concrete models set this to true
     * to participate in tenant scoping. Defaults to FALSE — adding the
     * trait alone does not enable scoping; the model must explicitly
     * opt in by overriding this property.
     *
     * Example:
     *   class Survey extends Model {
     *       use HasTenantScope;
     *       protected static bool $usesTenant = true;
     *   }
     */
    protected static bool $usesTenant = false;

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
        if (! static::$usesTenant) {
            return;
        }

        if (! self::tenantEnabled()) {
            return;
        }

        static::addGlobalScope('tenant', new TenantScope());
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
