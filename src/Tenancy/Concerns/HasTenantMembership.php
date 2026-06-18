<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy\Concerns;

/**
 * HasTenantMembership — single source of truth for "what is this user's
 * tenant id?" lookups.
 *
 * Spec: MK-LAR-1.0.6 + audit-2026-06-17-R2-004.
 *
 * Why this trait exists:
 *  - Before the 4R audit, TenantResolver, MkMultiTenantPlugin, and any
 *    consumer code each read the tenant id directly from `$user->client_id`
 *    with their own null/empty handling. Result: inconsistent behavior and
 *    a `'00000000-...' (string) vs 0 (int)` strict-comparison bug in
 *    MkMultiTenantPlugin (R2-018).
 *  - Centralizing the read here means future consumers opt in by adding
 *    the trait; the column name is overridable per-model for orgs that
 *    use `org_id`, `company_id`, etc.
 *
 * Resolution rules:
 *  - `getTenantId()` returns the value of the configured tenant column.
 *  - `null` is returned when the attribute is missing, empty string, or
 *    null — so consumers can write `if ($user->getTenantId() !== null)`.
 *  - `getTenantColumn()` defaults to `'client_id'` (the historical default
 *    in Mk-Director). Override per-model for non-client_id schemas.
 *
 * Pairing with {@see \Mk\Director\Tenancy\TenantResolver}: when the
 * resolver reads a tenant from the request header, it must compare against
 * `$user->getTenantId()`, not `$user->client_id` directly.
 */
trait HasTenantMembership
{
    /**
     * Return the tenant id stored on this model, or null when missing.
     *
     * String semantics: empty string and null both normalize to null so
     * the caller does not have to distinguish "absent" from "empty".
     */
    public function getTenantId(): string|int|null
    {
        $column = $this->getTenantColumn();

        $value = $this->getAttribute($column);

        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Name of the column on this model that holds the tenant id.
     *
     * Override in the model to use a non-default column. Default
     * 'client_id' preserves historical Mk-Director behavior.
     */
    public function getTenantColumn(): string
    {
        return 'client_id';
    }
}