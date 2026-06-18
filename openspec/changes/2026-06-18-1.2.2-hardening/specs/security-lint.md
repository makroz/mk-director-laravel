# Spec: Security Lint

**Owner**: mk-director-laravel / Sprint 1.2.2-hardening
**Status**: NEW (delta spec — does not exist in `openspec/specs/` yet)
**Source-of-truth**: this file (lives in `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md`; after archive, will move to `openspec/specs/security-lint.md`)

## Purpose

Define the requirements and behavior of `php artisan mk:security-lint`, a static analyzer that catches common security anti-patterns in Laravel application code that consumes `makroz/director-laravel`. The lint is **advisory** (returns non-zero exit code on errors, non-zero warning count does not fail the command) and runs in two contexts:

1. **Local development**: `composer lint:security` in the consumer app.
2. **CI**: optional job in the consumer's pipeline.

## Requirements

### REQ-SL-001 — `$guarded = []` detection

The command SHALL scan `app/Models/**/*.php` (consumer's app) and `src/Models/**/*.php` (mk-director-laravel itself) for models that declare `protected $guarded = []` or `protected $guarded = ['*']`. When detected, the command SHALL emit a `WARN` level finding with the file path and line number.

**Rationale**: mass assignment vulnerability. While `CRUDSmart` filters by `$fillable`, a model with `$guarded = []` allows arbitrary attribute injection in any other controller that uses `Model::create($request->all())`.

**Scenario**:
- **WHEN** a model has `protected $guarded = []`
- **THEN** the command emits a WARN with the file path
- **AND** the command does NOT exit with non-zero status (advisory only)

### REQ-SL-002 — Missing foreign key declaration

The command SHALL scan models with `belongsTo` relations and verify that the corresponding FK column is declared in the migration with `->foreign()->references()->on()` or `->constrained()`. When the FK is missing, the command SHALL emit a `WARN` level finding.

**Heuristic**:
- Read the model file, find `belongsTo(<Model>::class)` calls.
- Extract the second argument (FK column name) or default to `<snake_case_table>_id`.
- Search the migrations directory for a `Schema::create(<table>)` or `Schema::table(<table>)` block that declares a `foreign` or `constrained` for that column.
- If not found → WARN.

**Scenario**:
- **WHEN** a model has `belongsTo(User::class)` on `user_id` column
- **AND** the `addresses` table migration does not declare `->foreign('user_id')`
- **THEN** the command emits a WARN explaining the missing FK.

### REQ-SL-003 — `$tenantColumn` whitelist enforcement

The command SHALL read `MkMultiTenantPlugin::$tenantColumn` (default `'tenant_id'`) and compare against a hardcoded whitelist: `['tenant_id', 'org_id', 'company_id']`. If the value is outside the whitelist, the command SHALL emit an `ERROR` level finding and exit with non-zero status.

**Rationale**: `MkMultiTenantPlugin::beforeSave` and `beforeQuery` inject `WHERE <tenantColumn> = <current>` on every operation. If `$tenantColumn` is misconfigured to `password`, `email`, or any non-tenant column, the plugin will silently corrupt data and break queries.

**Scenario**:
- **WHEN** a consumer sets `MkMultiTenantPlugin::$tenantColumn = 'password'`
- **THEN** the command emits an ERROR (not WARN)
- **AND** the command exits with status 1.

### REQ-SL-004 — Output format

The command output SHALL follow this structure:

```
Security Lint Report
═══════════════════
✓ {N} models checked
⚠ {W} warnings
✗ {E} errors

[ERROR|WARN] {path}:{line}
              {message}
```

The command SHALL be deterministic (same input → same output, byte-exact).

### REQ-SL-005 — Configurability

The command SHALL read optional config from `config('mk_director.security_lint', [])`:
- `paths`: array of additional paths to scan (default: `['app/Models', 'src/Models']`).
- `strict`: boolean (default `false`). When `true`, WARN-level findings also exit with non-zero status.
- `baseline`: path to a baseline file (newline-separated paths to ignore, default: `null`).

### REQ-SL-006 — Performance

The command SHALL complete in < 2 seconds for projects with up to 100 models. Implementation MUST NOT load the full Laravel container (no `app()->boot()`); it MUST use file-system reads only (source-parsing pattern from sprint `1.2.0-fixes`).

## Out of scope (deferred)

- Detection of `$fillable` over-permissive patterns (e.g., listing `password`, `is_admin`).
- Type-level analysis (Psalm / PHPStan overlap).
- Taint analysis of request → DB paths.
- Multi-tenant context validation (which tenant is the user in).

These are out of scope for 1.2.2-rc1. Tracked for 1.3.0+.
