# R-PKG-011: Profile fields per-scope + optional email verification

> **Sprint**: `2026-06-25-profile-fields-per-scope`
> **Parent**: `2026-06-24-dogfooding-model`
> **Rule**: R-G-033-A (genérico)
> **Target release**: v1.5.0-rc5

---

## Contexto

`mk:make:auth-user` (R-PKG-009 / v1.5.0-rc3 + R-PKG-010 / v1.5.0-rc4) genera un scope autenticable con campo de login configurable, RBAC opt-in, rate limiting y audit log. **Pero no hay mecanismo para declarar campos de perfil propios del scope** (nombre, dni, phone, birthdate, etc. según el dominio).

Cada consumer termina escribiendo esos campos a mano en migración + modelo, lo cual:

- Genera boilerplate repetido
- Rompe MME (R-MK-001): un Admin tiene `dni`, un Member tiene `phone`, y no hay razón para compartir tablas ni modelos
- Hace que cambiar el shape del profile (agregar `birthdate`) requiera editar N migraciones + N modelos a mano
- Confunde la separación "auth field" (loginField) con "profile fields" (atributos del user que no son auth)

Adicionalmente, **email verification** no existe en el scaffolder. La interfaz `MustVerifyEmail` está en el modelo base (AuthUser) pero ningún endpoint verifica emails, y la columna `email_verified_at` solo se incluye cuando `--login-field=email` (R-PKG-009).

## Goal

Agregar al scaffolder `mk:make:auth-user` dos flags **opt-in** (default = BC preservada con v1.5.0-rc4):

1. **`--profile-fields=field1,field2,field3`** — columnas adicionales para la tabla del scope, en `$fillable` y `$casts` del modelo del scope. Cada scope encapsula sus propios fields (MME/R-MK-001 estricto).
2. **`--verify-email`** — habilita flujo completo de verificación de email: columna `email_verified_at`, cast, endpoint `/email/verify/{id}/{hash}`, endpoint `/email/resend`, middleware `verified` opcional en rutas protegidas.

## Scope

### In scope (v1.5.0-rc5)

- Flag `--profile-fields=<csv>` en `mk:make:auth-user {Scope}` (comma-separated, optional)
- Validación de cada field: PHP identifier válido, no duplicados
- Generación de columnas `string` en migration del scope (todas las profile fields en v1.5.0-rc5)
- Inclusión en `$fillable` del modelo (después de `name` + `loginField`, antes de `password`)
- Inclusión en `$casts` del modelo (todas como `string` por default)
- Exposición vía `GET /me` (read automático via fillable), `PATCH /me` (update con validación), `POST /register` (write al crear)
- Flag `--verify-email` (boolean, default false)
- Columna `email_verified_at` en migration (solo si flag activo)
- Cast `email_verified_at => datetime` en modelo (solo si flag activo)
- Endpoint `GET /email/verify/{id}/{hash}` en routes (solo si flag activo)
- Endpoint `POST /email/resend` en routes (solo si flag activo)
- Notification `Illuminate\Auth\Notifications\VerifyEmail` queueable
- Compatibilidad con `--with-auth-rbac` (R-PKG-010) y `--login-field` (R-PKG-009) — los flags son ortogonales

### Out of scope (futuros sprints)

- Tipos no-string para profile fields (int, date, json, file, etc.) → v1.6.0 con `--profile-fields-types=name:string,dni:string,birthdate:date`
- Avatar / file uploads → R-PKG-013+
- 2FA / TOTP → R-PKG-012+
- Multi-tenant en profile fields → R-PKG-014+
- Profile fields EAV (custom fields por tenant en runtime) → fuera, demasiada complejidad
- SMS verification → futuro, solo email en v1.5.0-rc5

## Dogfooding source

RETO Bolivia necesita:
- **Admin scope**: `name`, `dni`, `phone` (current impl custom en rama huérfana `makromania/260624-0511--admin-module`, ~150 LOC)
- **Member scope**: `name`, `phone`, `birthdate` (futuro)

RETO también quiere verificación por email (opcional — por ahora solo para Admin, no para Member).

## R-G-033-A compliance

- **≥ 2 dominios bounded**: cualquier app multi-vertical necesita profile fields (e-commerce = customer profile, fintech = KYC profile, social = bio+avatar, healthcare = medical history, etc.) ✓
- **Testeable sin RETO**: source-parsing tests (mismo patrón que R-PKG-009 + R-PKG-010) + unit tests de stub generation ✓
- **Doc sin RETO**: cómo "Profile fields per scope" genérico, sin mencionar Bolivia/CI/RETO en docs public-facing ✓

