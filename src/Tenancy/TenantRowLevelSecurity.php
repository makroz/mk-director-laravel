<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\ConnectionResolverInterface;
use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\Events\TenantResolved;

/**
 * Feeds the Postgres session setting that the {@see Rls} policies read.
 *
 * With `mk_director.tenant.rls` off, or on a non-pgsql default connection, it
 * does nothing at all (no query). It runs after {@see TenantConnectionSwitcher},
 * so the setting lands on the connection the tenant's queries will use.
 *
 * The setting is session-level (`is_local = false`): it does not survive
 * pgbouncer in transaction mode (docs/guides/MULTI_TENANT.md).
 */
final class TenantRowLevelSecurity
{
    public function __construct(private readonly ConnectionResolverInterface $db) {}

    public function resolved(TenantResolved $event): void
    {
        $this->set((string) $event->tenantId);
    }

    public function flushed(TenantFlushed $event): void
    {
        $this->set('');
    }

    private function set(string $value): void
    {
        if (! (bool) config('mk_director.tenant.rls', false)) {
            return;
        }

        $connection = $this->db->connection();

        if ($connection->getDriverName() === 'pgsql') {
            $connection->select("select set_config('app.tenant_id', ?, false)", [$value]);
        }
    }
}
