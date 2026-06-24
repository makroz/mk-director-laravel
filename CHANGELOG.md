# Changelog

All notable changes to `makroz/director-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.1] - 2026-06-24

Patch release. Fixes three real bugs in the `php artisan mk:make:auth-user {Scope}`
scaffolder that were reported by the RETO project (the first real-world consumer
of v1.3.0) immediately after release. None of the fixes change the public API
or the command signature — the generated module is functionally identical,
just correct out of the box.

### Fixed

- **ServiceProvider FQCN was missing the `Providers\` subnamespace** (bug
  1.3.0-001). `MakeAuthUserCommand::registerServiceProvider()` used to write
  `App\Modules\{Scope}\{Scope}ServiceProvider::class` into
  `bootstrap/providers.php`, but the stub generates
  `App\Modules\{Scope}\Providers\{Scope}ServiceProvider` (with the
  subnamespace). Result: Laravel could not resolve the class, the module
  silently failed to load, and `route:list` showed zero routes for the
  scope. The FQCN is now built with the correct subnamespace. The previous
  bug is pinned by a new source-parsing test
  (`MakeAuthUserCommandTest::test(...builds the ServiceProvider FQCN with
  the Providers subnamespace...)`) that asserts both the new form is
  present and the old form is absent.

- **Hardcoded `create_admins_table` migration duplicated the scaffolder's
  output** (bug 1.3.0-002). The package used to ship
  `src/Auth/Database/Migrations/2026_06_10_000006_create_admins_table.php`
  as a leftover from the original Admin scope, and `MkServiceProvider::boot()`
  auto-loaded it via `loadMigrationsFrom`. When a consumer ran
  `php artisan mk:make:auth-user Admin` and then `php artisan migrate`,
  both the package's migration and the scaffolder's generated migration
  tried to create the `admins` table, producing a "Table 'admins' already
  exists" failure. The hardcoded file has been removed: the scaffolder is
  the canonical source for the scope's table. Existing consumers that
  already ran the hardcoded migration keep the entry in their
  `migrations` table and the table itself; subsequent `migrate` calls
  skip the now-missing file. Pinned by
  `MakeAuthUserCommandTest::test(...does NOT ship a hardcoded
  create_admins_table migration...)`.

- **Routes stub produced paths without the `api/` prefix** (bug
  1.3.0-003). `auth-user.routes.stub` used to generate
  `Route::prefix('{{moduleNameLower}}/auth')`, producing
  `/admin/auth/login` — but the `AuthController` docblock, the
  scaffolder's success output, and the CHANGELOG entry for 1.3.0 all
  advertised `/api/admin/auth/login`. Root cause: Laravel 11+
  `loadRoutesFrom` from a ServiceProvider does NOT inherit the
  `apiPrefix` configured in `bootstrap/app.php` (that only applies to
  the central `routes/api.php`). The stub now emits
  `Route::prefix('api/{{moduleNameLower}}/auth')`. The existing test
  that asserted the old (broken) prefix has been updated to match
  the correct behavior.

### Test coverage

- 2 new tests in `MakeAuthUserCommandTest` (one pins the correct
  FQCN, one pins the absence of the hardcoded migration).
- 1 updated test in `MakeAuthUserCommandTest` (the routes prefix
  expectation, which used to encode the bug as the "expected"
  behavior).

### Notes for consumers

- The fixes apply to fresh runs of `php artisan mk:make:auth-user`.
  Consumers that already applied manual workarounds (such as RETO,
  which fixed the FQCN in `bootstrap/providers.php` by hand and
  deleted the scaffolder's duplicate migration) are unaffected —
  their workarounds remain valid; the fix simply removes the
  requirement for those workarounds on future consumers.

## [1.3.0] - 2026-06-24

This release closes the audit-driven gap on auth scaffolding (PRs #7,
#8, #9 of this repo, plus PRs #36, #37, #38 of the monorepo). The
package is now feature-complete on the auth front: every scope
scaffolder, the skill deploy flow, the super-admin creator, and the
hardened config block all ship together.

### Added

- **`php artisan mk:make:auth-user {Scope}`** — scaffolder de un scope de
  autenticación MK completo. Cierra el gap entre la doc
  (`docs/guides/AUTH.md` § "Generating a new scope") y el código: el
  comando se documentaba desde 1.0.0 pero no existía en `src/`.
  Genera: `Models/{Scope}.php` (extends `AuthUser` con
  `setAuthScope` en constructor), migration con `auth_scope` indexado,
  `Http/Controllers/AuthController.php` (login/refresh/logout/me/forgot/reset
  como skeleton con TODOs), `Http/Routes/api.php` con
  `prefix('api/{scope}/auth')` + `mk.auth:{scope}` middleware, y
  `{Scope}ServiceProvider` auto-registrado en `bootstrap/providers.php`
  (Laravel 11+) o `config/app.php` (Laravel 10).
  El command **NO** modifica `config/auth.php` del consumer (decisión
  consciente, least surprise): imprime los snippets a agregar
  (guard + provider).
  Stubs: `src/Stubs/auth-user.{model,migration,auth-controller,routes,service-provider}.stub`.
  Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6.
  Diff: `src/Console/Commands/MakeAuthUserCommand.php` (nuevo, 256 líneas).
  Tests: `tests/Unit/Console/MakeAuthUserCommandTest.php` (10 casos),
  `tests/Unit/MakeAuthUserCommandRegisteredTest.php` (2 casos).

- **`php artisan mk:skill:list`** — lista las skills disponibles para
  el ecosistema MK-Director. Escanea tres ubicaciones: paquete,
  agencia (`~/.mavis/agents/main/skills` + `.makromania/agency/skills`),
  y locales (`.makromania/agency/skills` + `.agents/skills` del
  proyecto actual). Muestra una tabla con `Name / Source / Deployed /
  Path`. Read-only, no toca archivos. Útil como punto de partida
  antes de `mk:skill:deploy`. Diff:
  `src/Console/Commands/MkSkillListCommand.php`. Test:
  `tests/Unit/Console/MkSkillListCommandTest.php` (6 casos).

- **`php artisan mk:skill:deploy {nombre?}`** — deploya una skill al
  proyecto actual. Es **asistente, no invasivo**: no toca config,
  no registra providers, no modifica composer. Solo copia
  `SKILL.md` a la ubicación autodetectada y agrega/actualiza la
  sección `## Skills deployadas` en `AGENTS.md` (idempotente: si la
  skill ya está listada, no duplica; si `AGENTS.md` no existe, lo
  crea con un template mínimo).
  Fuentes de la skill (en orden de prioridad):
  1. `~/.mavis/agents/main/skills/{nombre}/SKILL.md` (Mavis agent)
  2. `.makromania/agency/skills/{nombre}/SKILL.md` (agencia del workspace)
  3. `src/Skills/{nombre}/SKILL.md` (futuro: paquete)
  Destino (en orden de prioridad):
  1. `--to=` explícito del usuario
  2. `.makromania/agency/skills/` si existe
  3. `.agents/skills/` si existe
  4. Prompt al usuario con default `.makromania/agency/skills/`
  Flags: `--to=` para forzar destino, `--dry-run` para simular.
  Diff: `src/Console/Commands/MkSkillDeployCommand.php`. Test:
  `tests/Unit/Console/MkSkillDeployCommandTest.php` (7 casos).

