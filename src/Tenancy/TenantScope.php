<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope — Eloquent global scope that filters by `tenant_id`.
 *
 * Spec: MK-LAR-1.0.6 (Capa 4) + proposal M-1.
 *
 * Two usage modes:
 *
 *  1. Programmatic / test mode — pass a tenant id at construction:
 *     `new TenantScope(42)`. `apply()` filters by that id.
 *  2. Context-driven mode — instantiate with no arguments:
 *     `new TenantScope()`. `apply()` resolves the current tenant from
 *     the {@see TenantContext} singleton on each call. This is what
 *     {@see HasTenantScope} uses at boot; reading the context lazily
 *     keeps the scope "fresh" so the same model can be queried
 *     under different tenant contexts (CLI, queue, Octane).
 *
 * In both modes a `null` tenant (explicit or from an empty context)
 * is a no-op — the model is queried globally. This is intentional:
 * unauthenticated requests (CLI, console) should not crash, they
 * should see all rows.
 *
 * The scope can be bypassed with `Model::withoutGlobalScope('tenant')`
 * (the identifier is the alias set explicitly in HasTenantScope
 * for predictability — Laravel 13 defaults to the class name).
 */
class TenantScope implements Scope
{
    public function __construct(
        protected string|int|null $tenantId = null,
    ) {}

    /**
     * Get the tenant id this scope was bound to at construction.
     * For context-driven scopes this returns null even when an
     * active TenantContext is in play.
     */
    public function tenantId(): string|int|null
    {
        return $this->tenantId;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     * Resolution order for the effective tenant id:
     *  1. The id passed to the constructor (programmatic mode).
     *  2. The current value of the {@see TenantContext} singleton
     *     (context-driven mode, used by {@see HasTenantScope}).
     *  3. None — scope is a no-op and the query is global.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            return;
        }

        $column = $model->getTenantKey() ?? 'tenant_id';

        $builder->where($column, '=', $tenantId);
    }

    /**
     * Resolve the effective tenant id. Returns the constructor value
     * if set, otherwise consults the TenantContext singleton (when a
     * container is bound). Returns null when neither yields a value.
     */
    protected function resolveTenantId(): string|int|null
    {
        if ($this->tenantId !== null) {
            return $this->tenantId;
        }

        // Defensive: a no-app context (CLI without bootstrap, unit
        // tests that don't set a container) must not blow up.
        if (! function_exists('app')) {
            return null;
        }

        $container = app();
        if (! $container->bound(TenantContext::class)) {
            return null;
        }

        $context = $container->make(TenantContext::class);

        return $context->current();
    }
}
