<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * TenantScope — Eloquent global scope that filters by `tenant_id`.
 *
 * Spec: MK-LAR-1.0.6 (Capa 4) + proposal M-1 + LAR-11 fail-closed opt-in.
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
 * Default behavior (no fail-closed): a `null` tenant (explicit or from
 * an empty context) is a no-op — the model is queried globally. This
 * is intentional for unauthenticated requests (CLI, console) which
 * should not crash; they should see all rows.
 *
 * LAR-11 — fail-closed opt-in: when `tenant.fail_closed` is true in
 * config AND the context is null AND the model opted in via
 * HasTenantScope, the scope injects an impossible predicate
 * (`where tenant_id = -1`) instead of returning globally. This closes
 * the IDOR loophole where a misconfigured TenantResolver (strict=false)
 * would let a request slip through without tenant context, and the
 * scope would then leak rows across tenants. CLI/queue jobs must
 * either pinear `fail_closed=false` explicitly OR set the tenant
 * context manually before the query.
 *
 * The scope can be bypassed with `Model::withoutGlobalScope('tenant')`
 * (the identifier is the alias set explicitly in HasTenantScope
 * for predictability — Laravel 13 defaults to the class name).
 */
class TenantScope implements Scope
{
    /**
     * Sentinel tenant id used when fail-closed mode is on and the
     * resolved tenant is null. `tenant_id = -1` can never match a real
     * auto-increment/UUID row in any reasonable schema, so the query
     * returns zero rows. This is the contract — don't change without
     * updating tests/Unit/Tenancy/TenantStrictFailClosedTest.php.
     */
    public const FAIL_CLOSED_SENTINEL = -1;

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
     *  3. None — scope is either a no-op (default) or fail-closed
     *     (returns 0 rows via an impossible predicate, when the
     *     `tenant.fail_closed` config flag is true).
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = $this->resolveTenantId();

        if ($tenantId === null) {
            // LAR-11: fail-closed opt-in. When configured, an empty
            // tenant context does NOT mean "show all rows" — it means
            // "deny the query" (return 0 rows). This protects against
            // IDOR when TenantResolver misconfiguration leaks through.
            if ($this->isFailClosedEnabled()) {
                $column = $model->getTenantKey() ?? 'tenant_id';
                $builder->where($column, '=', self::FAIL_CLOSED_SENTINEL);
            }
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

    /**
     * LAR-11: read the fail-closed flag via filter_var so 'false' in
     * config/.env correctly evaluates to false (PHP (bool) footgun).
     */
    protected function isFailClosedEnabled(): bool
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return false;
        }

        return filter_var(
            config('mk_director.tenant.fail_closed', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
