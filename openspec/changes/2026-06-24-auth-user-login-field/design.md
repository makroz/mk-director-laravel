# R-PKG-009 — Design: `mk:make:auth-user --login-field=<field>`

**Status**: ✅ DESIGN GO (sign-off Mario pendiente, recomendación en § Q1-Q3)
**Sprint**: `2026-06-24-auth-user-login-field`
**Base**: `origin/dev` @ `8e41cff` (post-R-PKG-007 closure, R-PKG-009 branch clean)
**Target release**: `v1.5.0-rc3`

---

## Why (resumen del proposal.md)

`mk:make:auth-user {Scope}` hardcodea `email` en 4 lugares:
1. `AuthUser` base model — `$fillable` con `email`, docblock `@property string $email`
2. `auth-user.migration.stub` — `$table->string('email')->unique()`
3. `auth-user.auth-controller.stub` — `'email' => 'required|email'` en validation + `where('email', ...)` lookup
4. `auth-user.routes.stub` — sin cambios (no toca email)

RETO Bolivia necesita `ci` (cédula). Otros casos futuros: India `aadhaar`, China `id_card`, USA `ssn`, `phone`, `username`.

**Hipótesis**: el campo de login es un detalle de cada scope, no del framework. El paquete debe ser agnóstico al campo específico.

---

## What changes

### Cambio público

```bash
# Default (backward compatible): email
php artisan mk:make:auth-user Admin

# Campo custom
php artisan mk:make:auth-user Admin --login-field=ci
php artisan mk:make:auth-user Member --login-field=phone
php artisan mk:make:auth-user Customer --login-field=username
```

### Constraints (ADRs)

- **D1**: El campo DEBE ser `string`. NO soporta int, json, ni composite.
- **D2**: Default sigue siendo `email` (BC: consumers que no pasan `--login-field` generan idéntico a v1.4.0).
- **D3**: La columna DB se llama igual al campo (no prefijo `login_`). Esto permite queries directos tipo `where('ci', $value)` en lugar de `where(config('mk_director.auth.login_field'), ...)`.
- **D4**: Validación mínima (`required`, `string`). Formato específico (regex CI, formato email) es responsabilidad del consumer via `LoginRequest` override.
- **D5**: `AuthUser` base mantiene `email` y `MustVerifyEmail` por default (BC). Las subclases generadas con `--login-field != email` NO implementan `MustVerifyEmail` (porque no hay email que verificar) y NO incluyen `email_verified_at` en `$casts`. Esto preserva BC para consumers existentes.
- **D6**: `AuthUser::scopeWhereLoginField($value)` es el API recomendado para queries dinámicas agnósticas al campo. La query usa `$this->loginField` que la subclase override.

### ADRs firmadas

| ID | Decisión | Rationale |
|---|---|---|
| **D1** | Campo string only | Otros tipos (int, json) requieren diferente pipeline de hashing/validation. Out of scope. |
| **D2** | Default `email` (BC) | Consumers sin flag siguen idéntico a v1.4.0. |
| **D3** | Columna DB = nombre del campo | Queries directos sin indirección via config. |
| **D4** | Validación mínima | Agnóstico al dominio. Consumer customiza. |
| **D5** | AuthUser BC, subclase override | Preserva BC. Subclases con `--login-field != email` rompen `MustVerifyEmail` (intended). |
| **D6** | `scopeWhereLoginField()` API | Helper recomendado; subclases pueden usar `where($this->loginField, $value)` directo. |

---

## Decisiones abiertas (Q1-Q3) — recomendación incluida

### Q1 — Campo único vs múltiples

**Pregunta**: ¿`--login-field=ci` (campo único) o `--login-fields=email,ci` (múltiples con fallback)?

**Recomendación**: **A — campo único**.

**Rationale**:
- Cubre el 80% de casos (RETO, India, China, USA, genérico).
- "Login con email O ci" es un patrón SaaS avanzado, no MVP.
- B requiere lógica de fallback + tests más complejos.
- Si RETO lo pide, B se puede agregar en v1.6.0 sin romper A.

### Q2 — ¿Columna DB se llama igual al campo?

**Pregunta**: Si `--login-field=ci`, ¿la columna es `ci` o `login_field`?

**Recomendación**: **SÍ — columna DB = nombre del campo (`ci`)**.

**Rationale**:
- Queries directos sin indirección.
- Migración generada queda legible.
- El nombre del campo es ya único (por constraint D1).

### Q3 — ¿LoginRequest valida formato del campo?

**Pregunta**: ¿Validar `email:rfc` o regex para CI, etc.?

**Recomendación**: **Validación mínima — `required`, `string`**.

**Rationale**:
- Agnóstico al dominio (RETO, India, China, USA todos distintos).
- Consumer override el LoginRequest si necesita validación específica.
- BC para consumers existentes que asumen `email:rfc`.

---

## Files a modificar

