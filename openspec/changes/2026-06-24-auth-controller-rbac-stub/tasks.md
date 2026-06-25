# R-PKG-010 — Tasks

**Status**: ABIERTO — design.

---

## Track 0 — Diseño

### T0.1 — Diseñar config `mk_director.auth.*`
**Output**: ADRs en `design.md` con defaults BC + opt-in features.

### T0.2 — Diseñar stub actualizado
- Constructor con `RbacService` opcional.
- Métodos helper: `authorizeAbility()`.
- Middleware `throttle:5,1` en `/login`.

---

## Track 1 — Implementación

### T1.1 — Modificar `MakeAuthUserCommand`
**File**: `src/Console/Commands/MakeAuthUserCommand.php`
- Agregar opción `--with-auth-rbac`.
- Pasar flag a generación de stubs.
**Commit**: `feat(laravel): mk:make:auth-user --with-auth-rbac`

### T1.2 — Modificar stub `auth-user.auth-controller.stub`
- 2 versiones: default (BC) y con RBAC.
- O un stub único con condicionales.
- Incluir: ability checks, rate limit middleware, audit log calls.
**Commit**: `feat(laravel): auth-controller stub with optional RBAC + rate limit + audit`

### T1.3 — RbacService helper (si no existe)
- Crear `src/Auth/Services/RbacService.php`.
- O reusar el de R-PKG-008 (shared).
**Commit**: `feat(laravel): add RbacService for ability checks`

### T1.4 — Audit log integration
- Hook en MkAuditLoggerPlugin para capturar auth events.
- Solo si plugin está activo.
**Commit**: `feat(laravel): audit log auth events (login, logout, reset)`

### T1.5 — Config global
**File**: `config/mk_director.php` (template)
- `auth.login_field` (de R-PKG-009).
- `auth.rbac_enabled`.
- `auth.abilities.{endpoint}`.
- `auth.rate_limits.{endpoint}`.
**Commit**: `chore(laravel): add auth config block`

### T1.6 — Tests Pest
- `tests/Feature/AuthControllerRbacTest.php` — con y sin RBAC.
- `tests/Feature/AuthControllerRateLimitTest.php` — verifica throttle.
- `tests/Feature/AuthControllerAuditLogTest.php` — verifica eventos emitidos.
- `tests/Feature/AuthControllerBcTest.php` — verifica byte-equal con v1.4.0 cuando flag ausente.
**Commit**: `test(laravel): cover --with-auth-rbac and BC`

### T1.7 — R-G-032 sync
- 16 locations según checklist.
- Skill reference `11-auth-controller-rbac.md`.
**Commit**: `docs(laravel): sync R-G-032 for --with-auth-rbac`

---

## Track 2 — Validación RETO

### T2.1 — RETO regenera AuthController
- Rama `makromania/260624-XXXX--reto-bump-150-with-auth-rbac` en RETO.
- `php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac`.
- Verificar ability checks + audit log.
- Eliminar impl custom.

---

## Definition of Done

- [ ] Flag funciona, default BC preservado.
- [ ] Tests Pest verdes (4 archivos de test).
- [ ] R-G-032 sync completo.
- [ ] Tag v1.5.0-rc1 + RETO valida.