- **`php artisan mk:auth:create-super-admin`** — crea el primer usuario
  super-admin del scope "admin". El command se documentaba en
  `docs/GETTING_STARTED.md` desde 1.0.0 pero nunca se implementó;
  el audit 2026-06-24 cerró ese gap.
  Comportamiento:
  - Pregunta email, name, password (con confirmación). Acepta
    `--email=`, `--name=`, `--password=` para skip prompts en CI.
  - Valida formato de email (FILTER_VALIDATE_EMAIL) y longitud
    mínima de password (>= 8 chars).
  - Falla rápido con mensaje accionable si la clase
    `App\Modules\Admin\Models\Admin` no existe (le dice al dev que
    corra `mk:make:auth-user Admin` primero).
  - Es idempotente: re-correr con el mismo email sale con success
    y un warning, sin duplicar el row.
  - Asigna el role "super-admin" (auto-creado si no existe, con
    guard = auth_scope del user = "admin").
  - Asigna la ability `*` como grant directo (path `ability_user`)
    para que el super-admin no dependa de un seeder adicional.
  Diff: `src/Console/Commands/AuthCreateSuperAdminCommand.php`
  (nuevo, ~150 líneas). Test:
  `tests/Unit/Console/AuthCreateSuperAdminCommandTest.php` (7 casos).

### Changed

- **`php artisan mk:update`** — agrega un step opt-in al final del
  flujo que sugiere al dev revisar y deployar skills nuevas del
  ecosistema. Solo dispara si la respuesta a "¿Querés revisar y
  deployar las skills nuevas del ecosistema MK?" es positiva, y
  se salta en `--dry-run`. Implementado como
  `promptForSkillDeploy()` en `MkUpdateCommand`. Diff:
  `src/Console/Commands/MkUpdateCommand.php`. Test:
  `tests/Unit/MkSkillCommandsTest.php` (4 casos).

### Fixed (R-NEW-001 compliant)

