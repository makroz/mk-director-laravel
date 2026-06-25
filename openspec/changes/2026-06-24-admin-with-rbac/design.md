# Design: `mk:module {Name} --with-rbac` (R-PKG-008)

> **SDD**: `openspec/changes/2026-06-24-admin-with-rbac/`
> **Status**: design (triage GO closed)
> **Rule**: R-G-033-A (genérico)
> **Target release**: `makroz/director-laravel` v1.5.0-rc1
> **Base**: `origin/dev` @ v1.4.0

---

## Technical Approach

Extend `MakeModuleCommand` with a `--with-rbac` flag that scaffolds a
**bounded-context RBAC triad** (User + Role + Ability + 2 pivots) per
generated module. The triad is fully isolated from the package's central
`Auth/Models/{Role,Ability,AuthUser}` and uses its own migration namespace
prefixed by `{moduleNameLower}_` (e.g. `admin_users`, `admin_role_user`,
`admin_ability_role`). Auto-bind a `{Name}Policy` per model with
default-deny semantics, gate by `{moduleNameLower}.{resource}.{action}`
ability strings, and ship an `RbacService` helper bound in the
module's `ServiceProvider`.

The package's central `Auth/Models/{Role,Ability}` + `ability_user`/
`role_user` tables stay untouched — those are for **login scopes**
(via `mk:make:auth-user`), this flag is for **module-internal RBAC**
(authorization of internal actions within a bounded context). The two
can coexist; modules that only need login use `mk:make:auth-user`,
modules that need internal RBAC add `--with-rbac`.

**Reference**: `proposal.md` for the high-level why,
`specs/admin-with-rbac.md` for the 5 requirements (RBAC-001..005), and
`tasks.md` for the implementation track breakdown.

---

## Architecture Decisions

### Decision: D1 — Per-module RBAC isolation (vs. reuse central `roles`/`abilities`)

| Aspect | **Chosen: per-module triad** | Alternative: reuse central `Auth\Models\Role`/`Ability` |
|---|---|---|
| Schema | `{scope}_users`, `{scope}_roles`, `{scope}_abilities`, `{scope}_role_user`, `{scope}_ability_role` (5 tables) | One `roles` row with `guard='{scope}'`, share `abilities` table, only 1 new `{scope}_user` pivot |
| Isolation | Full bounded-context isolation (R-MK-001 ✓) | Couples module RBAC to package AuthUser's tables (violates R-MK-001 spirit) |
| Matches RETO | Yes — RETO's orphan branch built exactly this pattern (3 CRUDs + custom Role/Ability + custom migration) | No — would force RETO to rewrite half its module |
| Migration complexity | 5 migrations per `--with-rbac` call | 1 pivot + 0 role/ability migrations |
| Risk | Table proliferation if 5+ modules use `--with-rbac` (acceptable: most apps have 1-2 admin scopes) | Couples module RBAC semantics to AuthUser's `roles.guard` column |
| Test surface | Per-module fixtures, no cross-module state | Central Role/Ability fixtures, must guard-scope every test |

