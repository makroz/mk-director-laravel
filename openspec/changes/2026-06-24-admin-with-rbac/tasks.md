# R-PKG-008 — Tasks

**Status**: Track 0 + T1.1/T1.2/T1.3 landed (commit `5fb98a9`). Pendiente: T1.4 (tests), T1.5 (R-G-032 sync), T2.1/T2.2 (RETO retrofit, BLOCKED on R-PKG-007/009/010 + v1.5.0).

---

## Track 0 — Diseño

### T0.1 — Diseñar flag `--with-rbac`  ✅ COMPLETED
**Output**: `design.md` (503 líneas, 6 ADRs D1-D6, 4 open questions Q1-Q4).
**Commit**: `3b3455e docs(laravel): design mk:module --with-rbac (R-PKG-008)`

### T0.2 — Stubs  ✅ COMPLETED (junto con T1.2)
**Files nuevos**: `src/Stubs/module-rbac/` (carpeta con 17 stubs parametrizados).
**Tokens**: `{{ModuleName}}`, `{{moduleNameLower}}`, `{{moduleNamePluralLower}}`, `{{migrationDate}}`.
**Nota**: la ruta del SDD original era `src/Console/Stubs/module-rbac/` (incorrecta). Corregido a `src/Stubs/module-rbac/` que es donde el paquete tiene sus otros stubs (controller.stub, model.stub, etc.) — ver decision D4 en design.md.

---

## Track 1 — Implementación

### T1.1 — Modificar `mk:module` command  ✅ COMPLETED
**File**: `src/Console/Commands/MakeModuleCommand.php`
- ✅ Opción `--with-rbac` agregada al `$signature`.
- ✅ Branch en `handle()` que invoca `generateRbacPack()` cuando el flag está activo.
- ✅ Refactor: `generateStandardPack()` (9 archivos, comportamiento original preservado) + `generateRbacPack()` (20 archivos).
- ✅ Helper `generateMigration()` con timestamps secuenciales (evita colisión cuando se generan 5 en el mismo run).
- ✅ `generateFile()` extendido con `?string $stubFolder = null` para soportar sub-folder de stubs.
**Commit**: `5fb98a9 feat(laravel): mk:module --with-rbac generates RBAC triad (R-PKG-008)` (+161/-15 LOC en MakeModuleCommand.php)

### T1.2 — Stubs  ✅ COMPLETED (junto con T0.2, mismo commit que T1.1)
**17 stubs** en `src/Stubs/module-rbac/` (NO 11+ — el conteo original era estimado):
- 3 Models: `model-user.stub`, `model-role.stub`, `model-ability.stub`
- 3 Controllers: `controller-user.stub`, `controller-role.stub`, `controller-ability.stub`
- 3 Policies: `policy-user.stub`, `policy-role.stub`, `policy-ability.stub`
- 1 Service: `service-rbac.stub` (RbacService — singleton, container-bound)
- 5 Migrations: `migration-user.stub`, `migration-role.stub`, `migration-ability.stub`, `migration-role-user-pivot.stub`, `migration-ability-role-pivot.stub` (FK constraints con cascadeOnDelete, R-RISK-001)
- 1 Routes: `routes-rbac.stub` (los 3 controllers con CRUD + endpoints específicos)
- 1 ServiceProvider: `provider-rbac.stub` (Gate::policy + Gate::define auto-bind)
**Commit**: mismo que T1.1 — `5fb98a9` (+951 LOC en stubs)
**Nota**: el commit prefix `feat(laravel): add rbac module stubs` planeado originalmente NO se usó — todo se agrupó en `5fb98a9` para mantener el PR cohesivo.

