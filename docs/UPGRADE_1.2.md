# UPGRADE GUIDE: makroz/director-laravel 1.1.x → 1.2.x

This guide documents the breaking changes introduced in v1.2 and the
data-migration steps required to upgrade an existing install. Read
**every section** before running migrations; some changes are
irreversible once committed.

> **Spec**: 4R audit follow-up (closes R3-013)
> **Source**: `openspec/changes/2026-06-17-laravel-4r-fixes/`

---

## 1. Breaking changes summary

| # | Change | Reversible? |
|---|--------|-------------|
| 1 | `auth_users.id` BIGINT → UUID (commit 98a11a3) | **NO** — existing rows must be migrated with a UUID primary key |
| 2 | `HasTenantScope` is now opt-in (`$usesTenant = true`) | YES — set the flag in each consuming model |
| 3 | `MkAbility` rejects empty ability lists (R2-003) | YES — fix route definitions, no data change |
| 4 | `ListManager::applyFilterOperator` throws on unknown operator (R3-007) | YES — fix filter inputs |

> Items #1 is the only one that requires a data migration. Items #2-4
> are code-only changes that fail loudly if a consumer was relying on
> the previous (looser) behavior.

---

## 2. Pre-flight checklist

Before running the migration script:

- [ ] **Backup your database** (`mysqldump` / `pg_dump`). The script is
  one-way.
- [ ] All application servers are stopped or in maintenance mode.
- [ ] You have rolled out the v1.2 package code (composer update) on the
  target environment. Migrations need the package's PHP code to be
  loaded.
- [ ] You have at least 2x the current `auth_users` table size in free
  disk space. The script copies the table before dropping the old one.

---

## 3. UUID primary key migration

### What the script does

`bin/migrate-1.1-to-1.2.php` performs a 6-step migration:

1. Verifies the package is at v1.2 (checks `composer.lock`).
2. Adds a temporary `id_uuid CHAR(36)` column if missing.
3. Generates a UUIDv4 for every existing row using MySQL's
   `UUID()` (or `gen_random_uuid()` on PostgreSQL).
4. Adds a `client_id` (string) tenant column if missing and your
   config has `mk_director.tenant.enabled = true`.
5. Drops the old BIGINT `id` column and renames `id_uuid` → `id`.
6. Adds a primary key constraint on the new `id` column.

The script is **idempotent**: re-running it on an already-migrated
table is a no-op.

### Running the script

```bash
# 1. Preview the migration (no writes)
php bin/migrate-1.1-to-1.2.php --dry-run

# 2. Apply the migration
php bin/migrate-1.1-to-1.2.php

# 3. Verify
php artisan migrate:status
php artisan tinker
>>> \App\Models\Admin::first()
```

The script accepts `--connection=<name>` for non-default database
connections and `--chunk=1000` to tune the batch size on large
tables. Run with `--help` for the full flag list.

### Rollback

There is no automatic rollback because the BIGINT id is destroyed
in step 5. To roll back:

1. Restore the database from your pre-migration backup.
2. Re-deploy v1.1.x of the package.

This is the reason the pre-flight checklist is non-negotiable.

---

## 4. Per-model tenant opt-in

The `HasTenantScope` trait used to register the global scope on every
model that used it. As of v1.2, you must explicitly opt in by
declaring:

```php
class Survey extends Model
{
    use HasTenantScope;

    // R2-006: explicit per-model opt-in. Default is false.
    protected static bool $usesTenant = true;
}
```

If you previously relied on the implicit behavior, add the
`$usesTenant = true;` declaration to every model that should be
tenant-scoped. Models without the declaration are no longer
scoped, which is the safer default for multi-tenant Laravel apps.

To find every model that previously used the trait, run:

```bash
grep -rl "use HasTenantScope" app/Modules/
```

Then decide which ones should opt in.

---

## 5. MkAbility empty-abilities rejection

`Route::middleware(['mk.ability:'])` (with an empty string) used to
silently pass every request through. As of v1.2, this returns
HTTP 500 + `error: ERR_MIDDLEWARE_MISCONFIGURED`. Audit any routes
that registered the middleware with an empty parameter and either
remove the middleware or pass at least one ability.

To find suspect routes:

```bash
grep -rn "mk.ability:" routes/
```

---

## 6. ListManager unknown-operator rejection

`filter[status][op]=REGEXP` (or any operator not in the documented
9: `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `like`, `in`, `not_in`)
used to silently fall back to `=`. As of v1.2, this throws
`InvalidArgumentException`. Audit your filter UIs to use only the
documented operators.

---

## 7. Verify after upgrade

```bash
# Pest suite (must be 0 failed)
cd packagist/mk-director-laravel
./vendor/bin/pest

# Boundary linter (MME rule)
php artisan mk:lint:boundaries

# OpenAPI spec regenerates without error
php artisan mk:generate-docs
```

If any of these fail, **do not** proceed to production. Open a ticket
with the failing test/lint/spec attached.

---

## 8. Support

If the migration script fails partway through, capture the full
output and contact the mk-director maintainers. Do NOT re-run the
script against a partially-migrated database without first
restoring the backup.