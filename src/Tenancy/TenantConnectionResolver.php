<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

/**
 * Maps a tenant to the name of the database connection that holds its data.
 *
 * Return null to keep the default connection (the single-database behavior).
 * Replace the implementation with `mk_director.tenant.connection_resolver`.
 */
interface TenantConnectionResolver
{
    public function connectionFor(string|int $tenantId): ?string;
}
