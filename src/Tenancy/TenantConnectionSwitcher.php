<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Database\ConnectionResolverInterface;
use Mk\Director\Tenancy\Events\TenantFlushed;
use Mk\Director\Tenancy\Events\TenantResolved;

/**
 * Makes the tenant's connection (see {@see TenantConnectionResolver}) the
 * default one while the tenant is active, and puts the original default back
 * when the tenant is flushed. With the null resolver it never touches anything.
 */
final class TenantConnectionSwitcher
{
    /** Default connection before the first switch; null while not switched. */
    private ?string $original = null;

    public function __construct(
        private readonly TenantConnectionResolver $resolver,
        private readonly ConnectionResolverInterface $db,
    ) {}

    public function resolved(TenantResolved $event): void
    {
        $name = $this->resolver->connectionFor($event->tenantId);

        if ($name === null) {
            $this->restore();

            return;
        }

        $this->original ??= $this->db->getDefaultConnection();
        $this->db->setDefaultConnection($name);
    }

    public function flushed(TenantFlushed $event): void
    {
        $this->restore();
    }

    private function restore(): void
    {
        if ($this->original !== null) {
            $this->db->setDefaultConnection($this->original);
            $this->original = null;
        }
    }
}