| Archivo | Cambio |
|---|---|
| `src/Console/Commands/MakeAuthUserCommand.php` | Agregar option `--login-field=<field>` (default `email`). Pasar `{{loginField}}` a stubs via `str_replace`. |
| `src/Auth/Models/AuthUser.php` | Agregar property `$loginField` (default `email`). Agregar `scopeWhereLoginField($value)` + `getLoginField()`. Mantener `$fillable` con `email` por BC. Docblock `@property` generaliza. |
| `src/Stubs/auth-user.model.stub` | Generar `$fillable` específico con `{{loginField}}`. Generar `$loginField` property. Generar `$casts` con `email_verified_at` solo si `{{loginField}}` es email. Override `MustVerifyEmail` interface solo si login field es email. |
| `src/Stubs/auth-user.migration.stub` | Columna `{{loginField}}` en lugar de `email`. `email_verified_at` condicional. Tabla `password_reset_tokens` también usa `{{loginField}}` como primary key. |
| `src/Stubs/auth-user.auth-controller.stub` | Validation rule `'{{loginField}}' => ['required', 'string']`. Lookup `scopeWhereLoginField($credentials[{{loginField}}])`. Response `$user->only([..., '{{loginField}}'])`. |
| `src/Stubs/auth-user.routes.stub` | Sin cambios (no toca email). |
| `src/Stubs/auth-user.service-provider.stub` | Sin cambios. |
| `config/mk_director.php` | Agregar key `auth.login_field` con `env('MK_LOGIN_FIELD', 'email')`. |

---

## Implementation plan (6 commits atómicos)

| Commit | T | Tipo | Mensaje |
|---|---|---|---|
| 1 | T0 | chore | `chore(laravel): R-PKG-009 state.yaml v2 + design.md` |
| 2 | T1.1 | feat | `feat(laravel): mk:make:auth-user --login-field=<field>` |
| 3 | T1.2 | feat | `feat(laravel): auth-user stubs login-field aware` |
| 4 | T1.3 | feat | `feat(laravel): AuthUser login-field agnostic` |
| 5 | T1.4 | chore | `chore(laravel): add login_field to mk_director config` |
| 6 | T1.5 | test | `test(laravel): cover --login-field in mk:make:auth-user` |
| 7 | T1.6 | docs | `docs(laravel): sync R-G-032 for login-field` |

(Total 7 commits si separamos T0 del primer commit funcional. Patrón heredado de R-PKG-007.)

---

## Tests plan (T1.5)

### Tests nuevos (`tests/Feature/MakeAuthUserLoginFieldTest.php`)

1. **Default sin flag genera `email`** — BC con v1.4.0
2. **`--login-field=ci` genera columna `ci` en migration**
3. **`--login-field=phone` genera columna `phone` en migration**
4. **`--login-field=username` genera columna `username` en migration**
5. **Stub de model tiene `$loginField = 'ci'` cuando flag es `ci`**
6. **Stub de model tiene `$loginField = 'email'` cuando flag es default**
7. **Stub de auth-controller usa `where('ci', ...)` cuando flag es `ci`**
8. **Stub de auth-controller usa `required|string` (no `email:rfc`) cuando flag es `ci`**

### Tests nuevos (`tests/Feature/AuthUserLoginFieldTest.php`)

9. **`AuthUser::scopeWhereLoginField()` query usa default `email`**
10. **Subclase con `$loginField = 'ci'` + scope usa columna `ci`**
11. **`AuthUser::getLoginField()` retorna el campo configurado**

### Tests modificados

- `tests/Unit/Console/MakeAuthUserCommandTest.php` — actualizar signature expectations
- `tests/Unit/MakeAuthUserCommandRegisteredTest.php` — sin cambios (signature change no afecta el test)

### BC verification

- `./vendor/bin/pest tests/Unit/Console/MakeAuthUserCommandTest.php` — debe pasar idéntico a v1.4.0 cuando no se pasa flag.

---

## Risk register

| ID | Riesgo | Mitigación |
|---|---|---|
| R-RISK-PKG-009-001 | BC para consumers existentes | Default `email` idéntico a v1.4.0. Smoke test con `./vendor/bin/pest` debe pasar sin cambios. |
| R-RISK-PKG-009-002 | Subclase con `--login-field=ci` no implementa `MustVerifyEmail` | Documentado en D5. Consumer debe implementar `MustVerifyPhone` u override si lo necesita. |
| R-RISK-PKG-009-003 | Conflict con R-PKG-007 en `MkServiceProvider.php` y `config/mk_director.php` | Branch creada desde `origin/dev` post-R-PKG-007. Sin rebase. Ambos archivos son modificables independentemente (R-PKG-007 toca `features.auto_discover_abilities`, R-PKG-009 toca `auth.login_field`). |

---

## Cross-references

- **Parent**: `openspec/changes/2026-06-24-dogfooding-model/`
- **Predecessor cerrado**: `openspec/changes/2026-06-24-discover-abilities-to-core/` (R-PKG-007)
- **Source**: rama RETO huérfana `makromania/260624-0511--admin-module` + observación directa del paquete actual
- **Regla**: `~/.makromania/agency/global/rules_orchestration.md` § R-G-033 + R-G-032