**Choice**: **per-module triad** (proposal.md is explicit, R-MK-001
favors isolation, matches RETO's actual pattern in the source branch).

### Decision: D2 — User model extends `Illuminate\Foundation\Auth\User`, NOT `Mk\Director\Auth\Models\AuthUser`

| Aspect | **Chosen: foundation User** | Alternative: extend AuthUser |
|---|---|---|
| Rationale | proposal.md line 102-103 explicit: "Admin extends User base, NOT AuthUser — admin NO es un auth user, es un user interno" | Would couple module RBAC to AuthUser's login + Sanctum tokens |
| Trade-off | Module provides its own login flow (via separate `mk:make:auth-user` call) | Single model = simpler but conflates login vs internal RBAC |

**Choice**: **foundation User**. Modules that need login should ALSO
run `mk:make:auth-user {Scope}` (R-PKG-009 covers `--login-field` flag
for that). The two scaffolders are independent and composable.

### Decision: D3 — Pivot naming: `{scope}_role_user` and `{scope}_ability_role`

| Aspect | **Chosen: scope-prefixed** | Alternative: shared `role_user` + `ability_role` |
|---|---|---|
| Collision risk | None (each scope gets its own pivots) | High (R3-014 precedent: `role_user` got FK retrofitted because of cross-table confusion) |
| FK integrity | Each FK references `{scope}_users.id` + central `roles`/`abilities` | All scopes share, can't FK to `{scope}_users` directly |
| Migration safety | New modules only add NEW tables (no retroactive FK fixes) | New modules break existing FK assumptions |

**Choice**: **scope-prefixed**. Aligns with R3-014's lesson ("pivots
without FK = orphaned IDs"). The package's `2026_06_18_000001_add_fk_role_user_to_auth_users.php`
is the reference for idempotent FK hardening — apply that pattern.

### Decision: D4 — Stubs location: `src/Stubs/module-rbac/`

| Aspect | **Chosen: sub-folder** | Alternative: flat `src/Stubs/` |
|---|---|---|
| Convention | Matches Laravel's pattern (`vendor/laravel/framework/src/.../Console/stubs/`) | Pollutes root stubs namespace |
| Discoverability | All RBAC stubs in one folder, easy to grep | Mixed with module + auth-user stubs |
| Reusability | Other flags (e.g. `--with-files` future) can add their own sub-folder | Each flag competes for root-level names |

**Note**: tasks.md T0.2 says `src/Console/Stubs/module-rbac/` but the
**actual existing stub root is `src/Stubs/`** (verified:
`src/Stubs/{controller,model,service,...}.stub` + `auth-user.*.stub`).
This design uses `src/Stubs/module-rbac/` — the correct path. **T0.2
text needs correction** when tasks.md is next updated.

**Choice**: `src/Stubs/module-rbac/` (12 stubs).

### Decision: D5 — Policy auto-binding via `Gate::policy()` + `Gate::define()` per ability

| Aspect | **Chosen: Gate::policy for CRUD + Gate::define for abilities** | Alternative: just `Gate::policy()`, abilities derived from method names |
|---|---|---|
| Pattern | `Gate::policy(Admin::class, AdminPolicy::class)` + `Gate::define('admin.admins.view', fn($u) => $u->hasAbility('admin.admins.view'))` | Auto-derive abilities from policy method names + module prefix |
| Discoverability | Explicit abilities make `mk:discover-abilities` (R-PKG-007) trivial | Implicit abilities require reflection |
| Test ergonomics | `assertGateAllows` / `assertGateDenies` standard Pest patterns | Requires custom test helpers |
| Spec alignment | Matches RBAC-004 (spec says `before()` super-admin bypass + per-ability checks) | Implies spec rework |

**Choice**: **explicit Gate::policy + Gate::define**. Both generated
in `{Name}ModuleServiceProvider::boot()` from a config array. Tests
pin behavior via `$user->can('admin.admins.view')` and
`Gate::allows()` / `Gate::denies()`.

### Decision: D6 — Default-deny + super-admin `before()` bypass (RBAC-004)

| Aspect | **Chosen: default-deny + `before()` super-admin bypass** | Alternative: per-method checks everywhere |
|---|---|---|
| Convention | RBAC-004 spec is explicit: "default-deny + super-admin bypass" | More explicit, more boilerplate, more chances to forget a method |
| Risk | Forgotten ability = silently denies (good for security) | Forgotten check = silently allows (security hole) |
| Standard | Matches Laravel's policy convention | Diverges from Laravel |

**Choice**: **default-deny + super-admin bypass**. Both are scaffolded
into every generated Policy stub. Tests cover both paths (RBAC scenario
2 + 3).

---

## Data Flow

```
┌─────────────────────────────────────────────────────────────────────┐
│  php artisan mk:module Admin --with-rbac                            │
└─────────────────────────────┬───────────────────────────────────────┘
                              │
                              ▼
            ┌──────────────────────────────────────┐
            │  MakeModuleCommand::handle()         │
            │  1. Str::studly($name) → "Admin"     │
            │  2. generateFile() × 11 (standard)   │
            │  3. generateFile() × 12 (RBAC pack)   │
            │  4. registerServiceProvider()         │
            └─────────────────┬────────────────────┘
                              │
            ┌─────────────────▼──────────────────────────────────┐
            │  app/Modules/Admin/                                │
            │  ├── Models/Admin.php          (User base, hasRole, │
            │  │                               hasAbility)       │
            │  ├── Models/Role.php           (BelongsToMany     │
            │  │                               Ability)          │
            │  ├── Models/Ability.php        (BelongsToMany     │
            │  │                               Role)             │
            │  ├── Http/Controllers/AdminController.php          │
            │  ├── Http/Controllers/RoleController.php           │
            │  ├── Http/Controllers/AbilityController.php        │
            │  ├── Policies/AdminPolicy.php   (default-deny +   │
            │  │                               super-admin       │
            │  │                               before())         │
            │  ├── Policies/RolePolicy.php                       │
            │  ├── Policies/AbilityPolicy.php                    │
            │  ├── Services/RbacService.php   (assignRole,       │
            │  │                               syncAbilities,    │
            │  │                               userHasAbility)   │
            │  ├── Database/Migrations/                           │
            │  │   ├── YYYY_MM_DD_HHMMSS_create_admin_users_...   │
            │  │   ├── YYYY_MM_DD_HHMMSS_create_admin_roles_...  │
            │  │   ├── YYYY_MM_DD_HHMMSS_create_admin_abilities  │
            │  │   ├── YYYY_MM_DD_HHMMSS_create_admin_role_user  │
            │  │   └── YYYY_MM_DD_HHMMSS_create_admin_ability_.. │
            │  ├── ... (resto del módulo estándar)               │
            │  └── AdminModuleServiceProvider.php                 │
            │      (register: bind RbacService,                  │
            │       boot: Gate::policy + Gate::define + loadRoutes)│
            └─────────────────────────────────────────────────────┘
                              │
                              ▼
            ┌─────────────────────────────────────────────┐
            │  bootstrap/providers.php (auto-edit)        │
            │  → App\Modules\Admin\AdminModuleServiceProvider::class │
            └─────────────────────────────────────────────┘
```

At runtime (after `php artisan migrate`):

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

---

## File Changes

### New files (12 stubs + 1 source mod)

| File | Action | Description |
|------|--------|-------------|
| `src/Stubs/module-rbac/model-user.stub` | Create | `App\Modules\{{ModuleName}}\Models\{{ModuleName}}` extends `Illuminate\Foundation\Auth\User` + `HasRoles` trait. `$fillable`, `$hidden`, `roles()`, `hasRole()`, `hasAbility()`, `assignRole()`. |
| `src/Stubs/module-rbac/model-role.stub` | Create | `App\Modules\{{ModuleName}}\Models\Role` extends `Model`. `$fillable = ['name', 'description']`. `abilities()` BelongsToMany. `users()` BelongsToMany. |
| `src/Stubs/module-rbac/model-ability.stub` | Create | `App\Modules\{{ModuleName}}\Models\Ability` extends `Model`. `$fillable = ['name', 'description']`. `roles()` BelongsToMany. |
| `src/Stubs/module-rbac/controller-user.stub` | Create | `AdminController` extends `SmartController` (standard CRUD + `assignRole()` action). |
| `src/Stubs/module-rbac/controller-role.stub` | Create | `RoleController` extends `SmartController` (standard CRUD + `syncAbilities()` action). |
| `src/Stubs/module-rbac/controller-ability.stub` | Create | `AbilityController` extends `SmartController` (read-only via `mk:discover-abilities`, write blocked by policy). |
| `src/Stubs/module-rbac/policy-user.stub` | Create | `AdminPolicy` with `before()` super-admin bypass + 5 CRUD methods using `hasAbility()` checks. |
| `src/Stubs/module-rbac/policy-role.stub` | Create | `RolePolicy` (same shape). |
| `src/Stubs/module-rbac/policy-ability.stub` | Create | `AbilityPolicy` (read-only — discover overwrites). |
| `src/Stubs/module-rbac/service-rbac.stub` | Create | `RbacService` with `assignRole`, `revokeRole`, `syncAbilities`, `userHasAbility`, `giveAbilityToRole`. |
| `src/Stubs/module-rbac/migration-user.stub` | Create | `create_{scope}_users_table` with `$table->id()`, `$table->string('name')`, `$table->string('email')->unique()`, `$table->timestamp('email_verified_at')->nullable()`, `$table->string('password')`, `$table->timestamps()`. |
| `src/Stubs/module-rbac/migration-role.stub` | Create | `create_{scope}_roles_table`: `id`, `name` unique, `description` nullable, `timestamps`. |
| `src/Stubs/module-rbac/migration-ability.stub` | Create | `create_{scope}_abilities_table`: `id`, `name` unique, `description` nullable, `timestamps`. |
| `src/Stubs/module-rbac/migration-role-user-pivot.stub` | Create | `create_{scope}_role_user_table`: FK `role_id` → `{scope}_roles.id` cascadeOnDelete, FK `user_id` → `{scope}_users.id` cascadeOnDelete, composite PK `[role_id, user_id]`. |
| `src/Stubs/module-rbac/migration-ability-role-pivot.stub` | Create | `create_{scope}_ability_role_table`: FK `ability_id` → `{scope}_abilities.id` cascadeOnDelete, FK `role_id` → `{scope}_roles.id` cascadeOnDelete, composite PK `[ability_id, role_id]`. |
| `src/Console/Commands/MakeModuleCommand.php` | Modify | Add `--with-rbac` option to `$signature`, branch in `handle()` to load `src/Stubs/module-rbac/` pack when flag is set. |

### New test files

| File | Description |
|------|-------------|
| `tests/Feature/MkModuleWithRbacTest.php` | Pest: scaffolder generates 17 files (5 standard + 12 RBAC), policies bind to Gate, pivots have FK constraints in generated migrations, super-admin bypass works, default-deny enforced. |
| `tests/Fixtures/ModuleRbacFixtures.php` | Pest dataset: 3 users (super-admin, editor, no-role), 2 roles (super-admin, editor), 6 abilities (admin.admins.view/viewAny/create/update/delete + admin.posts.view). |

### R-G-032 sync (per checklist — see `references/08-rg032-checklist.md`)

| # | Location | Change |
|---|---|---|
| 1 | `packagist/mk-director-laravel/CHANGELOG.md` | Add `## [1.5.0-rc1]` block: new `--with-rbac` flag, link to migration notes. |
| 2 | `packagist/mk-director-laravel/DEVELOPER_GUIDE.md` | Add section "Scaffolding modules with RBAC" with end-to-end example. |
| 3 | `packagist/mk-director-laravel/README.md` | Add `--with-rbac` to the command catalog. |
| 4 | `.makromania/agency/skills/mk-director-laravel/SKILL.md` | Reference index entry for new `09-admin-with-rbac.md`. |
| 4b | `.makromania/agency/skills/mk-director-laravel/references/09-admin-with-rbac.md` | NEW: full walkthrough, stub list, customizations, examples. |
| 11 | `.makromania/agency/skills/mk-director-core/SKILL.md` | (no change expected, but verify) |
| 12 | `.makromania/agency/skills/mk-director-web/SKILL.md` | (no change expected, but verify) |
| 13 | `.makromania/agency/skills/mk-director-mobile/SKILL.md` | (no change expected, but verify) |
| 14 | `~/.makromania/agency/global/rules_orchestration.md` | (no change — R-G-033 already covers) |
| 15 | `projects/mk-director/AGENTS.md` | (no change — already references R-PKG-008) |

---

## Interfaces / Contracts

### Stub: `model-user.stub` (template)

```php
<?php

declare(strict_types=1);

namespace App\Modules\{{ModuleName}}\Models;

use App\Modules\{{ModuleName}}\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class {{ModuleName}} extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = '{{moduleNameLower}}_users';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, '{{moduleNameLower}}_role_user');
    }

    public function hasRole(string|int $role): bool
    {
        if (is_int($role)) {
            return $this->roles->contains('id', $role);
        }
        return $this->roles->contains('name', $role);
    }

    public function hasAbility(string $ability): bool
    {
        return $this->roles
            ->flatMap->abilities
            ->pluck('name')
            ->contains($ability);
    }

    public function assignRole(Role $role): void
    {
        $this->roles()->syncWithoutDetaching([$role->id]);
    }
}
```

### Stub: `policy-user.stub` (template)

```php
<?php

declare(strict_types=1);

namespace App\Modules\{{ModuleName}}\Policies;

use App\Modules\{{ModuleName}}\Models\{{ModuleName}};

class {{ModuleName}}Policy
{
    /**
     * Super-admin bypass — standard de facto.
     */
    public function before({{ModuleName}} $user, string $ability): ?bool
    {
        return $user->hasRole('super-admin') ? true : null;
    }

    public function viewAny({{ModuleName}} $user): bool
    {
        return $user->hasAbility('{{moduleNameLower}}.{{moduleNamePluralLower}}.viewAny');
    }

    public function view({{ModuleName}} $user, {{ModuleName}} $model): bool
    {
        return $user->hasAbility('{{moduleNameLower}}.{{moduleNamePluralLower}}.view');
    }

    public function create({{ModuleName}} $user): bool
    {
        return $user->hasAbility('{{moduleNameLower}}.{{moduleNamePluralLower}}.create');
    }

    public function update({{ModuleName}} $user, {{ModuleName}} $model): bool
    {
        return $user->hasAbility('{{moduleNameLower}}.{{moduleNamePluralLower}}.update');
    }

    public function delete({{ModuleName}} $user, {{ModuleName}} $model): bool
    {
        return $user->hasAbility('{{moduleNameLower}}.{{moduleNamePluralLower}}.delete');
    }
}
```

### Stub: `service-rbac.stub` (template)

```php
<?php

declare(strict_types=1);

namespace App\Modules\{{ModuleName}}\Services;

use App\Modules\{{ModuleName}}\Models\{{ModuleName}};
use App\Modules\{{ModuleName}}\Models\Role;
use App\Modules\{{ModuleName}}\Models\Ability;
use Illuminate\Support\Collection;

class RbacService
{
    public function assignRole({{ModuleName}} $user, Role $role): void
    {
        $user->assignRole($role);
    }

    public function revokeRole({{ModuleName}} $user, Role $role): void
    {
        $user->roles()->detach($role->id);
    }

    public function syncAbilities(Role $role, array $abilityNames): void
    {
        $abilityIds = Ability::whereIn('name', $abilityNames)->pluck('id');
        $role->abilities()->sync($abilityIds);
    }

    public function userHasAbility({{ModuleName}} $user, string $ability): bool
    {
        return $user->hasAbility($ability);
    }

    public function giveAbilityToRole(Role $role, Ability $ability): void
    {
        $role->abilities()->syncWithoutDetaching([$ability->id]);
    }
}
```

### Stub: `migration-role-user-pivot.stub` (FK integrity, R-RISK-001)

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{{moduleNameLower}}_role_user', function (Blueprint $table) {
            $table->foreignId('role_id')
                ->constrained('{{moduleNameLower}}_roles')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained('{{moduleNameLower}}_users')
                ->cascadeOnDelete();
            $table->primary(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{{moduleNameLower}}_role_user');
    }
};
```

---

## Testing Strategy

| Layer | What to Test | Approach | File |
|-------|--------------|----------|------|
| **Unit** | Scaffolder generates correct file tree (11 standard + 12 RBAC = 23 files) | Run `mk:module Admin --with-rbac` in test, assert `File::exists()` per expected path. Snapshot the 12 RBAC stub outputs after token replacement. | `tests/Feature/MkModuleWithRbacTest.php` |
| **Unit** | Each migration stub renders valid SQL with correct FK constraints | Parse generated migration file, regex-assert `constrained()->cascadeOnDelete()` on both columns. | `tests/Feature/MkModuleWithRbacTest.php` |
| **Unit** | Policy auto-binding | Boot provider in test, assert `Gate::has('admin.admins.view')` returns true, `Gate::allows()` denies for user without ability. | `tests/Feature/MkModuleWithRbacTest.php` |
| **Integration** | Default-deny: editor role can't `delete` | Seed user with `editor` role + 1 ability (`view`), assert `$user->can('admin.admins.delete') === false`. | `tests/Feature/AdminPolicyTest.php` |
| **Integration** | Super-admin bypass | Seed user with `super-admin` role + 0 abilities, assert `$user->can('admin.admins.delete') === true` via `before()`. | `tests/Feature/AdminPolicyTest.php` |
| **Integration** | FK integrity on pivots | Insert `admin_role_user` row with `role_id=999` (not exists), expect `QueryException` SQLSTATE 23000. | `tests/Feature/MkModuleWithRbacTest.php` |
| **E2E** | Full HTTP request lifecycle | `POST /api/admin/admins` (no auth) → 401. With user w/o `create` ability → 403. With user w/ ability → 201. | `tests/Feature/AdminControllerTest.php` (existing pattern in RETO; port to sandbox) |
| **Sanity** | `php artisan mk:lint:boundaries` passes | Cross-module imports still flagged as errors. `--with-rbac` doesn't introduce new boundary violations. | CI required check (R-MK-001) |

---

## Migration / Rollout

**No migration required** for existing consumers of `makroz/director-laravel`:
this is a purely additive flag. Consumers not using `--with-rbac` see no change.

**For RETO** (the only consumer, R-RET-001 separate sprint):

1. RETO closes branch `makromania/260624-0511--admin-module` (orphan, not merged).
2. RETO creates `makromania/260624-XXXX--admin-with-rbac-from-scratch` from `dev`.
3. RETO deletes `app/Modules/Admin/{Models,Http,Database/Migrations,Services}`.
4. RETO runs `composer require makroz/director-laravel:^1.5.0`.
5. RETO runs `php artisan mk:module Admin --with-rbac --profile-fields=name,phone,avatar`.
6. RETO ports business logic RETO-only (custom profile validators, etc.) over the generated stubs.
7. RETO bumps `composer.json` and merges to `dev`.
8. RETO deprecates the orphan branch (close PR without merging).

**BC**: breaking change for RETO only (it consumes 1.4.0 currently). The
package's central `Auth/Models/{Role,Ability}` + `roles`/`abilities`
tables are untouched, so any other potential consumer of `mk:make:auth-user`
is unaffected.

---

## Open Questions (need Mario sign-off)

- [ ] **Q1**: Confirm per-module RBAC isolation (D1) is the right call —
      not central `roles.guard='admin'` reuse. **Recommended**: per-module
      (matches RETO pattern + R-MK-001 isolation).
- [ ] **Q2**: Should `--with-rbac` also accept `--login-field=<ci|email>`
      to combine with R-PKG-009, or are the two flags strictly orthogonal
      (run `mk:make:auth-user` separately)? **Recommended**: orthogonal,
      composable. (`mk:make:auth-user` adds login to an existing module
      that already has `--with-rbac`).
- [ ] **Q3**: Should we generate a baseline seeder (`admin_users` with
      `super-admin` role, `admin_admins.*` abilities) when `--with-rbac`
      is used? **Recommended**: YES, with a `--no-seeder` flag to opt out.
      Otherwise first-time setup is painful (manual SQL).
- [ ] **Q4**: Where should the tests live: `tests/Feature/` (per Laravel
      convention) or `tests/Integration/`? **Recommended**: `tests/Feature/`
      (matches existing pattern in 1.4.0 suite).

---

## Cross-references

- **Proposal**: `openspec/changes/2026-06-24-admin-with-rbac/proposal.md`
- **Spec**: `openspec/changes/2026-06-24-admin-with-rbac/specs/admin-with-rbac.md` (RBAC-001..005)
- **Tasks**: `openspec/changes/2026-06-24-admin-with-rbac/tasks.md` (Track 0/1/2)
- **Parent**: `openspec/changes/2026-06-24-dogfooding-model/`
- **Companion**: R-PKG-007 (DiscoverAbilities), R-PKG-009 (login field), R-PKG-010 (AuthController RBAC stub)
- **Source**: RETO branch `makromania/260624-0511--admin-module` (orphan, ~1200 LOC, pre-1.4.0)
- **Rule**: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
- **Reference for FK hardening**: `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php` (R3-014 pattern)
- **Existing stub root**: `src/Stubs/` (NOT `src/Console/Stubs/` — tasks.md T0.2 has this wrong)

---

*Author: Mavis (R-G-033-F session `mvs_95e8aa6d93e044668b22f0250005c334`)* ·
*Date: 2026-06-24*
*Branch: `makromania/260624-2150--admin-with-rbac`* ·
*Base: `origin/dev` @ `ad03751`*
