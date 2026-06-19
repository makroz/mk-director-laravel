# 1.2.2-hardening — Reference

> **Audience**: maintainers of `makroz/director-laravel` and operators
> who upgrade a 1.2.x app to 1.2.2.
> **Source-of-truth**: `openspec/changes/2026-06-18-1.2.2-hardening/`
> (proposal, design, tasks, state). This file is the human-readable
> summary — it MUST stay in sync with the SDD change.

## Why this sprint

`1.2.0-rc1` closed the 27 critical / high-severity findings from the
4R audit (PR #4). 35 Medium/Low items remained deferred. This
hardening sprint attacks 6 of them — the ones that close the most
attack surface for the smallest blast radius — and freezes the rest
behind a `1.3.0` backlog.

The audit-trail lives in
`openspec/changes/archive/2026-06-17-laravel-4r-fixes/`. The original
57-finding report is in `~/.mavis/scratchpads/mvs_8e406a03.../scratchpad.md`
(Mavis's session, 2026-06-17).

## What changed (and why)

### R2-010 — debug payload is now role-gated
**File**: `src/Controllers/BaseController.php` (`getDebugData`).
**Risk closed**: an authenticated user with `?debug=true&_debug=1`
could read EXPLAIN + raw query bindings — a PII / schema leak.
**Fix**: the method now returns `[]` unless the request user has
`hasRole('super-admin')` OR `hasRole('dev')`. Apps whose User model
does not implement `hasRole()` get an empty debug payload
(fail-safe, no 500).
**Test**: `tests/Unit/BaseControllerDebugGateTest.php` (5 cases).
**BC impact**: none for properly-configured apps. Apps that relied on
debug data flowing to non-operator users (which is a bug) will see
empty payloads.

### R3-014 — `role_user.user_id` now has a foreign key
**File**: `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php`
(new).
**Risk closed**: deleting an `auth_users` row left orphan rows in
`role_user`, silently breaking RBAC.
**Fix**: new migration adds the FK to `auth_users.id` (UUID) with
`cascadeOnDelete()`. Idempotent: skips silently if the FK already
exists or `role_user` is missing.
**Test**: `tests/Unit/Auth/RoleUserFkMigrationTest.php` (3 cases).
**BC impact**: none. The migration runs in the normal `php artisan
migrate` cycle. `down()` is `try/catch`-wrapped so re-running on a
fresh install does not crash.

### R4-004 + R2-007 — DB::listen is no longer a cache stampede
**File**: `src/MkServiceProvider.php` (`registerGlobalCacheListener`).
**Risk closed**: the listener ran on EVERY query. Cron writes to
`jobs`, `migrations`, `sessions`, `password_resets`, etc. triggered
cache invalidations on tables the cache never knew about — classic
cache stampede when the file driver is used. The previous
`str_contains($query->sql, 'cache')` heuristic only excluded the
`cache` table and was unreliable.
**Fix**: the listener now (a) skips a hard-coded `$systemTables`
allowlist, and (b) only acts on writes
(`INSERT` / `UPDATE` / `DELETE`).
**Known limitation** (documented in the docblock): the regex does
not match `REPLACE`, `TRUNCATE`, raw stored-procedure calls, or
Eloquent `upsert()`. Callers using those patterns must
`Cache::tags([$table . '_all'])->flush()` manually.
**Test**: `tests/Unit/MkServiceProviderCacheListenerTest.php` (5 cases).
**BC impact**: none. The same `$table . '_all'` tag naming is
preserved, so consumers' tag-based cache code keeps working.

### R1-004 — Pint is installed, configured, and unused
**Files**: `pint.json` (new), `composer.json` (scripts added).
**Status**: Pint 1.29.3 is in `require-dev` with a `pint.json`
that declares preset `laravel` + `declare_strict_types` +
`ordered_imports alpha`. Scripts: `composer lint` (pint --test),
`composer lint:fix` (pint), `composer security:lint` (new T5.1).
**Why we did NOT apply the 93-file diff**: applying Pint to the
whole codebase would mean a 1000+-line commit in the hardening
PR, mixing cosmetic changes with security fixes — exactly the
pattern R-NEW-001 was created to prevent. The tool is ready; the
project-wide apply is deferred to 1.3.0.
**Test**: none (the tool is the contract; devs can run it).
**Operator action**: run `composer lint:fix` before opening a PR.

### R2-008 + R2-009 — `mk:security-lint` for fail-fast feedback
**File**: `src/Console/Commands/SecurityLintCommand.php` (new, 234 lines).
**Risk closed**: misconfiguration that would only surface at
runtime (a `$guarded = []` in a consumer model, a `MkMultiTenantPlugin::$tenantColumn`
set to `password` or `email`).
**Fix**: source-parsing linter that scans the project's `app/Models/**/*.php`
+ `config/mk_director.php`. Three checks:

1. `$guarded = []` (or `['*']`) — **WARN** with the file:line.
2. `belongsTo(<X>::class)` on a column without a matching FK in
   `database/migrations/` — **WARN** (best-effort heuristic).
3. `MkMultiTenantPlugin::$tenantColumn` set to a value outside the
   whitelist (`tenant_id`, `client_id`, `org_id`, `company_id`) —
   **ERROR** (hard fail).

Exit codes: `0` on success, `1` on any error or on warnings when
`--strict` is passed. `--format=json` for CI consumption.

The whitelist is duplicated from `MkMultiTenantPlugin::TENANT_COLUMN_WHITELIST`
as a defensive measure (lint runs even when the plugin is not yet
booted).
**Test**: `tests/Unit/SecurityLintCommandTest.php` (6 cases).
**Spec**: `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md`.
**Operator action**: add `mk:security-lint` to CI; gate PRs on a
clean run.

### R1-005 (advisory) — `mk:check` warns on unguarded SmartControllers
**File**: `src/Console/Commands/MkCheckCommand.php` (`auditController`).
**Risk surfaced**: `SmartController` does not enforce auth by itself
(BC: pre-existing apps rely on it being a pass-through). A
developer who extends `SmartController` and forgets to add an auth
middleware gets a public-by-default endpoint.
**Fix**: `mk:status` now scans each `SmartController` subclass and
emits a `WARN` finding if the source has no obvious auth wiring
(no `middleware(`, no `MkAuthenticate` / `MkAbility` reference, no
auth-aware `__construct`). Advisory, not enforcement.
**Test**: `tests/Unit/MkCheckCommandAuthWarningTest.php` (4 cases).
**Operator action**: review the `mk:status` output before
deploying. If a WARN is intentional (e.g. a public registration
endpoint), no action needed.

## What did NOT change (and why)

### R1-003 — phpstan + Larastan
**Status**: cancelled. Larastan 3.10.0 (the only Laravel 13
compatible version as of 2026-06-18) crashes on
`Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` in
`vendor/larastan/larastan/src/LarastanStubFilesExtension.php:25`.
The install/remove dance confirmed the lockfile stays clean
when we wait for upstream to ship a fix. The decision is to
diff to 1.3.0 rather than ship a broken tool.

If you want a local-only sanity check before a release, the
simplest workaround is `find src -name '*.php' -exec php -l {} \;`
— catches syntax errors but not type bugs.

### R1-001 — ADR `Contracts/` vs `Api/`
Out of scope. The scaffold emits `Contracts/RepositoryInterface` and
the spec canonically requires `Api/{Name}ApiInterface`. Choosing one
is a breaking change. Tracked for 1.3.0 with a dedicated ADR.

### R2-001 — rewrite of commit `6303844`
Git history is git history. The CHANGELOG for `1.2.0-rc1` already
notes the mislabeled commit; we do not rebase.

### 27 additional Medium/Low items from the 4R audit
T9.* discovery slot in the tasks.md would have surfaced any of these
that emerged during code reading for the 6 closed findings. None did.
The remaining 27 are tracked in the `1.3.0` backlog.

## How to revert

Each task is its own commit. To roll back a single change:

| Finding | Commit (will exist after T8.1) | Revert command |
|---|---|---|
| R2-010 | `sec(laravel): BaseController debug gate by role` | `git revert <sha>` |
| R3-014 | `fix(laravel): role_user.user_id FK to auth_users.id` | `git revert <sha>` + `php artisan migrate:rollback --step=1` |
| R4-004 / R2-007 | `perf(laravel): DB::listen filter writes + system tables` | `git revert <sha>` |
| R1-004 | `chore(laravel): add pint config` | `git revert <sha>` (also remove `pint.json`, restore `composer.json` scripts) |
| R2-008 + R2-009 | `feat(laravel): mk:security-lint command` | `git revert <sha>` (also un-register in `MkServiceProvider`) |
| R1-005 (advisory) | `docs(laravel): mk:check warns on unguarded SmartController` | `git revert <sha>` |

## How to verify after upgrade

1. `composer install` (or `composer update makroz/director-laravel`).
2. `vendor/bin/pest` — expect 222 passed (was 199 pre-hardening).
3. `php artisan migrate` — runs the new `add_fk_role_user_to_auth_users`
   migration.
4. `php artisan mk:status` — for each SmartController, the `Estado`
   column should now include a `WARN` line if auth is not wired.
5. `php artisan mk:security-lint --format=json` — should exit 0
   on a clean install. On a fresh project that has `$guarded = []`
   or a misconfigured `MkMultiTenantPlugin::$tenantColumn`, expect
   non-zero with structured JSON output.

## Open questions / follow-ups for 1.3.0

- ADR for `Contracts/` vs `Api/` (R1-001).
- Re-attempt `phpstan` install when Larastan 3.11+ ships.
- Apply the Pint diff in a dedicated `chore:` commit (R1-004 close).
- 27 additional Medium/Low items from the 4R audit. Run T9.*
  discovery at the start of the 1.3.0 cycle to see which the
  codebase still has.
- Investigate the 11 `deprecated` test annotations (not
  blocking but worth a follow-up).