- **`config/mk_director.php` `auth` block ya no rompe DDD.**
  Antes:
  - `'user_model' => \App\Models\User::class` — hardcodeaba el modelo
    default de Laravel, que rompe MME (los modelos viven en
    `App\Modules\<Scope>\Models\<Scope>`, no en `App\Models\*`).
  - `'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE', 'App\\Modules\\Admin\\Models\\Admin')` —
    asumía que el consumer tiene un módulo Admin, lo que rompe DDD
    (el paquete no debe conocer los módulos del consumer).
  Después: ambos leen de `env(...)` con default `null`. El consumer los
  define en su propio `config/mk_director.php` publicado, después de
  generar un scope con `mk:make:auth-user {Scope}`.
  Diff: `config/mk_director.php`.
  Test: `tests/Unit/MkDirectorConfigDefaultsTest.php` (4 casos).

### Docs

- The `auth` block del config ya no se etiqueta como `Experimental` —
  el command existe, la migración `auth_users` está consolidada, y
  `AUTH.md` documenta el flujo. El paquete deja de marcarse a sí
  mismo como experimental.
- Monorepo companion PRs: #36 (`mk:make:auth-user` doc), #37
  (`mk-update` npm doc + drift fixes), #38 (replace Tinker snippet
  in `GETTING_STARTED.md`).

## [Unreleased]

### Added

### Changed

### Fixed

### Deprecated

### Removed

### Security

## [1.2.2-rc1] - 2026-06-18

Hardening sprint on top of `1.2.0-rc1`. Closes 6 of the 35 Medium/Low
findings from the original 4R audit; the remaining 29 are deferred to
1.3.0 (see `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md`).

### Security (R-NEW-001: every entry below cites the diff to `src/` and the test that covers it)

- **R2-010 — `BaseController::getDebugData` now requires an authenticated
  user with `super-admin` or `dev` role.** Previously, any request with
  `?debug=true&_debug=1` would get EXPLAIN + raw query bindings — a
  PII / schema leak waiting to happen. The gate is fail-safe: apps
  whose User model does not implement `hasRole()` get an empty debug
  payload, not a 500. Diff: `src/Controllers/BaseController.php`.
  Test: `tests/Unit/BaseControllerDebugGateTest.php` (5 cases).

- **R3-014 — `role_user.user_id` foreign key to `auth_users.id`.** The
  pivot was missing the FK, so deleting an `auth_users` row left orphan
  rows in `role_user`. New migration
  `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php`
  adds the FK with `cascadeOnDelete()`. Idempotent: skips silently if
  the FK already exists or the `role_user` table is missing
  (consumer apps with custom migrations). `down()` is wrapped in
  `try/catch` so re-running on a fresh install does not crash. Test:
  `tests/Unit/Auth/RoleUserFkMigrationTest.php` (3 cases).

### Performance (R4-004 + R2-007)

- **DB::listen no longer runs on reads or on system-table writes.**
  Previously the "magic cache" listener ran on EVERY query and only
  excluded the `cache` table via `str_contains`. Cron writes to
  `jobs`, `migrations`, `sessions`, `password_resets`, and the
  `failed_jobs` table would trigger cache invalidations on tables
  the cache never knew about. The new listener:
  (a) skips a hard-coded `$systemTables` allowlist (migrations,
  cache, cache_locks, sessions, password_resets, password_reset_tokens,
  jobs, job_batches, failed_jobs, telescope_*), and
  (b) only acts on writes (`INSERT` / `UPDATE` / `DELETE`).
  Known limitation (documented in the docblock): the regex does not
  match `REPLACE`, `TRUNCATE`, raw stored-procedure calls, or
  Eloquent `upsert()`. Callers using those patterns must
  `Cache::tags([$table . '_all'])->flush()` manually.
  Diff: `src/MkServiceProvider.php`.
  Test: `tests/Unit/MkServiceProviderCacheListenerTest.php` (5 cases).

### Tooling

- **R1-004 — Pint installed and configured.** `pint.json` declares
  preset `laravel` + rules `declare_strict_types` + `ordered_imports
  alpha`. Scripts added to `composer.json`:
  `composer lint` (pint --test), `composer lint:fix` (pint), and
  `composer security:lint`. **The 93 file diff that Pint suggests
  has NOT been applied in this sprint** — it would contaminate the
  PR with unrelated cosmetic changes. Run `composer lint:fix` locally
  before opening a PR. The sprint is shipped with the tool installed
  and configured; the project-wide apply is deferred to 1.3.0.

- **R2-008 + R2-009 — `php artisan mk:security-lint` command.** New
  source-parsing linter with three checks: `$guarded = []` on
  Eloquent models, missing `belongsTo` foreign keys, and
  `MkMultiTenantPlugin::$tenantColumn` set to a value outside the
  whitelist (`tenant_id`, `client_id`, `org_id`, `company_id`).
  Exit codes: `0` on success, `1` on any error. `--strict` flag
  escalates warnings to failures. `--format=json` for CI integration.
  Source-parsing only — no Laravel app boot required, runs in < 2s
  for 100 models. Diff: `src/Console/Commands/SecurityLintCommand.php`
  (new file, 234 lines), registered in `MkServiceProvider`.
  Test: `tests/Unit/SecurityLintCommandTest.php` (6 cases).
  Spec: `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md`.

