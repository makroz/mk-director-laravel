<?php

declare(strict_types=1);

namespace Mk\Director\Tenancy;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Migration helper for Postgres Row Level Security (`mk_director.tenant.rls`).
 *
 * `enable()` turns RLS ON and FORCES it (the table owner is filtered too) and
 * installs ONE policy, `mk_tenant_isolation`, for every command: a row is
 * visible/writable only when its tenant column equals the session setting
 * `app.tenant_id`, which {@see TenantRowLevelSecurity} sets on every tenant
 * change. No setting = no rows, and inserts are rejected.
 *
 * The setting is cast to the column's real type (uuid, bigint, varchar…), not
 * the column to text, so the index on the column stays usable.
 *
 * There is no bypass by setting: the platform reads across tenants through a
 * database role with BYPASSRLS on its own connection (docs/guides/MULTI_TENANT.md).
 * Superusers always bypass RLS.
 */
final class Rls
{
    public const POLICY = 'mk_tenant_isolation';

    public static function enable(string $table, string $column = 'tenant_id'): void
    {
        self::assertPgsql();

        $t = self::quote($table);
        $c = self::quote($column);
        $type = DB::selectOne(
            'select format_type(atttypid, atttypmod) as type from pg_attribute where attrelid = ?::regclass and attname = ? and not attisdropped',
            [$t, $column],
        )?->type ?? throw new RuntimeException("Column {$table}.{$column} does not exist.");

        $check = "{$c} = NULLIF(current_setting('app.tenant_id', true), '')::{$type}";

        DB::statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$t}");
        DB::statement('CREATE POLICY '.self::POLICY." ON {$t} FOR ALL USING ({$check}) WITH CHECK ({$check})");
    }

    public static function disable(string $table): void
    {
        self::assertPgsql();

        $t = self::quote($table);

        DB::statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$t}");
        DB::statement("ALTER TABLE {$t} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$t} DISABLE ROW LEVEL SECURITY");
    }

    private static function assertPgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Row Level Security needs a pgsql connection.');
        }
    }

    private static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
