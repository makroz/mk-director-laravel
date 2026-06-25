# R-PKG-008 — Tasks (TBD al implementar)

**Status**: ABIERTO — design (triage cerrado GO).

---

## Track 0 — Diseño (sprint próximo)

### T0.1 — Diseñar flag `--with-rbac`
**Output**: ADRs en `design.md` con:
- Lista exacta de archivos a generar.
- Policies auto-generadas (template).
- Pivot table schemas con FK.
- ServiceProvider auto-registrado.

### T0.2 — Stubs
**Files nuevos**:
- `src/Console/Stubs/module-rbac/` (carpeta con todos los stubs).
- Cada stub parametrizable con `{{ModuleName}}`, `{{moduleNameLower}}`, etc.

---

## Track 1 — Implementación

### T1.1 — Modificar `mk:module` command
**File**: `src/Console/Commands/MkModuleCommand.php`
- Agregar opción `--with-rbac`.
- Branch en el handle() que carga stubs del RBAC pack.
**Commit**: `feat(laravel): mk:module --with-rbac generates RBAC triad`

### T1.2 — Stubs
- 11+ stubs nuevos (Models, Controllers, Migrations, Service).
- Policies auto-generadas (template).
- RbacService helper.
**Commit**: `feat(laravel): add rbac module stubs`

### T1.3 — ServiceProvider auto-registro
- Auto-register del `AdminServiceProvider`.
- Policies binding.
- Gate definitions.
**Commit**: `feat(laravel): auto-register admin service provider with policies`

### T1.4 — Tests Pest
- `tests/Feature/MkModuleWithRbacTest.php`
- Coverage: scaffolder genera estructura correcta, policies funcionan, pivots con FK.
**Commit**: `test(laravel): cover mk:module --with-rbac`

### T1.5 — R-G-032 sync
- 16 locations según checklist.
- Skill `mk-director-laravel/SKILL.md` + reference `09-admin-with-rbac.md`.
**Commit**: `docs(laravel): sync R-G-032 for --with-rbac`

---

## Track 2 — Validación RETO

### T2.1 — RETO reemplaza su módulo
- Rama `makromania/260624-0511--admin-module` se cierra (NO mergeada).
- Nueva rama `makromania/260624-XXXX--admin-with-rbac-from-scratch` en RETO.
- `mk:module Admin --with-rbac` reemplaza los 3 controllers + service custom.
- Lógica de negocio RETO-only se agrega sobre los stubs.
**Commit en RETO**: `refactor(reto): use mk-director --with-rbac instead of custom impl`

### T2.2 — RETO bumpea a v1.5.0
- `composer require makroz/director-laravel:^1.5.0`
- Smoke test del módulo Admin.

---

## Definition of Done

- [ ] Flag funciona, genera 11+ archivos.
- [ ] Tests Pest verdes (90%+ coverage).
- [ ] R-G-032 sync completo.
- [ ] Tag v1.5.0-rc1 + validación RETO.
- [ ] RETO cerró rama huérfana + bumpeó paquete.
