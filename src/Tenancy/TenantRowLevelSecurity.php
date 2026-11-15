<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Events\TransactionRolledBack;
use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\Events\TenantResolved;

/**
 * Feeds the Postgres session setting that the {@see Rls} policies read.
 *
 * With `mk_director.tenant.rls` off, or on a non-pgsql connection, it does
 * nothing at all (no query). It runs after {@see TenantConnectionSwitcher},
 * so the setting lands on the connection the tenant's queries will use.
 *
 * It remembers which connection holds the setting: when the switcher moves
 * the default connection away (flush, or `run()` restoring a tenant on
 * another connection), the old connection is cleared too, so it never keeps
 * a tenant nobody has active.
 *
 * `set_config(..., false)` is undone by a transaction rollback, so after
 * every rollback the setting is written again from {@see TenantContext}.
 *
 * The setting is session-level: it does not survive pgbouncer in transaction
 * mode (docs/guides/MULTI_TENANT.md).
 */
final class TenantRowLevelSecurity
{
    private ?string $applied = null;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly TenantContext $context,
    ) {}

    public function resolved(TenantResolved $event): void
    {
        $name = $this->db->getDefaultConnection();

        if ($this->applied !== null && $this->applied !== $name) {
            $this->write($this->applied, '');
        }

        $this->applied = $this->write($name, (string) $event->tenantId) ? $name : null;
    }

    public function flushed(TenantFlushed $event): void
    {
        $this->write($this->applied ?? $this->db->getDefaultConnection(), '');

        if ($this->applied !== null && $this->applied !== $this->db->getDefaultConnection()) {
            $this->write($this->db->getDefaultConnection(), '');
        }

        $this->applied = null;
    }

    public function rolledBack(TransactionRolledBack $event): void
    {
        $tenant = $this->context->current();
        $name = $event->connectionName;

        if ($name === $this->applied || ($tenant === null && $name === $this->db->getDefaultConnection())) {
            $this->write($name, $tenant === null ? '' : (string) $tenant);
        }
    }

    /** Returns whether the setting was written (rls on and a pgsql connection). */
    private function write(string $name, string $value): bool
    {
        if (! (bool) config('mk_director.tenant.rls', false)) {
            return false;
        }

        $connection = $this->db->connection($name);

        if ($connection->getDriverName() !== 'pgsql') {
            return false;
        }

        $connection->select("select set_config('app.tenant_id', ?, false)", [$value]);

        return true;
    }
}