### Observability

- **R1-005 (advisory) — `mk:check` warns on unguarded SmartControllers.**
  The `mk:status` command (`MkCheckCommand`) now scans each
  SmartController subclass and emits a `WARN` finding if the source
  has no obvious auth wiring (no `middleware(`, no `MkAuthenticate` /
  `MkAbility` reference, no auth-aware `__construct`). This is
  advisory, not enforcement: SmartController does not enforce auth by
  itself (BC: pre-existing apps rely on it being a pass-through), and
  the responsibility to add a middleware remains the developer's.
  Diff: `src/Console/Commands/MkCheckCommand.php`.
  Test: `tests/Unit/MkCheckCommandAuthWarningTest.php` (4 cases).

### Deferred to 1.3.0

- **R1-001** ADR `Contracts/` vs `Api/` (architectural decision
  required from Mario).
- **R1-003** phpstan + Larastan. Larastan 3.10.0 (the only Laravel 13
  compatible version as of 2026-06-18) crashes on
  `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` in
  `LarastanStubFilesExtension.php:25`. Bug is upstream. The
  install/remove dance confirmed the lockfile stays clean if we
  wait for Larastan 3.11+ to ship.
- **R1-005 (test)** end-to-end test of `LintBoundariesCommand` that
  actually invokes `handle()`. Out of scope for hardening.
- **R2-001** rewrite of commit `6303844` (`sec(laravel): mitigate
  SQL injection in ListManager`). Git history is git history.
- 27 additional Medium/Low items from the 4R audit. Tracked in
  `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md` and
  to be re-prioritized at the start of the 1.3.0 cycle.

### Process notes

- All 6 task commits are atomic and follow the
  `<type>(<scope>): <subject>` convention.
- The 3 `sec:`-prefixed commits (R2-010) cite both the diff path and
  the test file. R-NEW-001 satisfied.
- The Pint diff (93 files) is NOT shipped in this sprint — see the
  Tooling section above.
- `phpstan` install was attempted, locked to the package's
  compatibility window, then reverted when the Larastan bug
  surfaced. The lockfile is byte-identical to `1.2.0-rc1`.

## [1.2.0-rc1] - 2026-06-17

### Security (R-NEW-001: every entry below cites the diff to `src/`)

The audit found 57 issues (10 critical, 17 high, 19 medium, 12 low).
This RC closes all 10 P0 and all 17 P0-targeted high-severity findings.
Items #2–#4 below are code-only changes that fail loudly if a consumer
was relying on the previous (looser) behavior — there is no data
migration for those.

- **R4-001 — `HasAbilities::canMk` now delegates to `AbilityResolver`.**
  The resolver caches the resolved ability names per user with a
  configurable TTL (default 300s) and short-circuits via Sanctum
  `currentAccessToken()->can()` before any DB query. Every mutator
  (`giveAbilityTo`, `revokeAbilityTo`, `syncDirectAbilities`,
  `assignRole`, `revokeRole`, `syncRoles`) invalidates the cache so
  the next `canMk` reads fresh data. Commit 656360e.

- **R1-002 — `ability_user` migration published.** The pivot table
  for direct ability grants was referenced by `HasAbilities` but
  the migration was never shipped. Now in
  `src/Auth/Database/Migrations/2026_06_10_000007_create_ability_user_table.php`
  with FK to `abilities`, `uuid('user_id')` matching `auth_users.id`,
  and a unique composite index on `(ability_id, user_id, user_type)`.
  Commit d015cfc.

- **R3-005 — `AuthUser` docblock declares `$id` as `string`.**
  Was `@property int $id` even after the UUID migration. Tests parse
  the source file directly because the class uses Sanctum's
  `HasApiTokens` (not in `composer.json`). Commit 568111d.

- **R4-002 — `MkAuthenticate` eager-loads `roles.abilities` and
  `directAbilities` after the scope resolver runs.** Closes the N+1
  every downstream `canMk` would otherwise trigger. Commit 74bfe95.

- **R2-002 / R2-003 — `MkAbility` rejects empty ability lists and
  short-circuits via Sanctum.** Before: `Route::middleware(['mk.ability:'])`
  silently passed every request through (privilege escalation trap).
  Now: returns 500 + `ERR_MIDDLEWARE_MISCONFIGURED`. Sanctum
  `currentAccessToken()->can($ability)` is checked before the role/
  direct-grants path. Commit 1d5ffdb.

- **R2-004 — `AuthUser` uses `HasTenantMembership`; `TenantResolver`
  validates user↔tenant membership.** The middleware now reads the
  user's tenant via `$user->getTenantId()` and returns 403
  `ERR_TENANT_MISMATCH` if the resolved tenant differs. Prevents
  tenant-A tokens from accessing tenant-B data via header. Commit 5dd846d.

