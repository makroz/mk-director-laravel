<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Contracts\Events\Dispatcher;
use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\Events\TenantResolved;

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
 *    flushes the context in a `terminating` callback at the end of each
 *    request, so a long-lived worker does not carry the tenant over.
 *  - Queue: with `mk_director.tenant.queue_propagation` the tenant travels in
 *    the job payload ({@see TenantQueuePropagation}); otherwise jobs run
 *    without tenant unless they set it themselves (use {@see run()}).
 *
 * Changes are announced with {@see TenantResolved} / {@see TenantFlushed}
 * (only when the value really changes), which is what switches the
 * connection ({@see TenantConnectionSwitcher}) and what other seams hook into.
 */
class TenantContext
{
    protected string|int|null $current = null;

    /** Optional so the context can still be built by hand (`new TenantContext()`). */
    public function __construct(protected ?Dispatcher $events = null) {}

    /**
     * Set the active tenant id for the current request.
     */
    public function set(string|int $tenantId): void
    {
        if ($this->current === $tenantId) {
            return;
        }

        $previous = $this->current;
        $this->current = $tenantId;
        $this->events?->dispatch(new TenantResolved($tenantId, $previous));
    }

    /**
     * Run `$fn` with `$tenantId` active and ALWAYS put back the previous
     * tenant (or none), also when `$fn` throws. The single place to switch
     * tenant temporarily.
     */
    public function run(string|int $tenantId, callable $fn): mixed
    {
        $previous = $this->current;

        $this->set($tenantId);

        try {
            return $fn();
        } finally {
            $previous === null ? $this->flush() : $this->set($previous);
        }
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
        if ($this->current === null) {
            return;
        }

        $previous = $this->current;
        $this->current = null;
        $this->events?->dispatch(new TenantFlushed($previous));
    }
}
