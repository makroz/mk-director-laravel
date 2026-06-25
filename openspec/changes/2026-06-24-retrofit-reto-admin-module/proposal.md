# R-RET-001 — RETO módulo Admin retrofit (con skills v1.5.0+)

**Sprint ID**: `2026-06-24-retrofit-reto-admin-module`
**Parent**: `2026-06-24-dogfooding-model` (R-G-033)
**Branch RETO**: `makromania/260624-2220--retrofit-reto-admin-module` (a crear, post-bloqueo)
**Base**: `projects/reto/reto-api` @ `origin/dev` (branch actual: `makromania/260624-1605--bump-director-1.3.1`)
**Status**: BLOCKED — espera R-PKG-007/008/009/010 mergeados + publicados en v1.5.0+

---

## Why

RETO implementó el módulo Admin en la rama `makromania/260624-0511--admin-module` (+5019 LOC, 43 archivos, NO mergeada). Ese trabajo es:

- **Pre-1.4.0**: escrito contra `makroz/director-laravel` v1.3.0 (la rama bumpeó `v1.2.6 → v1.3.0` en commit `3dd559a`).
- **Sin skills indexed**: el agente que lo escribió NO tenía `mk-director-laravel/SKILL.md` con las 8 references ni R-G-032 sync.
- **Sin R-MK-001 estricto**: la regla MME existe desde 1.0.0 pero la implementación no se verificó con `mk:lint:boundaries`.
- **Inconsistente con el paquete**: AuthController usa `JsonResponse` ad-hoc (pre-1.4.0), no envelope estándar.

Si RETO mergea esta rama tal cual a `dev`, queda con deuda técnica acumulada. **El modelo dogfooding-first dice**: mientras el paquete está vivo, NO aceptar trabajo del consumer que diverge del paquete. **Releer y rehacer**.

---

## What changes

### Acción inmediata (este sprint): Inventariar rama huérfana

**Output**: `inventory.md` con análisis de cada archivo del branch huérfano.

**Estructura del inventario**:

| Archivo | LOC | Tier | Acción |
|---|---|---|---|
| AdminController.php | 157 | Tier 1 — boilerplate | Regenerar con `mk:module Admin --with-rbac` |
| RoleController.php | 104 | Tier 1 — boilerplate | Regenerar |
| AbilityController.php | 101 | Tier 1 — boilerplate | Regenerar |
| AuthController.php | 322 | Tier 2 — lógica única | Regenerar con `--login-field=ci --with-auth-rbac` + preservar lógica RBAC |
| AdminService.php | 164 | Tier 2 — lógica única | Regenerar base + portar lógica RETO-only |
| AdminRepository.php | 106 | Tier 1 — boilerplate | Regenerar |
| DiscoverAbilitiesCommand.php | 268 | Tier 2 — paquete | Reemplazar por `mk:discover-abilities` del paquete |
| Admin.php (Model) | 200 | Tier 2 — model + profile fields | Regenerar + override para profile fields |
| AdminFactory.php | 77 | Tier 1 — boilerplate | Regenerar |
| Migration (admin) | — | Tier 1 — boilerplate | Regenerar |
| Migration (profile fields) | 62 | Tier 2 — RETO-only | Override después de scaffoldear |
| Tests Feature (~650 LOC) | 650 | Tier 4 — referencia | Regenerar + portar lógica de tests |
| Postman collection | 789 | Tier 3 — docs | Mantener como docs, regenerar con paquete |
| OpenAPI spec | 834 | Tier 3 — docs | Regenerar (auto-gen del paquete) |
| config/mk_director.php | 135 | Tier 1 — boilerplate | Regenerar con defaults |
| config/auth.php | 22 | Tier 1 — boilerplate | Regenerar con snippets del scaffolder |

### Acción posterior (sprint post-v1.5.0): Retrofit real

**Pasos**:

