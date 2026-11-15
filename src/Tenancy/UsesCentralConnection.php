<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

/**
 * For Eloquent models that belong to the platform, not to a tenant (the
 * abilities catalog, the help manuals): they read from
 * `mk_director.tenant.central_connection`, whatever tenant connection is the
 * default at that moment. With it unset (null) nothing changes.
 *
 * The package models that use it are listed in docs/guides/MULTI_TENANT.md.
 */
trait UsesCentralConnection
{
    public function getConnectionName()
    {
        return config('mk_director.tenant.central_connection') ?: parent::getConnectionName();
    }
}
