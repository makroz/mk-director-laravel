# R-PKG-009 — Tasks

**Status**: ABIERTO — design (triage cerrado GO).

---

## Track 0 — Diseño

### T0.1 — Diseñar flag --login-field
**Output**: ADRs en `design.md`.

### T0.2 — Diseñar AuthUser base agnóstico
- `protected string $loginField = 'email';`
- Scope `whereLoginField()`.
- Método `getLoginField()`.

---

## Track 1 — Implementación

### T1.1 — Modificar `MakeAuthUserCommand`
**File**: `src/Console/Commands/MakeAuthUserCommand.php`
- Agregar opción `--login-field=<field>` (default `email`).
- Pasar el campo a la generación de stubs.
**Commit**: `feat(laravel): mk:make:auth-user --login-field=<field>`

### T1.2 — Modificar stubs
- `auth-user.model.stub` — usa `{{loginField}}` en fillable + propiedad.
- `auth-user.migration.stub` — columna con nombre `{{loginField}}`.
- `auth-user.auth-controller.stub` — usa `config('mk_director.auth.login_field')`.
- `auth-user.routes.stub` — sin cambios.
**Commit**: `feat(laravel): make stubs login-field aware`

### T1.3 — Modificar AuthUser base
**File**: `src/Auth/Models/AuthUser.php`
- Agregar `$loginField` property.
- Agregar `scopeWhereLoginField()`.
- Agregar `getLoginField()`.
**Commit**: `feat(laravel): AuthUser login-field agnostic`

### T1.4 — Config global
**File**: `config/mk_director.php` (publish template)
- `'auth.login_field' => env('MK_LOGIN_FIELD', 'email')`.
**Commit**: `chore(laravel): add login_field to mk_director config`

### T1.5 — Tests Pest
- `tests/Feature/MakeAuthUserLoginFieldTest.php` con 4 escenarios (email, ci, phone, username).
- `tests/Feature/AuthUserLoginFieldTest.php` con scope y método.
**Commit**: `test(laravel): cover --login-field in mk:make:auth-user`

### T1.6 — R-G-032 sync
- 16 locations según checklist.
- Skill reference `10-login-field.md`.
**Commit**: `docs(laravel): sync R-G-032 for login-field`

---

## Track 2 — Validación RETO

### T2.1 — RETO regenera con --login-field=ci
- Rama `makromania/260624-XXXX--reto-bump-150-with-login-field` en RETO.
- `php artisan mk:make:auth-user Admin --login-field=ci`.
- Smoke test del login con CI.

---

## Definition of Done

- [ ] Flag funciona con 4+ campos.
- [ ] BC preservada (default email idéntico a v1.4.0).
- [ ] Tests Pest verdes.
- [ ] R-G-032 sync completo.
- [ ] Tag v1.5.0-rc1 + RETO valida con `ci`.