1. **Releer skills actualizadas**:
   - `~/.makromania/agency/skills/mk-director-laravel/SKILL.md` (índice)
   - 8 references del paquete (`references/01-scaffolders.md`, `02-smart-controller.md`, `03-base-controller.md`, `04-auth-flow.md`, `05-plugins.md`, `06-r-mk-001-mme.md`, `07-update-strategy.md`, `08-rg032-checklist.md`).
   - `~/.mavis/agents/main/skills/mk-director-laravel/` (copia local).
   - 6+ nuevos references según R-PKG-007/008/009/010.

2. **Bumpear paquete**:
   ```bash
   composer require makroz/director-laravel:^1.5.0
   ```

3. **Re-scaffoldear módulo Admin**:
   ```bash
   # Borrar rama huérfana (NO mergeada, seguro)
   git branch -D makromania/260624-0511--admin-module

   # Nueva rama limpia
   git checkout -b makromania/260624-XXXX--reto-admin-with-v150 origin/dev

   # Borrar módulo Admin viejo
   rm -rf app/Modules/Admin

   # Regenerar con v1.5.0
   php artisan mk:module Admin --with-rbac
   php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac
   ```

4. **Portar lógica RETO-only** sobre los stubs:
   - Profile fields custom en el Model.
   - Lógica de negocio en `AdminService`.
   - Tests Feature que cubren casos RETO.

5. **Verificar MME compliance**:
   ```bash
   php artisan mk:lint:boundaries
   ```

6. **Actualizar Postman collection** con la nueva estructura.

7. **Smoke test end-to-end**:
   - `php artisan migrate`
   - `php artisan mk:discover-abilities --module=Admin --dry-run`
   - `php artisan serve` + curl manual a `/api/admin/auth/login` con `ci`.
   - Pest tests verdes.

8. **PR a dev**:
   ```bash
   git push origin makromania/260624-XXXX--reto-admin-with-v150
   gh pr create --base dev --title "feat(admin): RETO Admin module on v1.5.0+"
   ```

### Acción del paquete (R-G-033-C, notificar BC): NINGUNA

Este sprint NO genera BC en el paquete. Es 100% trabajo del consumer. **El paquete ya publicó v1.5.0 con los sub-changes mergeados; RETO adopta**.

---

## Scope

### In-scope
- Inventario de rama huérfana (este sprint, inmediato).
- Sprint de retrofit (post-v1.5.0).
- Bumpear RETO a v1.5.0+.

### Out-of-scope
- Mergear la rama huérfana `makromania/260624-0511--admin-module` (decidido: NO).
- Reescribir código de RETO en el paquete (R-PKG-007/008/009/010 son los que el paquete absorbe).
- Cambiar reglas del paquete (este sprint es solo consumer-side).

---

## Success criteria

1. Inventario completo de rama huérfana (`inventory.md`) — sprint actual.
2. RETO bumpea a v1.5.0+ sin errores de BC.
3. `mk:lint:boundaries` verde (R-MK-001).
4. Pest tests verdes (~80%+ coverage del módulo Admin).
5. Smoke test end-to-end del flujo Admin funciona con `ci` + RBAC.
6. Rama huérfana cerrada con PR link al retrofit.

---

## Anti-patterns

- ❌ "Mergear la rama huérfana y bumpear después" — diverge del paquete, deuda técnica.
- ❌ "Borrar la rama huérfana sin inventario" — perder learnings valiosos.
- ❌ "Re-implementar features que ya están en v1.5.0" — usar scaffolders.
- ❌ "Skip la releerura de skills" — la base cambió, hay nuevos commands.

---

## Cross-references

- Parent: `openspec/changes/2026-06-24-dogfooding-model/`
- Depends on: R-PKG-007, R-PKG-008, R-PKG-009, R-PKG-010 (todos merged + published).
- Source: rama huérfana `makromania/260624-0511--admin-module` (a cerrar).
- Skills: `~/.makromania/agency/skills/mk-director-laravel/SKILL.md`.
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033.
