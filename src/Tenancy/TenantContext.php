<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

/**
 * TenantContext — singleton service that holds the tenant id for
 * the current request.
 *
 * Spec: MK-LAR-1.0.6 (Capa 4) + proposal M-1.
 *
 * The middleware ({@see TenantResolver}) writes to this service
 * once per request. The trait {@see HasTenantScope} reads from it
 * on every query (via a closure-registered scope so the value is
 * read fresh, not frozen at boot).
 *
 * Lifecycle:
 *  - HTTP request: middleware sets → controller reads → response.
 *  - Console / queue: no middleware runs → tenant stays null →
 *    {@see HasTenantScope} does not register the scope.
 *  - Cross-request (Octane / Swoole): the {@see \Mk\Director\MkServiceProvider}
 *    does NOT auto-flush; consumers should call flush() in a
 *    terminating callback if they use long-lived workers.
 */
class TenantContext
{
    protected string|int|null $current = null;

    /**
     * Set the active tenant id for the current request.
     */
    public function set(string|int $tenantId): void
    {
        $this->current = $tenantId;
    }

    /**
     * Get the active tenant id, or null if none is set.
     */
    public function current(): string|int|null
    {
        return $this->current;
    }

    /**
     * Forget the current tenant. Use at the end of a request in
     * long-lived workers (Octane / Swoole) to avoid leaking
     * tenant state into the next request.
     */
    public function flush(): void
    {
        $this->current = null;
    }
}
