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

### Decision D1 — Source of truth: HYBRID (provider primary, attribute + docblock as fallback ONLY when provider absent)

| Aspect | **Chosen: hybrid — provider OR (attributes + docblock), NOT both** | Alternative: chain (combine all sources) | Alternative: reflection only |
|---|---|---|---|
| When provider implements `discoverAbilities()` | ✅ Use ONLY the provider array. Attributes/docblocks ignored. | Combine (drift risk if names diverge). | Ignore provider, re-parse. |
| When provider does NOT implement it | Fallback to union of attributes + docblocks. | Same. | Same. |
| Single source of truth per module | One source per module — never mixed. | Multiple sources combined at runtime. | Always one (reflection). |
| Reuses R-PKG-008 | ✅ Consumes the array `provider-rbac.stub` returns. | Same. | Would need a separate parser. |
| BC for pre-1.5.0 apps | App without `discoverAbilities()` falls back to attribute/docblock parsing. | Same. | Same. |

**Choice** (Mario sign-off 2026-06-25, Q1=hybrid): **provider if present, else
attributes + docblocks combined, NEVER mix**. The fallback path
(attribute + docblock union) only activates when the provider is absent
or doesn't implement `discoverAbilities()`. Rationale: when an app has
migrated to `--with-rbac`, the provider is the canonical declaration —
mixing attributes on top would risk silent drift. The fallback path is
for pre-1.5.0 apps that don't yet use `--with-rbac`.

This is a refinement of my original D1 recommendation (which proposed a
chain). Mario's hybrid preference is cleaner: exactly one source per
module, no risk of double-listing.

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

### Decision D3 — Interactive prompt with CI escape hatch (Mario sign-off, Q3)

| Mode | Behavior |
|---|---|
| **Interactive (default)** | `$this->confirm('¿Escribir las abilities a la tabla {scope}_abilities? [y/N]')`. User types y/n. Default answer is **No**. |
| **`--force` flag** | Skip prompt. Always write. Used by humans who already know what they want, and by sandbox scripts that pass it explicitly. |
| **`--dry-run` flag** | Skip prompt. Never write. Used by humans previewing, and by CI linters with `--json`. |
| **`--no-interaction` (Laravel global flag)** | Auto-supplied by CI / scheduled commands. Laravel's `confirm()` returns false under `--no-interaction`. Combined with `--force`, writes happen. Without `--force`, nothing happens. |

**Choice** (Mario sign-off 2026-06-25, Q3=interactive): **interactive
prompt with safety net**. The prompt defaults to **No** (must type `y` to
confirm). This is the explicit "are you sure?" gate Mario wants — humans
running it locally see the prompt; CI pipelines pass `--force` to skip.
Laravel's `--no-interaction` global flag means a non-interactive run
without `--force` is a safe no-op (prompt returns false → nothing
happens).

This is a refinement of my original D3 recommendation (dry-run default).
Mario prefers the interactive confirmation gate over a default-deny
because it surfaces intent at the moment of execution rather than via
flag archaeology. The trade-off (potential CI hang) is mitigated by
the `--no-interaction` semantics baked into Artisan.

Implementation sketch:

```php
$shouldWrite = false;
if ($this->option('dry-run')) {
    $shouldWrite = false;
} elseif ($this->option('force')) {
    $shouldWrite = true;
} else {
    $shouldWrite = $this->confirm(
        "¿Escribir las abilities a la tabla {$scope}_abilities?",
        false  // default = No
    );
}
if (!$shouldWrite) {
    $this->info('DRY-RUN: no se escribió nada.');
    return self::SUCCESS;
}
```

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
                        {--dry-run : Preview sin escribir a DB (skip prompt, never write)}
                        {--force : Escribir/actualizar filas en {scope}_abilities (skip prompt, always write)}
                        {--json : Output en JSON en vez de tabla humana}';
