<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy\Events;

use Mk\Director\Tenancy\TenantContext;

/**
 * Fired by {@see TenantContext::flush()} only when there
 * was an active tenant. `$previous` is the tenant that was just cleared.
 */
final class TenantFlushed
{
    public function __construct(public readonly string|int $previous) {}
}