- **R2-005 / R2-006 — `TenantContext` is flushed at request end;
  `HasTenantScope` is per-model opt-in.** `MkServiceProvider::boot`
  registers a `terminating` callback that calls `TenantContext::flush()`
  so long-lived workers (Octane / Swoole) do not leak tenant state
  across requests. `HasTenantScope` now requires `protected static
  bool $usesTenant = true` per model — adding the trait alone is a
  no-op. Commit 9a807da.

- **R2-009 / R2-018 / R4-003 — `MkMultiTenantPlugin` whitelist,
  strict comparison, and mutex with `HasTenantScope`.** The plugin
  rejects any `$tenantColumn` not in the documented whitelist
  (`tenant_id`, `client_id`, `org_id`, `company_id`). `beforeDelete`
  uses strict `!==` (was `!=`) to prevent the int-vs-string coercion
  bug where `'00000000-...' (string) != 0 (int)` was truthy. When the
  model already uses `HasTenantScope` with `$usesTenant = true`, the
  plugin skips its own `where()` to avoid applying the tenant
  predicate twice. Commit 7004a3d.

- **R2-014 / R3-007 / R2-012 — `ListManager` LIKE escape, operator
  whitelist, and `restoreState` sanitize.** `escapeLikeWildcards()`
  wraps `addcslashes($value, '\\%_')` and is used by both `applySearch`
  and the `like` filter operator so a search for `'50%'` matches
  the literal string instead of every row containing `50`. The
  search term is also capped at `mk_director.search.max_length`
  (default 256). `applyFilterOperator` now throws
  `InvalidArgumentException` on unknown operators (was a silent
  fallback to `=`). `restoreState` hashes the storage key with HMAC-
  SHA256 of `app.key` and sanitizes the rehydrated filter/sort state
  against the same whitelists used by the apply path. Commit 373007d.

- **R4-005 / R4-006 / R2-016 — Performance + symlink rejection.**
  `OpenApiController::spec` is wrapped in `Cache::remember()` with a
  24h TTL; `mk:generate-docs` calls `Cache::forget()` on the same
  key. `ModuleProviderRegistry::discover()` caches the discovered
  providers for 1h, keys the cache on `md5(realpath(modules path))`
  so any directory change invalidates automatically, and rejects
  symlinked module directories (R2-016) — a symlink under
  `app/Modules` pointing to `/tmp/evil` would otherwise be discovered
  as a legitimate module. Commit 77ad693.

### Process (R-NEW-001)