```

Behavior matrix:

| `--dry-run` | `--force` | TTY? | Result |
|---|---|---|---|
| ✓ | ✗ | * | No writes. Print preview. |
| ✗ | ✓ | * | Writes. No prompt. |
| ✗ | ✗ | TTY | Prompt "¿Escribir? [y/N]". Default No. |
| ✗ | ✗ | no-TTY (CI) | No writes (Laravel `confirm()` returns false under `--no-interaction`). |
| ✓ | ✓ | * | Last-flag-wins or explicit error? Decision: error "No combines --dry-run y --force". |

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

### Auto-discovery algorithm (pseudocode — D1 hybrid semantics)

```php
$scope = $this->resolveScope($moduleArg);          // 'admin'
$abilities = collect();
$sourceUsed = null;

// 1. Provider source (preferred) — IF present, attributes/docblocks are IGNORED.
$providerClass = "App\\Modules\\{$module}\\Providers\\{$module}ModuleServiceProvider";
if (class_exists($providerClass) && method_exists($providerClass, 'discoverAbilities')) {
    $abilities = collect(app($providerClass)->discoverAbilities())
        ->map(fn(string $name) => ['name' => $name, 'description' => null]);
    $sourceUsed = 'provider';
}

// 2. Fallback: attributes + docblocks combined (only if NO provider).
if ($sourceUsed === null) {
    foreach ($this->findControllerClasses($module) as $class) {
        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            // 2a. Attribute (preferred when in fallback path).
            foreach ($method->getAttributes(Ability::class) as $attr) {
                $instance = $attr->newInstance();
                $abilities->push(['name' => $instance->name, 'description' => $instance->description]);
            }
            // 2b. Docblock (additional, when in fallback path).
            $doc = $method->getDocComment();
            if ($doc && preg_match('/@mk-ability\s+([a-z0-9._*-]+)(?:\s+(.+))?/i', $doc, $m)) {
                $abilities->push(['name' => $m[1], 'description' => $m[2] ?? null]);
            }
        }
    }
    $abilities = $abilities->unique('name')->values();
    $sourceUsed = 'attribute+docblock';
}

// 3. Decide whether to write.
$shouldWrite = match (true) {
    $this->option('dry-run') => false,
    $this->option('force')   => true,
    default                  => $this->confirm(
        "¿Escribir las abilities a la tabla {$scope}_abilities?",
        false  // default = No
    ),
};

if (!$shouldWrite) {
    $this->info("DRY-RUN ({$sourceUsed}): no se escribió nada.");
    $this->previewAbilities($abilities, $scope);
    return self::SUCCESS;
}

// 4. UPSERT (idempotente).
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

$this->info("{$sourceUsed}: {$abilities->count()} abilities upserted en {$scope}_abilities.");
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

## Open Questions (CLOSED 2026-06-25, Mario sign-off)

- [x] **Q1** — Source-of-truth: **HYBRID** (provider primario, attribute+docblock como fallback ÚNICO cuando no hay provider).
      **Mario's choice**: hybrid (Q1 option 3). Rationale: una sola fuente por módulo, sin riesgo de drift. Cuando el provider implementa `discoverAbilities()`, los attributes/docblocks se IGNORAN. El fallback (attribute + docblock combinados) solo activa cuando el provider no está.
- [x] **Q2** — Attribute primary + docblock fallback: **CONFIRMED**.
      **Mario's choice**: attr-primary (Q2 option 1). Rationale: type-safe, O(1) via Reflection, IDE-friendly. Docblock fallback para apps pre-8.4.
- [x] **Q3** — Interactive prompt con `--force` skip + `--dry-run` skip + `--no-interaction` safety: **CONFIRMED**.
      **Mario's choice**: interactive (Q3 option 3). Implementation: `$this->confirm(..., false)` con escape hatch via `--no-interaction` (Laravel global flag → confirm retorna false → safe no-op). CI pipelines pasan `--force` para skip.

## Decisions matrix (final)

| Q | Decision | Trade-off | Mitigation |
|---|---|---|---|
| Q1 | Hybrid: provider OR (attribute+docblock), never both | Falls back to reflection when provider missing | Fixture-only tests cover both paths |
| Q2 | PHP 8.4 attribute primary | Forces PHP 8.4+ for attribute use | Docblock fallback keeps 8.1+ apps working |
| Q3 | Interactive prompt with CI escape hatch | Could hang in old CI without `--no-interaction` | Document `--no-interaction` behavior; CI scripts pass `--force` |

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