## Decisiones de diseño (preguntas a resolver en design.md)

| ID | Pregunta | Recomendación |
|----|----------|---------------|
| **D1** | ¿Profile fields por scope o compartidos (User global)? | Por scope. Cada scope tiene su propia tabla + modelo. MME/R-MK-001 estricto. |
| **D2** | Default sin profile fields (BC)? | Sí. Sin flag, modelo tiene solo `name`, `loginField`, `password`, `auth_scope`, `client_id`. Idéntico a v1.5.0-rc4. |
| **D3** | Tipos de profile fields en v1.5.0-rc5? | Todos `string`. Custom types en v1.6.0. |
| **D4** | ¿Qué endpoints exponen profile fields? | `GET /me` (read via fillable), `PATCH /me` (update), `POST /register` (write al crear). |
| **D5** | `--verify-email` default? | Default `false` (sin verificación, BC con v1.5.0-rc4). |
| **D6** | Notificación de verificación? | Laravel default `Illuminate\Auth\Notifications\VerifyEmail`. Customización de template / queue del consumer. |
| **D7** | Validación de field names? | PHP identifier válido (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`). |
| **D8** | Duplicados en CSV? | Reject fail-fast. `--profile-fields=name,dni,name` → error. |
| **D9** | Ortogonalidad con `--with-auth-rbac` y `--login-field`? | Total. Los 4 flags son independientes y combinables. Default mode (ninguno) = BC. |
| **D10** | ¿`/me` PATCH rate-limited? | No por default. Consumer puede agregar `throttle:30,1` si quiere. |

## Plan

1. ✅ SDD completo (proposal + state.yaml v1 + tasks.md + design.md + specs/)
2. ⏳ Branch `makromania/260625-1822--r-pkg-011-profile-fields` desde `origin/dev` (en sub-repo `makroz/mk-director-laravel`)
3. ⏳ T1.1: Signature con `--profile-fields` y `--verify-email`
4. ⏳ T1.2: Métodos `resolveProfileFields()` y `resolveVerifyEmail()` con validación
5. ⏳ T1.3: Construcción de replacements en `handle()`
6. ⏳ T1.4: Actualizar `auth-user.model.stub` con placeholders condicionales
7. ⏳ T1.5: Actualizar `auth-user.migration.stub` con columnas condicionales
8. ⏳ T1.6: Actualizar `auth-user.auth-controller.stub` con endpoints /me PATCH + /register + verifyEmail/resend
9. ⏳ T1.7: Actualizar `auth-user.routes.stub` con PATCH /me + conditional email verification routes
10. ⏳ T1.8: Tests Pest (MakeAuthUserProfileFieldsTest + AuthUserProfileFieldsTest)
11. ⏳ T1.9: R-G-032 sync (CHANGELOG, DEVELOPER_GUIDE, README + skill reference 13 + monorepo docs)
12. ⏳ PR + cron watch r-pkg-011-pr-watch + memory entry + Telegram

## Cross-repo coordination (3 PRs)

- **makroz/mk-director-laravel#XX** — feat + tests + R-G-032 locations 1-3 + 4 + 4b
- **makroz/MK-Director#XX** — monorepo docs sync (locations 6-8)
- **mariogfos/humandirector#XX** — skill reference 13 (`references/13-profile-fields-per-scope.md`) + SKILL.md index update (locations 4 + 4b)

## Riesgo y mitigación

| ID | Risk | Mitigation |
|----|------|-----------|
| R-RISK-PKG-011-001 | Columnas duplicadas con `name` o `loginField` | Validación fail-fast en `resolveProfileFields()`. |
| R-RISK-PKG-011-002 | BC break: app existente que asuma columnas mínimas | Default mode (sin flag) = columnas idénticas a v1.5.0-rc4. |
| R-RISK-PKG-011-003 | Profile fields leak entre scopes (Admin ve fields de Member) | Cada scope genera su propia tabla + modelo. Tests de encapsulación pineados. |
| R-RISK-PKG-011-004 | Ortogonalidad rota con `--with-auth-rbac` | Source-parsing test que verifica que los 4 flags se pueden combinar en cualquier subconjunto. |
| R-RISK-PKG-011-005 | Email verification expone endpoint sin rate limit | Sin rate limit por default, pero consumer puede agregar via routes customization. Documentado. |