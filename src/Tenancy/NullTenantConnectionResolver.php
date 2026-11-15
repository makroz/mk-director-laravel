<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

/** Default resolver: every tenant uses the default connection. */
final class NullTenantConnectionResolver implements TenantConnectionResolver
{
    public function connectionFor(string|int $tenantId): ?string
    {
        return null;
    }
}
