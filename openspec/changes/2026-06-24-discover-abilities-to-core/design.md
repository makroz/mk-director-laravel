# Design: `mk:discover-abilities` (R-PKG-007)

> **SDD**: `openspec/changes/2026-06-24-discover-abilities-to-core/`
> **Status**: design (triage GO closed 2026-06-25)
> **Rule**: R-G-033-A (genérico) — ✅ closed
> **Target release**: `makroz/director-laravel` v1.5.0-rc1 (consume R-PKG-008's `discoverAbilities()` provider)
> **Base**: `origin/dev` @ `609b912` (post-R-PKG-008 closure)
> **Branch**: `makromania/260624-2140--discover-abilities-to-core`

---

## Technical Approach

Provide a single Artisan command, `php artisan mk:discover-abilities`, that
**idempotently populates a module's `{scope}_abilities` table** by reading the
ability list from the module's `ServiceProvider` (preferred source — already
exposed by R-PKG-008's `provider-rbac.stub`) and falling back to PHP 8.4
attributes (`#[\Mk\Director\Auth\Attributes\Ability(...)]`) or docblock
annotations (`@mk-ability name|description`) when the provider does not
implement `discoverAbilities()`. The command defaults to **dry-run** for
safety; `--force` is opt-in for re-syncs.

This makes the scaffolder → discovery → runtime gate chain **explicit and
drift-free**: `mk:module Admin --with-rbac` generates a provider with
`discoverAbilities()` returning 15 abilities; running `mk:discover-abilities
--module=admin` inserts those 15 rows into `admin_abilities`; the
`Gate::define('admin.admins.view', ...)` registered in the same provider
reads from those rows via `RbacService::userHasAbility()`.

**Reference**: `proposal.md` for the high-level why, `tasks.md` for the
implementation track breakdown, and `state.yaml` for the triage rationale.

---

## Architecture Decisions

### Decision D1 — Source of truth = `discoverAbilities()` on module providers (vs. reflection over controllers)

| Aspect | **Chosen: consume provider** | Alternative: re-parse controllers / models |
|---|---|---|
| Single source of truth | One list per module (provider's `discoverAbilities()` array). Tests, Gate::define, and DB rows all read from it. | Two sources (controller reflection + provider) that must stay in sync — drift risk. |
| Reuses R-PKG-008 | ✅ Consumes the array that `provider-rbac.stub` already returns. Zero new content for `--with-rbac` modules. | Would need a separate parser duplicating the 15 abilities. |
| BC for pre-1.5.0 apps | App that did NOT use `--with-rbac` has no `discoverAbilities()`. Fallback chain: provider → attribute → docblock. | Would force every pre-1.5.0 app to migrate to `--with-rbac` first. |
| Test ergonomics | Mock the provider with a fixture returning `[...abilities]` — trivial. | Reflection-based parser needs fixture PHP files + namespace setup. |
| Computed cost | O(n) over the provider's array (typically 10-30 abilities). | O(n × methods) over controllers with docblock parsing per method. |

**Choice**: **consume provider** as the primary source. This is
non-negotiable: R-PKG-008's provider already returns the exact list we need,
and re-parsing would create a second source of truth that can drift.

### Decision D2 — PHP 8.4 attribute as primary source, docblock as fallback

| Aspect | **Chosen: attribute first, docblock fallback** | Alternative: docblock only | Alternative: attribute only |
|---|---|---|---|
| PHP version requirement | Attribute path needs PHP 8.4+. Docblock fallback supports 8.1+. | Works on all PHP versions (8.1+). | Forces PHP 8.4+ for ALL consumers of `--with-rbac`. |
| Reflection cost | O(1) attribute lookup via Reflection. | O(n) docblock regex parse per method. | Same as chosen. |
| Readability | Inline metadata, IDE-friendly. | Requires looking at the comment above. | Same as chosen. |
| Falls back gracefully | ✅ Yes — if attribute is missing, parser checks docblock. | N/A | ❌ Pre-8.4 apps break. |
| SPEC consistency | Matches R-PKG-008 D5 ("explicit abilities make `mk:discover-abilities` trivial"). | Diverges from R-PKG-008 design intent. | Diverges from BC policy (R-G-033-C). |

**Choice**: **attribute primary, docblock fallback**. Attribute is
`#[\Attribute(\Attribute::TARGET_METHOD)]` (repeatable, no arguments
needed beyond the constructor). The constructor accepts
`string $name, ?string $description = null`. Docblock format is
`@mk-ability name` or `@mk-ability name description` (must be preceded by
`@mk-ability`, NOT `@ability` — the `mk-` prefix avoids collision with
other tools like ApiGen).

### Decision D3 — `--dry-run` default (safety), `--force` opt-in

| Aspect | **Chosen: dry-run default + force opt-in** | Alternative: write by default + `--dry-run` flag | Alternative: interactive prompt |
|---|---|---|---|
| Default safety | No DB writes unless explicitly opted in. | First-time run accidentally inserts rows. | Prompts block CI/CD pipelines. |
| RETO BC | RETO's orphan command had no `--dry-run` default — always wrote. Migration to v1.5.0 requires opt-in (`--force`). | RETO migration is silent (no behavior change). | RETO pipeline would hang on prompt. |
| Discoverable behavior | CI can run `mk:discover-abilities --json` to lint the discoverable list without side effects. | CI must guard against accidental writes via separate flag. | CI scripts need `--no-interaction` plus extra flags. |
| Predictability | Two states: dry-run (read) or write (force). | Three: read, write, dry-run-on-write. | Depends on stdin availability. |

**Choice**: **dry-run default, `--force` opt-in**. Matches the principle
of least surprise (running a command does not mutate state unless you
explicitly ask). `--json` outputs the would-be inserts as JSON for
machine consumption (CI linting, dashboards).

### Decision D4 — Auto-register on boot when `mk_director.features.auto_discover_abilities = true`

| Aspect | **Chosen: opt-in via config flag** | Alternative: always run on boot | Alternative: never auto-register |
|---|---|---|---|
| Performance | Off by default → zero overhead. On → runs once per boot, cached via Gate facade. | Every request pays the cost even if abilities are static. | Forces manual `php artisan mk:discover-abilities` post-deploy. |
| Dev ergonomics | `MK_AUTO_DISCOVER_ABILITIES=true` in `.env` for sandbox/dev only. | Sandbox suffers, prod may have stale cache. | Easy to forget → stale abilities. |
| Idempotency | UPSERT is idempotent. Same result on every run. | Same. | N/A. |
| BC | Off by default → existing apps see no behavior change. | Silent BC break for all consumers. | BC safe but UX worse. |

**Choice**: **opt-in via `mk_director.features.auto_discover_abilities`**.
Mirrors the existing `auto_cache`, `dynamic_joins`, etc. toggles in
`config/mk_director.php`. When enabled, `MkServiceProvider::boot()`
schedules the discovery after all module providers are registered
(after `Application::bootProviders()`).

### Decision D5 — Module path resolution via config, not hardcoded

| Aspect | **Chosen: `config('mk_director.paths.modules')`** | Alternative: hardcoded `app_path('Modules')` (RETO) | Alternative: PSR-4 root resolution |
|---|---|---|---|
| Default for mk-director convention | `app_path('Modules')` (matches `mk:module` scaffolder output). | Same default but no override. | PSR-4 root from composer.json. |
| Override capability | `mk_director.paths.modules = base_path('custom/Modules')` per project. | None — fork required. | None — Composer-driven. |
| BC for RETO | RETO uses `app_path('Modules')` → config default matches → zero config changes. | Matches RETO but no flexibility. | RETO composer uses `App\\` → maps correctly. |
| Multi-monorepo support | Each package consumer can use a different path. | Forces `app_path('Modules')`. | Forces PSR-4 root. |

**Choice**: **config-driven, default to `app_path('Modules')`**.
Adds `'paths' => ['modules' => env('MK_MODULES_PATH', app_path('Modules'))]`
to `config/mk_director.php`. Aligns with R-PKG-008's `bootstrap/providers.php`
auto-edit (which assumes `app/Modules`).

### Decision D6 — Scope detection: prefer `{scope}_abilities` over central `abilities`

| Aspect | **Chosen: scope-prefixed table when module is specified, central when not** | Alternative: always central | Alternative: only scope-prefixed |
|---|---|---|---|
| Matches R-PKG-008 | ✅ `{scope}_abilities` tables are the R-PKG-008 convention. | Diverges from RBAC triad. | Breaks `mk:make:auth-user` (which uses central `abilities`). |
| Module-less run | `mk:discover-abilities` (no `--module`) scans all modules and inserts per-scope. | Central table gets cross-scope rows → scope confusion. | Requires `--module=*` always — friction. |
| Central scope use | `mk:discover-abilities --scope=auth` for `mk:make:auth-user` flow. | Same. | Same. |
| Table collision | None — each scope has its own table. | High — multi-scope apps collide. | None. |

**Choice**: **scope-prefixed when `--module` (or `--scope`) is given**,
fall back to scanning all modules. The `module` and `scope` arguments are
synonyms (lowercase plural of the module name, e.g. `admin` for `Admin`).

### Decision D7 — Fallback chain: provider → attribute → docblock

When the module provider does NOT implement `discoverAbilities()`:

1. **Attribute scan**: walk `app/Modules/{Name}/Http/Controllers/*.php`,
   collect `#[\Mk\Director\Auth\Attributes\Ability(...)]` on public methods.
2. **Docblock scan**: for the same files, regex `/@mk-ability\s+([a-z0-9._*-]+)(?:\s+(.+))?/i`
   on the method's docblock.
3. **Combine + dedupe** with the provider's list if both exist.
4. **Log the source** at the table footer: `Sources: provider=15, attribute=0, docblock=0`.

This means a pre-1.5.0 app that has controllers with `@mk-ability` docblocks
(or PHP 8.4 attributes) gets auto-discovery without rewriting anything.
The chain is **additive** — if both provider AND attributes exist, the
union is used (no dedup needed because ability names are unique by
convention).

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  php artisan mk:discover-abilities --module=admin                  │
└─────────────────────────────┬───────────────────────────────────────┘
                              │
                              ▼
            ┌─────────────────────────────────────────────┐
            │  DiscoverAbilitiesCommand::handle()         │
            │  1. Resolve --module → $scope               │
            │  2. Resolve paths.modules via config        │
            │  3. Discover providers (per R-PKG-008)      │
            │  4. For each provider: collect abilities     │
            │  5. Fallback: attributes → docblocks        │
            │  6. UPSERT into {scope}_abilities           │
            └─────────────────┬───────────────────────────┘
                              │
              ┌───────────────┼────────────────┐
              │               │                │
              ▼               ▼                ▼
    ┌─────────────────┐ ┌────────────┐ ┌─────────────────┐
    │ Provider source │ │ Attribute  │ │ Docblock source │
    │ (preferred)     │ │ source     │ │ (fallback)      │
    │                 │ │            │ │                 │
    │ {Name}Module    │ │ Controllers│ │ Controllers     │
    │ ServiceProvider │ │ with       │ │ with @mk-ability│
    │ ::discoverAbili │ │ #[Ability] │ │ tags            │
    │ ties() → array  │ │ attributes │ │                 │
    └─────────────────┘ └────────────┘ └─────────────────┘
                              │
                              ▼
            ┌─────────────────────────────────────────────┐
            │  DB::table('{scope}_abilities')->upsert(    │
            │    ['name' => ..., 'description' => ...],   │
            │    ['name'],                                │
            │    ['description']                          │
            │  )                                          │
            │  → idempotente: corre N veces = mismo estado│
            └─────────────────────────────────────────────┘
```

At runtime (after `php artisan migrate` + `mk:discover-abilities`):

```
HTTP request → /api/admin/admins
   │
   ▼
AdminController::__invoke() (extends SmartController)
   │
   ▼
$this->authorize('viewAny', Admin::class)  ← Gate::policy lookup
   │
   ├─ super-admin? → before() returns true → allow
   ├─ hasAbility('admin.admins.viewAny')? → allow
   └─ otherwise → 403 (default-deny)
```

The `hasAbility()` chain reads from `admin_abilities` (populated by
`mk:discover-abilities`) via the `RbacService`.

---

## File Changes

### New files

| File | Action | Description |
|------|--------|-------------|
| `src/Console/Commands/DiscoverAbilitiesCommand.php` | Create | Main command class. Signature `mk:discover-abilities {--module=*} {--dry-run} {--json} {--force}`. Boots container, resolves scope, calls providers, walks attributes/docblocks, UPSERTs. |
| `src/Auth/Attributes/Ability.php` | Create | PHP 8.4 attribute `#[\Attribute(\Attribute::TARGET_METHOD)]` with constructor `__construct(string $name, ?string $description = null)`. |
| `src/Auth/Attributes/README.md` | Create | Quick reference for the attribute. |
| `tests/Feature/DiscoverAbilitiesCommandTest.php` | Create | Pest: source-of-truth from provider, attribute, docblock fallback, dry-run, force, scope detection, UPSERT idempotency, multi-module scan. |
| `tests/Fixtures/DiscoverAbilitiesFixtures.php` | Create | Pest dataset: 3 fake modules (provider-based, attribute-based, docblock-based). |

### Modified files

| File | Action | Description |
|------|--------|-------------|
| `src/MkServiceProvider.php` | Modify | Register `DiscoverAbilitiesCommand::class` in `commands()` array. Add optional `boot()` hook for `auto_discover_abilities` config flag. |
| `config/mk_director.php` | Modify | Add `paths.modules` (default `app_path('Modules')`) and `features.auto_discover_abilities` (default `false`). |

### Auto-generated consumer files (per `--with-rbac` module)

These are NOT modified in this sprint — they already exist from R-PKG-008:

- `app/Modules/{Name}/{Name}ModuleServiceProvider.php` — generated with `discoverAbilities()` returning the 15 abilities.
- `app/Modules/{Name}/Models/Ability.php` — Eloquent model with `$table = '{moduleNameLower}_abilities'`.

---

## Interfaces / Contracts

### Command signature

```php
protected $signature = 'mk:discover-abilities
                        {--module=* : Scope(s) a procesar. Vacío = todos los módulos}
                        {--dry-run : Preview sin escribir a DB (default: true si no se pasa --force)}
                        {--force : Escribir/actualizar filas en {scope}_abilities}
                        {--json : Output en JSON en vez de tabla humana}';
```

### `Ability` attribute

```php
<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Ability
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {}
}
```

Usage in a controller method:

```php
use Mk\Director\Auth\Attributes\Ability;

class PostController
{
    #[Ability('posts.viewAny', 'Listar posts')]
    public function index(Request $request) { /* ... */ }

    #[Ability('posts.create', 'Crear un post')]
    public function store(StorePostRequest $request) { /* ... */ }
}
```

### Auto-discovery algorithm (pseudocode)

```php
$scope = $this->resolveScope($moduleArg);          // 'admin'
$abilities = collect();

// 1. Provider source (preferred).
$providerClass = "App\\Modules\\{$module}\\Providers\\{$module}ModuleServiceProvider";
if (class_exists($providerClass) && method_exists($providerClass, 'discoverAbilities')) {
    $abilities = $abilities->merge(
        app($providerClass)->discoverAbilities()
    );
}

// 2. Attribute source (PHP 8.4+).
foreach ($this->findControllerClasses($module) as $class) {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods() as $method) {
        foreach ($method->getAttributes(Ability::class) as $attr) {
            $instance = $attr->newInstance();
            $abilities->push(['name' => $instance->name, 'description' => $instance->description]);
        }
    }
}

// 3. Docblock source (fallback).
foreach ($this->findControllerClasses($module) as $class) {
    $reflection = new ReflectionClass($class);
    foreach ($reflection->getMethods() as $method) {
        $doc = $method->getDocComment();
        if ($doc && preg_match('/@mk-ability\s+([a-z0-9._*-]+)(?:\s+(.+))?/i', $doc, $m)) {
            $abilities->push(['name' => $m[1], 'description' => $m[2] ?? null]);
        }
    }
}

$abilities = $abilities->unique('name')->values();

if (!$isDryRun && !$isForce) {
    $this->warn('DRY-RUN: pasando --force para escribir.');
    return self::SUCCESS;
}

// UPSERT (idempotent).
DB::table("{$scope}_abilities")->upsert(
    $abilities->map(fn($a) => [
        'name' => $a['name'],
        'description' => $a['description'],
        'created_at' => now(),
        'updated_at' => now(),
    ])->all(),
    ['name'],                                  // unique key
    ['description', 'updated_at']             // columns to update on conflict
);
```

---

## Testing Strategy

| Layer | What to Test | Approach | File |
|-------|--------------|----------|------|
| **Unit** | Provider source — 15 abilities from `discoverAbilities()` are inserted | Mock provider via fixture, run command, assert DB rows. | `DiscoverAbilitiesCommandTest.php` |
| **Unit** | Attribute source — 3 abilities from `#[\Ability(...)]` on 3 methods | Fixture controllers with 3 attributes, run command, assert DB rows. | Same. |
| **Unit** | Docblock source — 2 abilities from `@mk-ability` on 2 methods | Fixture controllers with 2 docblocks, run command, assert DB rows. | Same. |
| **Unit** | Combined source — provider + attribute + docblock deduped to union | Fixture with all 3, run, assert unique count. | Same. |
| **Unit** | `--dry-run` (default) inserts nothing | Run without `--force`, assert 0 rows. | Same. |
| **Unit** | `--force` inserts all | Run with `--force`, assert N rows. | Same. |
| **Unit** | Idempotency — run twice with `--force`, same final state | Run, count rows, run again, count rows == same. | Same. |
| **Unit** | Scope detection — `--module=admin` writes to `admin_abilities`, not `abilities` | Run, assert table name + row count. | Same. |
| **Unit** | Multi-module scan — no `--module` flag, scans all modules | Run without flag, assert per-scope rows. | Same. |
| **Unit** | `--json` output structure | Run with `--json --dry-run`, assert JSON shape. | Same. |
| **Unit** | Provider not implementing `discoverAbilities()` falls back to attributes | Fixture provider without method, assert attributes used + log. | Same. |
| **Integration** | `mk:module Admin --with-rbac` end-to-end: scaffold + discover → Gate allows | Sandbox end-to-end (deferred to R-RET-001). | (Sandbox) |

---

## Migration / Rollout

**No migration required** for existing consumers: this is purely additive.

**For RETO** (the only consumer, R-RET-001 separate sprint):

1. RETO closes orphan branch `makromania/260624-0511--admin-module` (5019 LOC, pre-1.4.0).
2. RETO creates `makromania/260624-XXXX--admin-with-rbac-from-scratch` from `dev`.
3. RETO deletes `app/Modules/Admin/{Console,Models,Http,Database/Migrations,Services}`.
4. RETO runs `composer require makroz/director-laravel:^1.5.0`.
5. RETO runs `php artisan mk:module Admin --with-rbac`.
6. RETO runs `php artisan mk:discover-abilities --module=admin --force`.
7. RETO ports business logic RETO-only over the generated stubs.
8. RETO bumps `composer.json` and merges.

**BC**: this is the FIRST sprint that ships the v1.5.0 API to consumers
that don't yet use `--with-rbac`. For those, nothing changes (the
command is opt-in). For `--with-rbac` consumers (i.e. RETO post-step 5),
the command's behavior is part of the contract.

**Auto-register on boot** is OFF by default — no behavior change for any
existing app. Enable explicitly via `mk_director.features.auto_discover_abilities = true`.

---

## Open Questions (need Mario sign-off)

- [ ] **Q1**: Confirm source-of-truth = `discoverAbilities()` on provider (D1).
      **Recommended**: YES. R-PKG-008 already exposes this array; consuming it
      avoids drift. Fallback to attributes/docblocks for pre-1.5.0 apps.
- [ ] **Q2**: Confirm PHP 8.4 attribute as primary, docblock as fallback (D2).
      **Recommended**: YES. Attribute is type-safe, IDE-friendly, and
      O(1) via Reflection. Docblock fallback supports pre-8.4 apps
      without forcing a PHP upgrade.
- [ ] **Q3**: Confirm dry-run default with `--force` opt-in (D3).
      **Recommended**: YES. Principle of least surprise — running the
      command should not mutate state unless explicitly asked. CI can
      run `--json` to lint without writes.

---

## Cross-references

- **Proposal**: `openspec/changes/2026-06-24-discover-abilities-to-core/proposal.md`
- **Spec**: TBD (will be created in T1.x if needed — RBAC triad spec already covers abilities table)
- **Tasks**: `openspec/changes/2026-06-24-discover-abilities-to-core/tasks.md`
- **State**: `openspec/changes/2026-06-24-discover-abilities-to-core/state.yaml` (v2, triage GO)
- **Parent**: `openspec/changes/2026-06-24-dogfooding-model/`
- **Downstream**: R-PKG-008 (`mk:module --with-rbac` exposes `discoverAbilities()`)
- **Companion**: R-PKG-009, R-PKG-010, R-RET-001
- **Source**: RETO branch `makromania/260624-0511--admin-module` (orphan, pre-1.4.0, not merged)
- **Rule**: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
- **Reference pattern**: `src/Stubs/module-rbac/provider-rbac.stub` (R-PKG-008) — the `discoverAbilities()` method shape we consume.

---

*Author: Mavis (R-G-033-F session `mvs_d7b0d9916d9d4c87b2da793a6aba6fb2`) ·
Date: 2026-06-25 ·
Branch: `makromania/260624-2140--discover-abilities-to-core` ·
Base: `origin/dev` @ `609b912`*