### T1.3 — ServiceProvider auto-registro  ✅ COMPLETED
**File**: `src/Stubs/module-rbac/provider-rbac.stub` (NO `AdminServiceProvider` hardcodeado — es `{Name}ModuleServiceProvider`, genérico).
- ✅ Auto-register del ServiceProvider vía `MakeModuleCommand::registerServiceProvider()` (mismo patrón que standard).
- ✅ Policies binding: `Gate::policy($model, $policy)` para los 3 modelos (User, Role, Ability).
- ✅ Gate definitions: `Gate::define($ability, fn($user) => $user->hasAbility($ability))` para cada ability listada en `discoverAbilities()`.
- ✅ `discoverAbilities()` retorna lista explícita de 15 abilities (convención `{scope}.{resource}.{action}`) — fuente de verdad para `mk:discover-abilities` (R-PKG-007).
- ✅ Default-deny + super-admin `before()` bypass en cada Policy (RBAC-004).
- ✅ `RbacService` bindeado como singleton en `register()`.
**Commit**: mismo que T1.1/T1.2 — `5fb98a9`.

### T1.4 — Tests Pest  ⏳ PENDING (próxima sesión)
- `tests/Feature/MkModuleWithRbacTest.php` (~200 LOC estimados).
- Coverage:
  - Scaffolder genera 20 archivos correctamente (assert File::exists).
  - FK constraints en migrations generadas (regex-assert `constrained()->cascadeOnDelete()`).
  - Gate::policy auto-bind funciona (boot provider, assert `Gate::has(...)`).
  - Default-deny: user sin ability → 403.
  - Super-admin bypass: user con role `super-admin` → 200 en cualquier endpoint.
- Necesita: tempdir test pattern, MkLaravelTestCase setup, fixtures de SuperAdmin/editor roles.
**Commit**: `test(laravel): cover mk:module --with-rbac`

### T1.5 — R-G-032 sync  ⏳ PENDING (próxima sesión)
- 16 locations según checklist en `mk-director-laravel/SKILL.md` § "Checklist R-G-032".
- Skill `mk-director-laravel/SKILL.md` + nuevo `references/09-admin-with-rbac.md`.
- 4 archivos del paquete: CHANGELOG, DEVELOPER_GUIDE, README, AGENTS.md del repo.
- 4 archivos del monorepo: docs/guides/GETTING_STARTED, docs/guides/AUTH, docs/API_REFERENCE_LARAVEL, docs/architecture/MODULAR_ARCHITECTURE.
- 4 skills de la agencia: `mk-director-{core,web,mobile}/SKILL.md`.
- 3 globales: `~/.makromania/agency/global/rules_orchestration.md`, `projects/mk-director/AGENTS.md`, `~/.mavis/agents/main/memory/MEMORY.md`.
- 1 consumer: `mariogfos/reto-api` (notificación).
**Commit**: `docs(laravel): sync R-G-032 for --with-rbac`

---

## Track 2 — Validación RETO

### T2.1 — RETO reemplaza su módulo  🚫 BLOCKED (depends on R-PKG-007/009/010 + v1.5.0)
- Rama `makromania/260624-0511--admin-module` se cierra (NO mergeada).
- Nueva rama `makromania/260624-XXXX--admin-with-rbac-from-scratch` en RETO.
- `mk:module Admin --with-rbac` reemplaza los 3 controllers + service custom.
- Lógica de negocio RETO-only se agrega sobre los stubs.
**Commit en RETO**: `refactor(reto): use mk-director --with-rbac instead of custom impl`

### T2.2 — RETO bumpea a v1.5.0  🚫 BLOCKED (depends on T2.1 + v1.5.0)
- `composer require makroz/director-laravel:^1.5.0`
- Smoke test del módulo Admin.

---

## Definition of Done

- [x] Flag funciona, genera 17+ archivos (target era 11+, superamos). ✅ `5fb98a9`
- [ ] Tests Pest verdes (90%+ coverage). ⏳ T1.4
- [ ] R-G-032 sync completo. ⏳ T1.5
- [ ] Tag v1.5.0-rc1 + validación RETO. 🚫 T2.1/T2.2
- [ ] RETO cerró rama huérfana + bumpeó paquete. 🚫 T2.2
