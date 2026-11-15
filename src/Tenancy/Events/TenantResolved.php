<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy\Events;

use Mk\Director\Tenancy\TenantContext;

/**
 * Fired by {@see TenantContext::set()} only when the
 * active tenant actually changes. `$previous` is the tenant that was active
 * before (null when there was none).
 */
final class TenantResolved
{
    public function __construct(
        public readonly string|int $tenantId,
        public readonly string|int|null $previous,
    ) {}
}