- **CI: monorepo now has a `pest-laravel` job** that runs
  `./vendor/bin/pest` against the package and is in the `build`
  dependency chain. Path filter so the job only fires when the
  package or the workflow file changes. This closes the gap that
  allowed the previous sprint (PR #3) to declare 6 security fixes
  and ship only 3 — the monorepo CI never exercised the sub-repo's
  pest suite.
- **Spec: `openspec/specs/architecture/modular-encapsulation.md`**
  documents the rule with the three required scenarios (claim
  without diff, claim with diff but without test, monorepo CI
  does not run pest-laravel). R-NEW-001 is enforced at review time.

### Testing infrastructure

- `MkLaravelTestCase` — boots a minimal Laravel Container with
  config/cache/db/files bindings so the 5 pre-existing pest failures
  (R3-009, R3-010, R3-011) are green. No new composer dependency
  (we deliberately avoided `orchestra/testbench` which would pull
  in the full application kernel).
- `StrictTypesTest` extended to scan `tests/` (was `src/` only) and
  asserts `declare(strict_types=1)` is positioned right after
  `<?php` — 8 pre-existing test files were missing the declaration.
- `AuthUserMigrationTest`, `RoleUserMigrationTest` — refactored to
  parse the migration source directly because the chainable
  `Blueprint` mock fights Laravel's real signatures.

### Documentation

- `docs/UPGRADE_1.2.md` — full upgrade guide covering the breaking
  changes, pre-flight checklist, runbook for the UUID migration,
  rollback procedure.
- `bin/migrate-1.1-to-1.2.php` — standalone CLI script with
  `--dry-run`, `--connection`, `--chunk`, `--help`. Idempotent,
  refuses to run outside CLI, refuses to migrate a table whose id
  is neither BIGINT nor CHAR(36).

### Notes

- **DO NOT publish to Packagist yet.** This RC is tagged locally so
  the team can validate against `apps/sandbox-laravel` before
  publishing. Mario will run `composer publish` from his machine
  after sandbox validation passes.
- 9 of the 12 medium-priority and all 12 low-priority findings from
  the audit are deferred to `1.2.2-hardening` (next sprint).

## [1.2.0] - 2026-06-17

### Security

- **A1 — SQL injection mitigado en `ListManager`.** `applyFilters` y `applyJoins` ahora
  requieren una whitelist explícita (`allowed_filters`, `allowed_joins`). Sin whitelist,
  cero filtros/joins se aplican (comportamiento defensivo por defecto). Cualquier campo
  fuera de la lista es ignorado silenciosamente antes de llegar a la query.

### Changed

- **A2 — `MkAuthMiddleware`: respuesta 401 nativa.** Import de `MkResponse` (clase
  inexistente) removido. Reemplazado por `response()->json(['success' => false,
  'message' => 'Unauthenticated.', 'code' => 'ERR_UNAUTHENTICATED'], 401)`.

- **A3 — Migración `auth_users`: ID como UUID.** `$table->id()` reemplazado por
  `$table->uuid('id')->primary()`. Requiere `HasUuids` en el modelo `AuthUser`.

- **A4 — Migración `role_user`: FK como UUID y default configurable.** `user_id`
  tipado como `uuid` (foreign key coherente con A3). El `user_type` por defecto
  ahora se lee de `config('mk_director.auth.default_user_type', 'App\\Models\\User')`.

- **A5 — `MkAuthenticate`: excepción correcta al faltar autenticación.** Reemplazado
  `MissingAbilityException` (de Sanctum, semántica incorrecta) por
  `AuthenticationException` (framework HTTP estándar de Laravel), pasando el guard
  activo como guards array para que el handler de exceptions produzca un 401 correcto.

- **A6 — `declare(strict_types=1)` en todo el source del paquete.** Todos los
  archivos PHP bajo `src/` tienen la declaración strict_types al inicio. Incluye
  un test automatizado `StrictTypesTest.php` que falla si algún archivo nuevo la omite.

> ⚠️ **Nota de migración**: A3 y A4 son cambios en migraciones de base de datos.
> Si ya corriste las migraciones anteriores en un entorno, necesitás rollback +
> re-run (`php artisan migrate:rollback --step=2 && php artisan migrate`).
> En entornos frescos no hay impacto.

## [1.1.1] - 2026-06-16


### Fixed (1.1.1)

- **`HasTenantScope` now uses `TenantScope` instead of an inline closure** —
  the global-scope class shipped in 1.1.0 was dead code at runtime because
  `HasTenantScope::booted()` registered an inline closure with the same
  `where($column, '=', $tenantId)` logic. The code review that landed
  alongside 1.1.0 flagged this as 🔴 bloqueante (B-3): the
  `TenantScope::apply()` was never invoked, the JSDoc on both classes
  claimed a contract that was not in effect, and the duplication was a
  footgun for any future change.
- **`TenantScope::apply()` is now context-aware.** It resolves the current
  tenant from the `TenantContext` singleton on every apply when no
  explicit `tenantId` was passed to the constructor. The constructor
  still accepts an explicit id (used by the 5 unit tests on
  `TenantScope` and by programmatic scopes); when set, that value wins.
  The "fresh read" semantics that the inline closure had are preserved,
  so the scope is safe under long-lived workers (Octane / Swoole) where
  the tenant can change mid-process.
- **No behavior change at the `HasTenantScope` trait boundary** — the
  trait still registers the scope under the alias `'tenant'`, the opt-in
  guard (`mk_director.tenant.enabled`) is unchanged, and the bypass path
  (`Model::withoutGlobalScope('tenant')`) still works.

### Cross-repo coordination

This 1.1.1 ships in lockstep with the `create-mk-director@1.1.1` CLI,
whose templates now require `makroz/director-laravel: ^1.1`. Publishing
this 1.1.1 is what makes the scaffoldeado de proyectos actually
`composer install`-able end-to-end. The CLI bump is in
`makroz/MK-Director#17` (PR #17, already merged into the monorepo's
`dev`).

## [1.1.0] - 2026-06-12

### Added (1.1.0)

- **Multi-tenant opt-in (M-1 of the 1.1.0 sprint).** Three new
  classes under `Mk\Director\Tenancy\*` ship behind a single
  config flag (`mk_director.tenant.enabled`, default `false`):
  - `TenantScope` — Eloquent `Scope` that filters by `tenant_id`
    on every `apply()`. No-op when no tenant is bound, so
    console / queue jobs see all rows by design.
  - `HasTenantScope` — model trait that auto-registers a
    closure-based global scope at `booted()` time. The closure
    reads `TenantContext` on every query, so the tenant id is
    always fresh (no Octane-style freeze).
  - `TenantContext` — singleton service that holds the current
    tenant id for the duration of a request.
  - `TenantResolver` — HTTP middleware that reads the tenant
    from a header (default `X-Tenant-ID`), a path segment, or a
    subdomain, and writes it into the `TenantContext`. Strict
    mode (default) returns 400 when the tenant cannot be
    resolved.
- **Config flag**: `config/mk_director.php` gains a `tenant` key
  (`enabled`, `resolver`, `header_name`, `model`, `strict`).
  The `MkServiceProvider` always registers the middleware on
  the `api` group, but the middleware itself short-circuits to
  a pass-through when `tenant.enabled = false` (opt-in per
  ADR-003).
- **Sandbox fixtures**: `apps/sandbox-laravel` now ships with
  a `mk_tenants` table, a `DemoTenantableModel` that uses
  `HasTenantScope`, and an end-to-end feature test in
  `tests/Feature/TenantScopeTest.php` that proves isolation
  (header, 400, cross-tenant 404, write-back with the right
  tenant).
- **Documentation**: `docs/guides/MULTI_TENANT.md` is updated
  to reflect the shipped feature (no more "Available from
  v1.1.0" warning — it ships with 1.1.0).

### Changed (1.1.0)

- `branch-alias.dev-main` in `composer.json` bumped from
  `1.0.x-dev` to `1.1.x-dev` to track the new minor line.
- `composer.json` gained `minimum-stability: dev` +
  `prefer-stable: true` so the dev toolchain (Pest 5 has only
  RC releases at the time of this writing) installs cleanly
  without affecting consumers (the dev deps are not
  installed by `composer require`).
- `MkServiceProvider::registerTenantMiddleware()` now always
  registers the middleware on the `api` group. The middleware
  is the one that checks `tenant.enabled` and short-circuits.
  This is more flexible than registering conditionally at
  boot — flipping the config at runtime (e.g. in tests)
  picks up the new state without re-booting the framework.

### Fixed (1.1.0)

- `HasTenantScope`'s closure scope was originally typed as
  `function (Model $model)`, but Eloquent invokes closure
  scopes with the **Builder** (not the model). The
  signature is now `function (Builder $builder)`, and the
  model is obtained via `$builder->getModel()`. This was
  caught and fixed before merge by the sandbox feature
  test in `tests/Feature/TenantScopeTest.php`.

## [1.0.0] - 2026-06-10

### Added (1.0.0)

- **Strict Laravel 13 support**: `makroz/director-laravel` 1.0.0 declares hard
  `^13.0` constraints on `illuminate/support`, `illuminate/database`, and
  `illuminate/http`. Laravel 10/11/12 are no longer supported.
- **PHP 8.4 baseline**: hard `^8.4` requirement (typed const, asymmetric
  visibility, property hooks). PHP 8.2/8.3 are no longer supported.
- **Pest 5 baseline**: `pestphp/pest` and `pestphp/pest-plugin-laravel` upgraded
  to `^5.0` for dev testing.
- **OpenAPI 3.x generation**: the `Mk\Director\Controllers\OpenApiController` and
  `Mk\Director\Services\OpenApiGeneratorService` (already in 0.0.1) now
  target the Laravel 13 schema introspection API (`Schema::getColumns()`)
  and are stable for production B2B usage.
- **SmartController + CRUDSmart trait**: stable, with `beforeList` /
  `beforeSearch` / `afterList` / `beforeCreate` / `afterCreate` /
  `beforeUpdate` / `afterUpdate` / `beforeDelete` / `afterDelete` hooks
  contractually documented via `Mk\Director\Contracts\MkModuleServiceInterface`.
- **ListManager** (`Mk\Director\Managers\ListManager`): stable API for
  pagination, filtering, sorting, search, dynamic includes, with-count,
  and joins.
- **Plugin Manager + MkPluginInterface**:
  - `Mk\Director\Managers\PluginManager` is now stable, with full hook
    coverage (`boot`, `beforeQuery`, `beforeSave`, `afterSave`,
    `beforeDelete`, `afterDelete`, `afterResponse`).
  - `Mk\Director\Plugins\FileStoragePlugin` (auto file uploads) and
    `Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin` +
    `Mk\Director\Plugins\Enterprise\MkAuditLoggerPlugin` ship as
    reference implementations.
- **DTO layer**: `Mk\Director\DTOs\MkDTO` and `Mk\Director\DTOs\DTOFactory`
  provide enum-aware type-safe hydration of DTOs and Eloquent casts
  (datetime, json, int, float, bool).
- **MkServiceProvider** registers the package's commands, config, routes
  (`/mk/openapi.json`, `/mk/docs`), and the global DB→Cache listener
  (the "Magic Cache" feature, opt-in via `mk_director.features.auto_cache`).
- **Artisan commands**:
  - `mk:status` — diagnose SmartControllers and their plugin health.
  - `mk:module {Name}` — scaffold a full MME module
    (Controllers, Contracts, DTOs, Enums, Models, Repositories, Requests,
    Resources, Routes, Services, ServiceProvider, Database/Migrations).
  - `mk:service {Name}` — generate a Service that implements
    `MkModuleServiceInterface`.
  - `mk:dto {Name}` — generate a DTO extending `MkDTO`.
  - `mk:generate-docs` — emit a static OpenAPI 3.x JSON file from all
    registered SmartControllers.
- **Module structure documentation**: see `DEVELOPER_GUIDE.md` for the
  canonical `Mk\Director` namespace layout and MME conventions.

### Changed (1.0.0)

- **Composer constraints** tightened from `^10|^11|^12|^13` → `^13.0`
  for all `illuminate/*` dependencies. This is a hard break for projects
  still on Laravel 12 or earlier.
- **PHP requirement** raised from `^8.2` → `^8.4`.
- **Pest test framework** raised from `^3.0` → `^5.0` (Pest 4 skipped
  deliberately; 5 is the long-term stable line).
- **MkServiceProvider** is now the only auto-discovered provider. The
  `extra.laravel.providers` array in `composer.json` is the source of
  truth (no `config/app.php` registration required on Laravel 11/12/13).

### Removed (1.0.0)

- **Support for Laravel 10, 11, 12**: the package no longer resolves on
  these versions. Downgrade to `0.0.x` if you need them.
- **Support for PHP 8.2 / 8.3**: the package relies on PHP 8.4 language
  features in future minor releases; 1.0.0 still compiles on 8.4 but
  is the floor.

### Fixed (1.0.0)

- `Mk\Director\Models\BaseModelBuilder::cacheGet()` and `cacheFirst()`
  are now consistent about the table-tagging fallback when the cache
  store does not support tags (file / database driver) — they no longer
  crash in CI / SQLite environments.
- `Mk\Director\DTOs\DTOFactory::makeFromArray()` correctly casts
  `datetime` and `timestamp` casts to `Carbon\Carbon` (instead of the
  non-existent `Carbon\DateTime`) via the explicit `Carbon\Carbon::parse()`
  path, fixing a regression introduced in 0.0.1.
- `Mk\Director\DTOs\MkDTO::fromArray()` now throws a clear
  `LogicException` when a `readonly` property is re-hydrated after
  construction, instead of silently failing on PHP 8.4.
- `Mk\Director\Traits\CRUDSmart::getCacheTags()` no longer crashes when
  the controller does not declare an explicit `cache_tags` config — it
  falls back to the model's table name, matching the documented contract.
- `Mk\Director\Services\OpenApiGeneratorService` gracefully degrades to
  `fillable`-based introspection when `Schema::getColumns()` is not
  available (e.g. when doctrine/dbal is not installed), preventing 500s
  on a missing driver in dev.
- `Mk\Director\Managers\PluginManager::auditRequirements()` now reports
  both `error` and `warning` findings without truncating the message,
  and is wired into `mk:status` for end-to-end diagnosability.

### Known issues / Deferred to 1.0.1+

- `Mk\Director\Middleware\MkAuthMiddleware` references a non-existent
  `Mk\Director\Utils\MkResponse` class (a pre-0.0.1 stub that was never
  extracted from the original `condaty` code base). The middleware is
  not auto-registered anywhere and is not loaded by the sandbox
  application, so it does not block 1.0.0 — but the file should be
  either completed (with a proper `MkResponse` utility) or removed in
  1.0.1. Tracking: see `tasks.md` follow-up notes.
- `tests/Unit/ListManagerTest.php` still uses a non-existent
  `new ListManager($request, $config)` constructor — the production
  class is a static-method utility. The sandbox-level
  `tests/Feature/ListManagerApiTest.php` covers the real behaviour
  via the HTTP layer. The unit test is a candidate for deletion in
  1.0.1.

### Security

- No security-relevant changes from 0.0.1 → 1.0.0. The `MkMultiTenantPlugin`
  continues to enforce `client_id` isolation in `beforeQuery`,
  `beforeSave`, and `beforeDelete` — this is the recommended path until
  a `TenantScope` global-scope variant ships in 1.1.
- The global DB→Cache listener (`mk_director.features.auto_cache`) only
  flushes on `INSERT/UPDATE/DELETE` against tables whose names appear
  in the SQL — it does not execute untrusted SQL.

### Upgrade guide from 0.0.x

1. Bump your host application's `composer.json`:
   - `"makroz/director-laravel": "^1.0"`
2. Ensure your host project runs on **PHP 8.4** (`composer.json` → `require.php`).
3. Ensure your host project runs on **Laravel 13**:
   `"laravel/framework": "^13.0"`.
4. If you maintain your own DTOs extending `MkDTO`, audit any `readonly`
   properties — the new hydration guard will surface
   re-initialization attempts as `LogicException`.
5. Update dev dependencies in your host project: `pestphp/pest ^5.0`,
   `pestphp/pest-plugin-laravel ^5.0`.
6. Re-run `php artisan package:discover` after `composer update` so
   `MkServiceProvider` is registered.

[1.0.0]: https://github.com/makromania/mk-director/releases/tag/mk-laravel-v1.0.0
