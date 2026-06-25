# R-PKG-008 — mk:module Admin --with-rbac

**Sprint ID**: `2026-06-24-admin-with-rbac`
**Parent**: `2026-06-24-dogfooding-model` (R-G-033)
**Branch**: `makromania/260624-2150--admin-with-rbac` (a crear)
**Base**: `origin/dev` @ v1.4.0
**Status**: design (triage GO, falta diseñar API)

---

## Why

RETO implementó manualmente 3 CRUDs interrelacionados (Admin + Role + Ability) + service con RBAC integration (~1200 LOC en el módulo Admin de su rama huérfana). El scaffolder `mk:module {Name}` actual solo genera UN módulo a la vez. Para RBAC completo, el dev tiene que:

1. `mk:module Admin`
2. `mk:module Role`
3. `mk:module Ability`
4. Crear manualmente pivot tables (`role_user`, `ability_role`).
5. Crear manualmente service con RBAC integration.
6. Wirear abilities discovery (R-PKG-007).

Esto es exactamente el tipo de "boilerplate repetitivo" que el paquete debería absorber (R-G-033).

---

## What changes

### Nuevo flag `--with-rbac`

```bash
php artisan mk:module Admin --with-rbac
```

Genera:
```
app/Modules/Admin/
├── Models/
│   ├── Admin.php              (extends User base, NOT AuthUser — admin es un user del sistema)
│   ├── Role.php
│   └── Ability.php
├── Http/Controllers/
│   ├── AdminController.php    (CRUD + assign roles)
│   ├── RoleController.php     (CRUD + assign abilities)
│   └── AbilityController.php  (CRUD, read-only via discover)
├── Database/Migrations/
│   ├── {ts}_create_admins_table.php
│   ├── {ts}_create_roles_table.php
│   ├── {ts}_create_abilities_table.php
│   ├── {ts}_create_role_user_table.php     (pivot: admin belongs to many roles)
│   └── {ts}_create_ability_role_table.php  (pivot: role belongs to many abilities)
├── Services/
│   └── RbacService.php        (helper: user->hasAbility('admin.users.list'))
└── (resto del módulo estándar)
```

### Service provider + auto-wiring

`AdminServiceProvider` registra:
- Policies de Laravel para cada modelo (auto-generadas vía stub).
- `RbacService` en el container.
- Gate definitions basadas en abilities.

### Combinable con otros flags

```bash
php artisan mk:module Admin --with-rbac --profile-fields=name,phone,avatar
php artisan mk:module Member --with-rbac    # Mismo patrón, diferente scope
```

### Auto-run de discover-abilities

Si R-PKG-007 está mergeado, `mk:module Admin --with-rbac` ofrece correr `mk:discover-abilities --module=Admin --force` al final del scaffolder (con confirmación).

---

## Scope

### In-scope
- Diseño del flag `--with-rbac` y sus stubs.
- Tests Pest (fixtures con Admin + Role + Ability + pivots).
- R-G-032 sync (16 locations).

### Out-of-scope
- Reemplazar `AuthUser` por un sistema de roles genérico (mantener AuthUser como base).
- UI para gestión de roles (es CLI/admin app, no UI).
- Integración con providers externos de identidad (OAuth, SAML).

---

## Success criteria

1. `php artisan mk:module Admin --with-rbac` corre sin errores.
2. Genera 11+ archivos correctamente estructurados.
3. Las policies auto-generadas funcionan (test: Admin con Role `super-admin` puede todo, sin Role no puede nada).
4. Pivots con FK constraints (R-RISK-001 — schema integrity).
5. RETO puede reemplazar su módulo Admin custom por el generado.

---

## Anti-patterns

- ❌ Asumir que `Admin extends AuthUser` — admin NO es un auth user, es un user interno. AuthUser es para login scope. Admin usa base User model (configurable).
- ❌ Hardcodear nombres `admin`, `role`, `ability` en español — internacionalizable.
- ❌ Pivot tables sin FK — integridad de DB es R-RISK-001.
- ❌ Policies auto-generadas demasiado permisivas — leer convención "default deny".

---

## Cross-references

- Parent: `openspec/changes/2026-06-24-dogfooding-model/`
- Source: rama `makromania/260624-0511--admin-module` (huérfana)
- Companion: R-PKG-007 (discover abilities)
- Regla: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033
