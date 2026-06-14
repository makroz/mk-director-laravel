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
 * The scope accepts a tenant_id (string|int|null) at instantiation.
 * `apply()` adds `WHERE tenant_id = ?` to the query.
 *
 * Design notes:
 *  - `null` tenant means "no scope applied" — the model is global
 *    for this query. This is intentional: an unauthenticated request
 *    (CLI, console) should not crash, it should see all rows.
 *  - The scope is registered with a closure in {@see HasTenantScope}
 *    so tenant_id is read fresh on every query, not frozen at boot.
 *  - The scope can be bypassed with `Model::withoutGlobalScope('tenant')`
 *    (the identifier is the class name by default in Laravel 13,
 *    but we set the alias explicitly to `tenant` for predictability).
 */
class TenantScope implements Scope
{
    public function __construct(
        protected string|int|null $tenantId = null,
    ) {}

    /**
     * Get the tenant id this scope is currently bound to.
     */
    public function tenantId(): string|int|null
    {
        return $this->tenantId;
    }

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if ($this->tenantId === null) {
            return;
        }

        $column = $model->getTenantKey() ?? 'tenant_id';

        $builder->where($column, '=', $this->tenantId);
    }
}
