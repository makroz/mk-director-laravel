# Changelog

All notable changes to `makroz/director-laravel` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0-rc3] - 2026-06-26

### Changed

- **`php artisan mk:update` ahora lista TODAS las versiones superiores a la instalada, incluyendo pre-releases** (R-PKG-013). Bug fix: el filtro previo `/^v?\d+\.\d+\.\d+$/` ocultaba cualquier versión con sufijo (`-rc1`, `-rc2`, `-beta`, `-alpha`). Si estabas en `v1.3.1`, el command decía "última disponible: v1.4.0" cuando `v1.6.0-rc2` ya estaba en Packagist. Ahora:
  - Lista todas las versiones publicadas en Packagist que son mayores a la instalada.
  - Las presenta en un menú navegable con flechas del teclado (Symfony `choice()`), sin necesidad de flags (`--include-rc`, `--channel=`, etc.).
  - El usuario elige con ↑↓ + Enter. Por default, la primera opción es la versión más alta disponible (sea RC o estable).
  - Markers visuales: `⭐ (última estable)` y `🧪 (pre-release)` para distinguir.
  - Una vez elegida, ejecuta `composer require makroz/director-laravel:vX.Y.Z` (en lugar del `composer update` genérico de antes) para garantizar la versión exacta.

### Migration desde v1.6.0-rc1 / rc2

- **Sin acción requerida.** El comportamiento cambia solo para devs que corren `php artisan mk:update`. Los que ya están en la última versión verán el nuevo output ("X versiones disponibles para actualizar") pero ningún cambio real.
- Si tenés scripts CI que pinean `composer update makroz/director-laravel`, ahora deberías pinear `composer require makroz/director-laravel:vX.Y.Z` para reproducibilidad. (Esto era implícitamente cierto antes también — `composer update` sin constraint puede moverse a versiones inesperadas.)

### Spec

- Sprint: `openspec/changes/2026-06-26-mk-update-interactive/`
- Bug original detectado por Mario en RETO (corriendo `mk:update` desde `v1.3.1`, vio "última v1.4.0" ignorando `v1.6.0-rc2`).

---

## [1.6.0-rc1] - 2026-06-25

Release candidate. **Extensión backward-compatible** de `--profile-fields` en
`php artisan mk:make:auth-user {Scope}`: sintaxis `key[:type]` para declarar
tipos custom (más allá de `string` que era el único tipo en v1.5.0-rc5).

### Added

- **Sintaxis `key:type` en `--profile-fields=<csv>`** (R-PKG-012): cada item del
  CSV puede ser `key` (sin tipo, default `string`) o `key:type` (tipo explícito).
  Sin `:` = `string` (BC con R-PKG-011). Tipos case-sensitive (lowercase only).
- **8 tipos soportados** en v1.6.0-rc1 (lista cerrada):

  | Tipo | Migration column | Model cast | Validation rule |
  |---|---|---|---|
  | `string` (default, BC) | `string` | (sin cast) | `['required', 'string', 'max:255']` |
  | `text` | `text` | (sin cast) | `['required', 'string']` |
  | `int` | `integer` | `'integer'` | `['required', 'integer']` |
  | `decimal` | `decimal(8,2)` | `'decimal:2'` | `['required', 'numeric']` |
  | `bool` | `boolean` | `'boolean'` | `['required', 'boolean']` |
  | `date` | `date` | `'date'` | `['required', 'date']` |
  | `datetime` | `dateTime` | `'datetime'` | `['required', 'date']` |
  | `json` | `json` | `'array'` | `['required', 'array']` |

- **Constante `PROFILE_FIELD_TYPES`** en `MakeAuthUserCommand`: tabla cerrada
  con 8 tipos y sus configs (column_method, column_args, cast, validation).
  Reutilizable desde otros commands si en el futuro se necesita.

### Ortogonalidad

R-PKG-012 es extensión de R-PKG-011. Combinable con todos los flags existentes:

```bash
# v1.5.0-rc5 (BC): todos string
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# v1.6.0-rc1 (NEW): tipos custom
php artisan mk:make:auth-user Admin \
  --profile-fields=name:string,birthdate:date,age:int,biography:text,active:bool

# Mixed (con y sin tipo): default string cuando no se especifica tipo
php artisan mk:make:auth-user Admin --profile-fields=name,age:int,active:bool

# RETO Bolivia Member scope (futuro, post-GA)
php artisan mk:make:auth-user Member \
  --login-field=email \
  --with-auth-rbac \
  --profile-fields=name:string,phone:string,birthdate:date,active:bool,registered_at:datetime,metadata:json
```

Los 5 flags del command siguen siendo combinables:
`--login-field`, `--with-auth-rbac`, `--profile-fields` (con o sin tipos),
`--verify-email`. Sin interacción inesperada entre ellos.

### Compatibility

- **BC con R-PKG-011 preservada**: `--profile-fields=name,dni,phone` (sin
  tipos) se interpreta como `name:string,dni:string,phone:string`. Output
  **idéntico** a v1.5.0-rc5. 0 regresiones verificadas con 411 tests Pest
  verdes (372 baseline + 39 nuevos).
- **Default mode preservado**: sin `--profile-fields`, comportamiento idéntico
  a v1.5.0-rc5 (sin profile fields, sin register method).
- **Tipos desconocidos → fail-fast**: `--profile-fields=name:foo` se rechaza
  con error explícito listando los 8 tipos válidos. No fail-silent.
- **Tipos case-sensitive**: `String`, `STRING`, `sTrInG` se rechazan (solo
  lowercase). No normalización implícita.
- **Out of scope v1.6.0-rc1**: `file`/`avatar` (storage uploads, R-PKG-013+),
  `enum` (Laravel 11+ `Rule::enum()`, R-PKG-014+), `decimal` con precisión
  custom (`decimal:10,4`, post-RC si RETO necesita), `uuid`/`ulid`,
  `int[]`/`string[]`.

### Anti-patterns

- ❌ **No usar `--profile-fields=name:foo`**: tipo no en lista cerrada se
  rechaza con error. Tipos válidos: `string, text, int, decimal, bool, date,
  datetime, json`.
- ❌ **No usar `--profile-fields=name:String`**: case incorrecto se rechaza.
  Solo lowercase.
- ❌ **No usar `--profile-fields=email:date --login-field=email`**: colisión
  con login field sigue siendo rechazada por R-PKG-011 (regla preservada).
- ❌ **No usar tipos para compartir datos entre scopes**: cada scope tiene su
  propia tabla y modelo. Para datos compartidos, exponer `Api\*` interface
  del scope que los posee (MME/R-MK-001).
- ❌ **No esperar validación strict format para `date`/`datetime`**: el rule
  es `['required', 'date']` loose (acepta múltiples formatos). Para strict
  format (`Y-m-d` o ISO 8601), override `register()`/`updateProfile()` en el
  AuthController generado.

### Spec

- Proposal: `openspec/changes/2026-06-25-profile-fields-types/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-types/specs/profile-fields-types.md`
- Design: `openspec/changes/2026-06-25-profile-fields-types/design.md`
- Tasks: `openspec/changes/2026-06-25-profile-fields-types/tasks.md`

## [1.5.0-rc1] - 2026-06-24

Release candidate. **New feature** in the `mk:module` scaffolder: the
`--with-rbac` flag generates a complete bounded-context RBAC triad
(User + Role + Ability + 2 pivots + 3 Policies + RbacService +
ServiceProvider with Gate bindings) in a single command. The triad
is fully isolated from the package's central `Auth/Models/{Role,Ability}`
and uses its own migration namespace prefixed by `{moduleNameLower}_`.

### Added

- **`php artisan mk:module {Name} --with-rbac`**: scaffolds a complete
  RBAC triad per module (D1: per-module isolation, NOT reuse of
  central `Auth\Models\Role`/`Ability`). Generates 20 files:
  - 3 Models: `{Name}` (extends `Illuminate\Foundation\Auth\User`, NOT `AuthUser` — D2), `Role`, `Ability`.
  - 3 Controllers: `{Name}Controller` (CRUD + `assignRole`/`revokeRole`),
    `RoleController` (CRUD + `syncAbilities`), `AbilityController` (read-only).
  - 3 Policies: `{Name}Policy`, `RolePolicy`, `AbilityPolicy` — `before()` super-admin
    bypass + per-method `hasAbility()` checks (default-deny, RBAC-004).
  - 1 Service: `RbacService` (singleton, bound in ServiceProvider).
  - 5 Migrations: 3 entity tables (`{scope}_users`, `{scope}_roles`,
    `{scope}_abilities`) + 2 pivots (`{scope}_role_user`,
    `{scope}_ability_role`) with **FK constraints + `cascadeOnDelete()`
    on both columns** (R-RISK-001, hardening R3-014).
  - 1 ServiceProvider: auto-`Gate::policy()` for 3 models +
    auto-`Gate::define()` for 15 abilities (D5: explicit abilities
    make `mk:discover-abilities` — R-PKG-007 — trivial).
  - 1 Routes file (`Routes/api.php`): CRUD for the 3 controllers +
    `assignRole` / `revokeRole` / `syncAbilities` actions.
  - 3 standard reusados: DTO, Repository, RepositoryInterface.
  - Timestamps secuenciales (`addSeconds($i)`) para evitar colisión
    de filenames en migrations generadas en el mismo run.

- **Per-module RBAC isolation (D1)**: each `--with-rbac` module gets its
  own tables. Avoids cross-module state contamination. Aligns with
  R-MK-001 (MME: bounded contexts must not share DB state).

- **Auto-discover abilities source-of-truth (D5)**: the ServiceProvider
  exposes `discoverAbilities()` returning 15 explicit ability strings
  (`{scope}.{resource}.{action}`). `mk:discover-abilities` (R-PKG-007)
  can use this as the canonical list when seeding the `{scope}_abilities` table.

### Tests (15 new)

- `tests/Feature/MkModuleWithRbacTest.php` — 15 Pest tests, 157 assertions.
  Coverage: scaffolding generates 20 files, FK constraints in pivots,
  `Gate::policy` auto-bind for 3 models, 15 abilities via `discoverAbilities()`,
  default-deny via `hasAbility()` in all 7 CRUD methods, `before()` super-admin
  bypass, User extends `Authenticatable` NOT `AuthUser`, end-to-end tempdir
  test that runs the actual command.

### Breaking change

**None for existing consumers.** This release is purely additive: existing
modules generated without `--with-rbac` see no change. Only RETO's orphan
branch (`makromania/260624-0511--admin-module`, pre-1.4.0) has a custom RBAC
module that will be replaced when it bumps to v1.5.0 (planned sprint
R-RET-001, separate).

### Related sprints

- **R-PKG-007** (`mk:discover-abilities`): consumes the explicit abilities
  list from `--with-rbac` provider.
- **R-PKG-009** (`mk:make:auth-user --login-field`): orthogonal flag.
  Run separately to add login flow to a module that already has RBAC.
- **R-PKG-010** (`AuthController` RBAC stub): depends on `--with-rbac`
  for the `Role`/`Ability` models.

### Spec

- Spec: `openspec/changes/2026-06-24-admin-with-rbac/specs/admin-with-rbac.md`
- Design: `openspec/changes/2026-06-24-admin-with-rbac/design.md`
- Tasks: `openspec/changes/2026-06-24-admin-with-rbac/tasks.md`

## [1.5.0-rc2] - 2026-06-25

Release candidate. **New Artisan command**: `php artisan mk:discover-abilities`
auto-publishes abilities into `{scope}_abilities` by reading them from the
module's `ServiceProvider::discoverAbilities()` (consumed when present),
falling back to PHP 8.4 attributes `#[\Mk\Director\Auth\Attributes\Ability]`
and docblock `@mk-ability` annotations when the provider doesn't expose the
method.

### Added

- **`php artisan mk:discover-abilities {--module=*} {--dry-run} {--force} {--json}`**:
  - **Source-of-truth (D1 hybrid)**: provider primary; attribute + docblock
    as fallback ONLY when provider absent. Never mix sources within a module.
  - **PHP 8.4 attribute `#[\Mk\Director\Auth\Attributes\Ability(name, description)`**:
    repeatable, target METHOD, ideal for typed declaration on controllers.
  - **Docblock fallback `@mk-ability name|description`**: regex-escape-free
    (PHP 8.5 PCRE2), supports pre-8.4 apps.
  - **Write intent (D3)**: interactive `$this->confirm(..., false)` with
    `--force` skip + `--dry-run` skip + Laravel `--no-interaction` safe
    no-op (Q3 sign-off).
  - **Idempotent UPSERT** into `{scope}_abilities` (`{scope}` = snake_case
    plural of module name, e.g. `admin_abilities`).
  - **Opt-in auto-register**: `mk_director.features.auto_discover_abilities = true`
    runs on every boot (sandbox/dev only — off by default).
  - **Configurable module path**: `mk_director.paths.modules` (default
    `app_path('Modules')`, env override `MK_MODULES_PATH`).
- **`src/Auth/Attributes/Ability.php`**: PHP 8.4 attribute,
  `#[\Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]`.
- **Config additions**: `paths.modules`, `features.auto_discover_abilities`.

### Compatibility

- No BC break for existing consumers (command is opt-in).
- The `--with-rbac` provider from 1.5.0-rc1 is consumed as-is: `mk:module Admin --with-rbac`
  followed by `mk:discover-abilities --module=admin --force` is the canonical
  workflow for scaffolding + ability sync.

### Spec

- Spec: `openspec/changes/2026-06-24-discover-abilities-to-core/proposal.md`
- Design: `openspec/changes/2026-06-24-discover-abilities-to-core/design.md`
- Tasks: `openspec/changes/2026-06-24-discover-abilities-to-core/tasks.md`

## [1.5.0-rc3] - 2026-06-25

Release candidate. **Configurable login field** for `mk:make:auth-user`:
the new `--login-field=<campo>` flag lets consumers authenticate with
non-email identifiers (CI for Bolivia, phone, username, documento, etc.)
without hardcoding the column.

### Added

- **`php artisan mk:make:auth-user {Scope} --login-field=<campo>`**:
  - **Default `email`** (BC con v1.4.0 — comportamiento idéntico sin flag).
  - **String-only fields** (D1): valida `[a-zA-Z_][a-zA-Z0-9_]*`. Empty
    o ausente → fallback a `email`.
  - **Columna DB = nombre del campo** (D3): `--login-field=ci` genera
    `$table->string('ci')->unique()`, NO `login_field` o `email`.
  - **Validación mínima** (D4): `required|string` cuando loginField != email
    (consumer customiza vía LoginRequest override).
  - **`MustVerifyEmail` interface** (D5): solo se implementa cuando loginField
    es `email` (subclases con `ci`/`phone` lo heredan del AuthUser base pero
    el cast de `email_verified_at` se omite).
- **`AuthUser` base agnóstico**:
  - Nueva property `$loginField` (default `email`).
  - Nuevo método `getLoginField(): string`.
  - Nuevo local scope `scopeWhereLoginField(Builder $query, string $value): Builder`
    (D6 — permite queries dinámicas agnósticas al campo).
  - Docblock ya NO hardcodea `@property string $email` (regresión cubierta
    por `AuthUserDocblockTest`).
- **Config**: `mk_director.auth.login_field` (env `MK_LOGIN_FIELD`, default `email`).
- **Stubs actualizados**: `auth-user.model.stub`, `auth-user.migration.stub`,
  `auth-user.auth-controller.stub` ahora son parametrizados con `{{loginField}}`
  + placeholders condicionales para BC con v1.4.0.

### Compatibility

- **BC preservada**: sin flag, `mk:make:auth-user Admin` genera idéntico a v1.4.0.
- 316/316 tests verde, 0 regresiones.

### Examples

```bash
# Default (BC): email
php artisan mk:make:auth-user Admin

# Bolivia: cédula de identidad
php artisan mk:make:auth-user Admin --login-field=ci

# Genérico: phone
php artisan mk:make:auth-user Member --login-field=phone

# Genérico: username
php artisan mk:make:auth-user Customer --login-field=username
```

### Spec

- Spec: `openspec/changes/2026-06-24-auth-user-login-field/proposal.md`
- Design: `openspec/changes/2026-06-24-auth-user-login-field/design.md`
- Tasks: `openspec/changes/2026-06-24-auth-user-login-field/tasks.md`

---

## [1.5.0-rc4] - 2026-06-25

Release candidate. **RBAC integration** for the `AuthController` generated
by `mk:make:auth-user`: the new `--with-auth-rbac` flag adds ability checks
on `/me` and `/logout`, rate-limit middleware on `/login` (and `/forgot`,
`/reset`), and audit-log events for the auth flow. Default (sin flag)
preserva comportamiento idéntico a v1.5.0-rc3.

### Added

- **`php artisan mk:make:auth-user {Scope} --with-auth-rbac`**:
  - **Default `false`** (BC con v1.5.0-rc3 — comportamiento idéntico sin flag).
  - **Ability checks** en `/me` y `/logout` vía `authorizeAbility()` helper.
    Configurables via `mk_director.auth.abilities.{me,logout}` (default `null`
    → no check, retrocompatible).
  - **Rate limit middleware** en endpoints públicos:
    - `/login`: `throttle:5,1` (configurable via `mk_director.auth.rate_limits.login`).
    - `/forgot`: `throttle:3,1`.
    - `/reset`: `throttle:3,1`.
  - **Audit log events** vía `Mk\Director\Auth\Events\AuthEvent`:
    - `auth.login.success` (user_id, ip, user_agent, scope).
    - `auth.login.failed` (login_field_value, ip, user_agent — **sin password**).
    - `auth.logout` (user_id, token_id).
    - `auth.password_reset.requested` (login_field_value, ip).
    - `auth.refresh.success` y `auth.password_reset.success` marcados con TODO
      (esperan implementación del consumer).
    Consumido por `MkAuditLoggerPlugin` si está activo.
- **Constructor injection** opcional: `AbilityResolver` se inyecta via
  container o se resuelve vía `app()` en boot. Fallback a `canMk()` del
  trait `HasAbilities` para tests que no bootean Laravel completo.
- **Configuración**:
  - `mk_director.auth.abilities.{me,logout}` (env `MK_AUTH_ABILITY_ME` /
    `MK_AUTH_ABILITY_LOGOUT`, default `null`).
  - `mk_director.auth.rate_limits.{login,forgot,reset}` (env
    `MK_AUTH_RATE_LIMIT_*`, defaults `5,1` / `3,1` / `3,1`).
- **Nueva clase**: `Mk\Director\Auth\Events\AuthEvent` — readonly props
  (`type`, `payload`), `Dispatchable` trait para emitir via
  `AuthEvent::dispatch(...)`.
- **Stub updates**:
  - `auth-user.auth-controller.stub`: 11 placeholders RBAC condicionales
    (`{{rbacImports}}`, `{{rbacConstructor}}`, `{{rbacAbilityCheckMe}}`,
    `{{rbacAbilityCheckLogout}}`, `{{rbacAudit*}}`, `{{rbacAuthorizeAbilityMethod}}`).
  - `auth-user.routes.stub`: 3 placeholders inline `{{rbac{Login,Forgot,Reset}Throttle}}`
    (preservan línea original del stub cuando están vacíos → BC estricta).

### Anti-patterns (rejected)

- **Habilitar RBAC por default**: rompe BC con v1.5.0-rc3 (consumers que no
  esperaban ability checks). RBAC es **opt-in** vía flag.
- **Loggear passwords** (ni hasheados) en audit events. El payload se
  sanitiza antes de dispatch.
- **Rate limit agresivo global**: configurable por endpoint. Default seguro
  pero tunable.

### Compatibility

- **BC preservada**: sin flag, `mk:make:auth-user Admin` genera AuthController
  + routes **idénticos** a v1.5.0-rc3 (modulo la parametrización de
  `--login-field`). 0 regresiones verificadas con 332 tests Pest verdes.
- **RETO migration path**: cuando bumpeen a v1.5.0 (R-RET-001 phase 2+3),
  regenerar `AuthController` con `--with-auth-rbac` y eliminar los
  ~322 LOC de implementación manual que viven en su rama huérfana.

### Spec

- Spec: `openspec/changes/2026-06-24-auth-controller-rbac-stub/proposal.md`
- Spec formal: `openspec/changes/2026-06-24-auth-controller-rbac-stub/specs/auth-controller-rbac-stub.md`
- Design: pendiente (T0 de tasks.md).
- Tasks: `openspec/changes/2026-06-24-auth-controller-rbac-stub/tasks.md`

---

## [1.5.0-rc5] - 2026-06-25

Minor release. Dos flags opt-in para `php artisan mk:make:auth-user {Scope}`:

1. `--profile-fields=<csv>` — columnas adicionales para el scope (per-scope, no compartidas).
2. `--verify-email` — flujo completo de verificación por email.

Ambos flags son **opt-in**. Sin ellos, el comportamiento es **idéntico a v1.5.0-rc4** (BC preservada).

### Added

- **`mk:make:auth-user --profile-fields=<csv>`** (R-PKG-011): declarativo de columnas adicionales para el scope. Cada field se genera como columna `string` nullable en la tabla del scope, se incluye en `$fillable` y `$casts` del modelo, y se expone vía:
  - `GET /api/{scope}/auth/me` (read via `$fillable`)
  - `PATCH /api/{scope}/auth/me` (update con validación `required|string|max:255` por default)
  - `POST /api/{scope}/auth/register` (write al crear)
- **`mk:make:auth-user --verify-email`** (R-PKG-011): habilita el flujo completo de verificación por email:
  - Columna `email_verified_at` en migration (nullable, opt-in)
  - Cast `'email_verified_at' => 'datetime'` en `$casts` del modelo (opt-in)
  - Endpoint `GET /api/{scope}/auth/email/verify/{id}/{hash}` (signed URL)
  - Endpoint `POST /api/{scope}/auth/email/resend` (throttle 6,1, auth:scope required)
  - Dispatch de `Illuminate\Auth\Notifications\VerifyEmail` queueable en `register()`
  - Métodos `verifyEmail()` + `resendVerification()` en el AuthController generado
- **Refactor de `email_verified_at`**: antes (R-PKG-009) se generaba siempre que `--login-field=email`; ahora depende del flag `--verify-email` (opt-in). R-PKG-011 ADR-009 simplifica la matriz de combinaciones.
- **Método `register()`** en AuthController: NUEVO endpoint `POST /api/{scope}/auth/register`. Se genera solo si `--profile-fields` o `--verify-email` está activo. BC: NO existe en v1.5.0-rc4.

### Ortogonalidad

Los 4 flags del command son independientes y combinables:

```bash
# Default (BC con v1.5.0-rc4)
php artisan mk:make:auth-user Customer

# Solo login field no-email (R-PKG-009)
php artisan mk:make:auth-user Admin --login-field=ci

# Solo RBAC (R-PKG-010)
php artisan mk:make:auth-user Admin --with-auth-rbac

# Solo profile fields (R-PKG-011)
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# Solo email verification (R-PKG-011)
php artisan mk:make:auth-user Admin --verify-email

# Full combo (RETO Bolivia Admin)
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac --profile-fields=name,dni,phone --verify-email
```

### Compatibility

- **BC preservada**: sin `--profile-fields` y sin `--verify-email`, `mk:make:auth-user Admin` genera **exactamente lo mismo** que v1.5.0-rc4. 0 regresiones verificadas con 372 tests Pest verdes (336 baseline + 36 nuevos).
- **`--verify-email` solo aplica si `--login-field=email`**: si se pide el flag con login-field != email (e.g. `--verify-email --login-field=ci`), el scaffolder ignora `--verify-email` con warning explícito. ADR-009.
- **Reserved columns**: `--profile-fields` rechaza colisiones con `id`, `password`, `auth_scope`, `client_id`, `remember_token`, `created_at`, `updated_at`, `email_verified_at`, y con el `--login-field` (que ya tiene su propia columna).
- **Duplicados**: `--profile-fields=name,dni,name` se rechaza fail-fast (no se genera nada).
- **Tipos**: en v1.5.0-rc5 todos los profile fields son `string`. Tipos custom (`date`, `int`, `decimal`, `bool`, `datetime`, `json`, `text`) en v1.6.0-rc1 con `--profile-fields=key:type,...` (sintaxis extendida en el mismo flag, default `string` cuando no se especifica tipo).

### Anti-patterns

- ❌ **No usar `--profile-fields` con columnas reservadas**: `--profile-fields=password` o `--profile-fields=email` (si `--login-field=email`) se rechazan. La razón: colisión con columnas críticas que tienen comportamiento distinto.
- ❌ **No combinar `--verify-email` con `--login-field != email`**: el flag se ignora silenciosamente. Para casos atípicos (e.g. SMS verification de CI), override el AuthController directamente.
- ❌ **No usar `--profile-fields` para compartir datos entre scopes**: cada scope tiene su propia tabla y modelo. Para datos compartidos, exponer una `Api\*` interface del scope que los posee (MME/R-MK-001).

### Spec

- Spec: `openspec/changes/2026-06-25-profile-fields-per-scope/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-per-scope/specs/profile-fields.md`
- Design: `openspec/changes/2026-06-25-profile-fields-per-scope/design.md`
- Tasks: `openspec/changes/2026-06-25-profile-fields-per-scope/tasks.md`

---

## [1.4.0] - 2026-06-24

Minor release. **Breaking change** in the response shape of all 6 auth-user
endpoints generated by `php artisan mk:make:auth-user {Scope}`. The
generated `AuthController` is now aligned with the rest of the package
(BaseController envelope, TokenIssuer service, plugin instrumentation).

### Changed (BC)

**Response shape** of every endpoint generated by the auth-user scaffolder.

Before (v1.3.x, ad-hoc shapes via `response()->json(...)`):
```json
POST /api/{scope}/auth/login
{ "access_token": "...", "token_type": "Bearer", "{scope}": {...} }

POST /api/{scope}/auth/logout
{ "message": "Sesión cerrada." }

POST /api/{scope}/auth/me
{ "id": "...", "name": "...", "email": "..." }

POST /api/{scope}/auth/forgot
{ "message": "Si el email existe, ..." }

POST /api/{scope}/auth/refresh
{ "error": "not_implemented", "hint": "..." }   HTTP 501

POST /api/{scope}/auth/reset
{ "error": "not_implemented", "hint": "..." }   HTTP 501
```

After (v1.4.0, package envelope via `sendResponse()` / `sendError()`):
```json
POST /api/{scope}/auth/login
{ "success": true, "message": "Login exitoso",
  "data": { "access_token": "...", "refresh_token": "...",
            "token_type": "Bearer", "expires_in": 900,
            "{scope}": {...} },
  "debugMsg": [] }

POST /api/{scope}/auth/logout
{ "success": true, "message": "Sesión cerrada.", "data": true, "debugMsg": [] }

POST /api/{scope}/auth/me
{ "success": true, "message": "",
  "data": { "id": "...", "name": "...", "email": "..." },
  "debugMsg": [] }

POST /api/{scope}/auth/forgot
{ "success": true, "message": "Si el email existe, ...",
  "data": null, "debugMsg": [] }

POST /api/{scope}/auth/refresh
{ "success": false, "message": "not_implemented",
  "errors": { "hint": "..." }, "debugMsg": [] }   HTTP 501

POST /api/{scope}/auth/reset
{ "success": false, "message": "not_implemented",
  "errors": { "hint": "..." }, "debugMsg": [] }   HTTP 501
```

**Consumers MUST update** their client code to read from `response.data`
instead of `response` directly. Affects:
- Frontend SPA / mobile that reads the auth response.
- Tests that pinned the v1.3.x shape.

The package's `getDebugData()` is now also active on these endpoints
when the requester has `super-admin` or `dev` role and `?debug=true&_debug=1`
is set (gated by R2-010). Previously the auth endpoints had no debug.

### Changed (BC, internal)

**`$user->createToken(...)` → `TokenIssuer::issueAccessToken($user)`**
and `TokenIssuer::issueRefreshToken($user)`. Direct benefit: TTLs now
come from `mk_director.auth.ttl.access_seconds` / `refresh_seconds` config
instead of being hardcoded at the call site. The `auth_scope` ability is
now baked correctly via `TokenIssuer::buildAccessAbilities()`.

**`extends Illuminate\Routing\Controller` → `extends Mk\Director\Controllers\BaseController`**.
This is the root cause of the BC. The new base class provides:
- `sendResponse()` / `sendError()` (used by the new envelope above)
- `autoTransform()` (Model → API Resource transparent in `me`)
- `getDebugData()` (EXPLAIN gated by role, see above)
- Plugin instrumentation hook (audit log, multi-tenancy)

### Added

- `TokenIssuer` is now a real, mandatory import in the generated
  AuthController (was mentioned in the docblock only). Login issues both
  access and refresh tokens through it; refresh and reset methods
  document the `TokenIssuer + Sanctum v4 id|plaintext parsing` path.

- New `expires_in` field in the login response, sourced from
  `mk_director.auth.ttl.access_seconds` (default 900 = 15 min).

### Notes for consumers

- **RETO** (which already implemented its own `refresh` method based on
  the v1.3.x stub) will need to:
  1. Re-emit its access + refresh tokens through `TokenIssuer` if it
     wasn't already (most likely it was — `RETO` uses Sanctum v4 which
     is what `TokenIssuer` wraps).
  2. Wrap any `response()->json(...)` calls in `sendResponse()` /
     `sendError()` for envelope consistency.
  3. Update the frontend / mobile code to read `response.data` instead
     of `response` directly for the auth endpoints.
- The `mk:make:auth-user` command's output now also tells the dev
  to implement via `TokenIssuer`.

### Test coverage

- 3 new source-parsing tests in `MakeAuthUserCommandTest`:
  - `auth-user auth-controller stub extends BaseController (bug 1.4.0-001)`
  - `auth-user auth-controller stub uses sendResponse / sendError envelope (bug 1.4.0-002)`
  - `auth-user auth-controller stub uses TokenIssuer::issueAccessToken in login (bug 1.4.0-003)`
- Full Pest suite: **270 passed, 0 failed** (267 + 3 new), 13 pre-existing
  deprecations unrelated.
- End-to-end validated against `apps/sandbox-laravel` with a fresh
  `TestScope`:
  - generated `AuthController` extends `Mk\Director\Controllers\BaseController` ✓
  - all 6 endpoints route through `sendResponse` / `sendError` ✓
  - `TokenIssuer` is instantiated in `login` and `issueAccessToken` called ✓
  - `php artisan route:list` shows 6 routes under `api/test_scope/auth/*` ✓

## [1.3.2] - 2026-06-24

Documentation-only patch. No PHP code changes, no public API change, no
behavior change. The package version is bumped to keep the convention
that every tag in the changelog has a corresponding release.

### Fixed

- **`README.md` — "Magic CRUD Controller" reference was outdated.** The
  feature list pointed at `Mk\Director\Controllers\Controller` (the
  template-method base), but the actual "Magic CRUD" implementation is
  `SmartController` (with the `CRUDSmart` trait). The feature is now
  described accurately: declarative ABM via `extends SmartController`
  + `$mkConfig`, with automatic plugin instrumentation.

- **`DEVELOPER_GUIDE.md` §3 — example was using the manual pattern.**
  The example showed `class SurveyController extends BaseController
  { use CRUDSmart; ... }`, which works but is the verbose way (the
  dev has to remember to add the trait manually). The modern,
  scaffolder-generated way is `class SurveyController extends
  SmartController` (the trait is already on the parent). The example
  is now aligned with what `mk:module` actually generates and what
  the `mk-director-laravel` skill documents.

### Notes for consumers

- Zero impact on existing projects. The `Controller` (template method)
  and `BaseController` classes are still available; this PR only
  modernizes the documentation examples.
- The `mk-director-laravel` skill at
  `.makromania/agency/skills/mk-director-laravel/SKILL.md` was
  audited and was already correct (uses `SmartController`).
- The mk-director monorepo guides
  (`docs/MIGRATION_GUIDE.md`, `docs/API_REFERENCE_LARAVEL.md`,
  `docs/guides/CREATE_MODULE.md`, `docs/guides/GETTING_STARTED.md`,
  `docs/guides/AUTH.md`, `docs/guides/MULTI_TENANT.md`) were audited
  and were already correct (no changes needed).

### Test coverage

- No new tests — these are documentation-only changes.
- Defensive run of the full Pest suite: 267 passed, 0 failed.

## [1.3.1] - 2026-06-24

Patch release. Fixes three real bugs in the `php artisan mk:make:auth-user {Scope}`
scaffolder that were reported by the RETO project (the first real-world consumer
of v1.3.0) immediately after release. None of the fixes change the public API
or the command signature — the generated module is functionally identical,
just correct out of the box.

### Fixed

- **ServiceProvider FQCN was missing the `Providers\` subnamespace** (bug
  1.3.0-001). `MakeAuthUserCommand::registerServiceProvider()` used to write
  `App\Modules\{Scope}\{Scope}ServiceProvider::class` into
  `bootstrap/providers.php`, but the stub generates
  `App\Modules\{Scope}\Providers\{Scope}ServiceProvider` (with the
  subnamespace). Result: Laravel could not resolve the class, the module
  silently failed to load, and `route:list` showed zero routes for the
  scope. The FQCN is now built with the correct subnamespace. The previous
  bug is pinned by a new source-parsing test
  (`MakeAuthUserCommandTest::test(...builds the ServiceProvider FQCN with
  the Providers subnamespace...)`) that asserts both the new form is
  present and the old form is absent.

- **Hardcoded `create_admins_table` migration duplicated the scaffolder's
  output** (bug 1.3.0-002). The package used to ship
  `src/Auth/Database/Migrations/2026_06_10_000006_create_admins_table.php`
  as a leftover from the original Admin scope, and `MkServiceProvider::boot()`
  auto-loaded it via `loadMigrationsFrom`. When a consumer ran
  `php artisan mk:make:auth-user Admin` and then `php artisan migrate`,
  both the package's migration and the scaffolder's generated migration
  tried to create the `admins` table, producing a "Table 'admins' already
  exists" failure. The hardcoded file has been removed: the scaffolder is
  the canonical source for the scope's table. Existing consumers that
  already ran the hardcoded migration keep the entry in their
  `migrations` table and the table itself; subsequent `migrate` calls
  skip the now-missing file. Pinned by
  `MakeAuthUserCommandTest::test(...does NOT ship a hardcoded
  create_admins_table migration...)`.

- **Routes stub produced paths without the `api/` prefix** (bug
  1.3.0-003). `auth-user.routes.stub` used to generate
  `Route::prefix('{{moduleNameLower}}/auth')`, producing
  `/admin/auth/login` — but the `AuthController` docblock, the
  scaffolder's success output, and the CHANGELOG entry for 1.3.0 all
  advertised `/api/admin/auth/login`. Root cause: Laravel 11+
  `loadRoutesFrom` from a ServiceProvider does NOT inherit the
  `apiPrefix` configured in `bootstrap/app.php` (that only applies to
  the central `routes/api.php`). The stub now emits
  `Route::prefix('api/{{moduleNameLower}}/auth')`. The existing test
  that asserted the old (broken) prefix has been updated to match
  the correct behavior.

### Test coverage

- 2 new tests in `MakeAuthUserCommandTest` (one pins the correct
  FQCN, one pins the absence of the hardcoded migration).
- 1 updated test in `MakeAuthUserCommandTest` (the routes prefix
  expectation, which used to encode the bug as the "expected"
  behavior).

### Notes for consumers

- The fixes apply to fresh runs of `php artisan mk:make:auth-user`.
  Consumers that already applied manual workarounds (such as RETO,
  which fixed the FQCN in `bootstrap/providers.php` by hand and
  deleted the scaffolder's duplicate migration) are unaffected —
  their workarounds remain valid; the fix simply removes the
  requirement for those workarounds on future consumers.

## [1.3.0] - 2026-06-24

This release closes the audit-driven gap on auth scaffolding (PRs #7,
#8, #9 of this repo, plus PRs #36, #37, #38 of the monorepo). The
package is now feature-complete on the auth front: every scope
scaffolder, the skill deploy flow, the super-admin creator, and the
hardened config block all ship together.

### Added

- **`php artisan mk:make:auth-user {Scope}`** — scaffolder de un scope de
  autenticación MK completo. Cierra el gap entre la doc
  (`docs/guides/AUTH.md` § "Generating a new scope") y el código: el
  comando se documentaba desde 1.0.0 pero no existía en `src/`.
  Genera: `Models/{Scope}.php` (extends `AuthUser` con
  `setAuthScope` en constructor), migration con `auth_scope` indexado,
  `Http/Controllers/AuthController.php` (login/refresh/logout/me/forgot/reset
  como skeleton con TODOs), `Http/Routes/api.php` con
  `prefix('api/{scope}/auth')` + `mk.auth:{scope}` middleware, y
  `{Scope}ServiceProvider` auto-registrado en `bootstrap/providers.php`
  (Laravel 11+) o `config/app.php` (Laravel 10).
  El command **NO** modifica `config/auth.php` del consumer (decisión
  consciente, least surprise): imprime los snippets a agregar
  (guard + provider).
  Stubs: `src/Stubs/auth-user.{model,migration,auth-controller,routes,service-provider}.stub`.
  Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6.
  Diff: `src/Console/Commands/MakeAuthUserCommand.php` (nuevo, 256 líneas).
  Tests: `tests/Unit/Console/MakeAuthUserCommandTest.php` (10 casos),
  `tests/Unit/MakeAuthUserCommandRegisteredTest.php` (2 casos).

- **`php artisan mk:skill:list`** — lista las skills disponibles para
  el ecosistema MK-Director. Escanea tres ubicaciones: paquete,
  agencia (`~/.mavis/agents/main/skills` + `.makromania/agency/skills`),
  y locales (`.makromania/agency/skills` + `.agents/skills` del
  proyecto actual). Muestra una tabla con `Name / Source / Deployed /
  Path`. Read-only, no toca archivos. Útil como punto de partida
  antes de `mk:skill:deploy`. Diff:
  `src/Console/Commands/MkSkillListCommand.php`. Test:
  `tests/Unit/Console/MkSkillListCommandTest.php` (6 casos).

- **`php artisan mk:skill:deploy {nombre?}`** — deploya una skill al
  proyecto actual. Es **asistente, no invasivo**: no toca config,
  no registra providers, no modifica composer. Solo copia
  `SKILL.md` a la ubicación autodetectada y agrega/actualiza la
  sección `## Skills deployadas` en `AGENTS.md` (idempotente: si la
  skill ya está listada, no duplica; si `AGENTS.md` no existe, lo
  crea con un template mínimo).
  Fuentes de la skill (en orden de prioridad):
  1. `~/.mavis/agents/main/skills/{nombre}/SKILL.md` (Mavis agent)
  2. `.makromania/agency/skills/{nombre}/SKILL.md` (agencia del workspace)
  3. `src/Skills/{nombre}/SKILL.md` (futuro: paquete)
  Destino (en orden de prioridad):
  1. `--to=` explícito del usuario
  2. `.makromania/agency/skills/` si existe
  3. `.agents/skills/` si existe
  4. Prompt al usuario con default `.makromania/agency/skills/`
  Flags: `--to=` para forzar destino, `--dry-run` para simular.
  Diff: `src/Console/Commands/MkSkillDeployCommand.php`. Test:
  `tests/Unit/Console/MkSkillDeployCommandTest.php` (7 casos).

- **`php artisan mk:auth:create-super-admin`** — crea el primer usuario
  super-admin del scope "admin". El command se documentaba en
  `docs/GETTING_STARTED.md` desde 1.0.0 pero nunca se implementó;
  el audit 2026-06-24 cerró ese gap.
  Comportamiento:
  - Pregunta email, name, password (con confirmación). Acepta
    `--email=`, `--name=`, `--password=` para skip prompts en CI.
  - Valida formato de email (FILTER_VALIDATE_EMAIL) y longitud
    mínima de password (>= 8 chars).
  - Falla rápido con mensaje accionable si la clase
    `App\Modules\Admin\Models\Admin` no existe (le dice al dev que
    corra `mk:make:auth-user Admin` primero).
  - Es idempotente: re-correr con el mismo email sale con success
    y un warning, sin duplicar el row.
  - Asigna el role "super-admin" (auto-creado si no existe, con
    guard = auth_scope del user = "admin").
  - Asigna la ability `*` como grant directo (path `ability_user`)
    para que el super-admin no dependa de un seeder adicional.
  Diff: `src/Console/Commands/AuthCreateSuperAdminCommand.php`
  (nuevo, ~150 líneas). Test:
  `tests/Unit/Console/AuthCreateSuperAdminCommandTest.php` (7 casos).

### Changed

- **`php artisan mk:update`** — agrega un step opt-in al final del
  flujo que sugiere al dev revisar y deployar skills nuevas del
  ecosistema. Solo dispara si la respuesta a "¿Querés revisar y
  deployar las skills nuevas del ecosistema MK?" es positiva, y
  se salta en `--dry-run`. Implementado como
  `promptForSkillDeploy()` en `MkUpdateCommand`. Diff:
  `src/Console/Commands/MkUpdateCommand.php`. Test:
  `tests/Unit/MkSkillCommandsTest.php` (4 casos).

### Fixed (R-NEW-001 compliant)

- **`config/mk_director.php` `auth` block ya no rompe DDD.**
  Antes:
  - `'user_model' => \App\Models\User::class` — hardcodeaba el modelo
    default de Laravel, que rompe MME (los modelos viven en
    `App\Modules\<Scope>\Models\<Scope>`, no en `App\Models\*`).
  - `'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE', 'App\\Modules\\Admin\\Models\\Admin')` —
    asumía que el consumer tiene un módulo Admin, lo que rompe DDD
    (el paquete no debe conocer los módulos del consumer).
  Después: ambos leen de `env(...)` con default `null`. El consumer los
  define en su propio `config/mk_director.php` publicado, después de
  generar un scope con `mk:make:auth-user {Scope}`.
  Diff: `config/mk_director.php`.
  Test: `tests/Unit/MkDirectorConfigDefaultsTest.php` (4 casos).

### Docs

- The `auth` block del config ya no se etiqueta como `Experimental` —
  el command existe, la migración `auth_users` está consolidada, y
  `AUTH.md` documenta el flujo. El paquete deja de marcarse a sí
  mismo como experimental.
- Monorepo companion PRs: #36 (`mk:make:auth-user` doc), #37
  (`mk-update` npm doc + drift fixes), #38 (replace Tinker snippet
  in `GETTING_STARTED.md`).

## [Unreleased]

### Added

### Changed

### Fixed

### Deprecated

### Removed

### Security

## [1.2.2-rc1] - 2026-06-18

Hardening sprint on top of `1.2.0-rc1`. Closes 6 of the 35 Medium/Low
findings from the original 4R audit; the remaining 29 are deferred to
1.3.0 (see `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md`).

### Security (R-NEW-001: every entry below cites the diff to `src/` and the test that covers it)

- **R2-010 — `BaseController::getDebugData` now requires an authenticated
  user with `super-admin` or `dev` role.** Previously, any request with
  `?debug=true&_debug=1` would get EXPLAIN + raw query bindings — a
  PII / schema leak waiting to happen. The gate is fail-safe: apps
  whose User model does not implement `hasRole()` get an empty debug
  payload, not a 500. Diff: `src/Controllers/BaseController.php`.
  Test: `tests/Unit/BaseControllerDebugGateTest.php` (5 cases).

- **R3-014 — `role_user.user_id` foreign key to `auth_users.id`.** The
  pivot was missing the FK, so deleting an `auth_users` row left orphan
  rows in `role_user`. New migration
  `src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php`
  adds the FK with `cascadeOnDelete()`. Idempotent: skips silently if
  the FK already exists or the `role_user` table is missing
  (consumer apps with custom migrations). `down()` is wrapped in
  `try/catch` so re-running on a fresh install does not crash. Test:
  `tests/Unit/Auth/RoleUserFkMigrationTest.php` (3 cases).

### Performance (R4-004 + R2-007)

- **DB::listen no longer runs on reads or on system-table writes.**
  Previously the "magic cache" listener ran on EVERY query and only
  excluded the `cache` table via `str_contains`. Cron writes to
  `jobs`, `migrations`, `sessions`, `password_resets`, and the
  `failed_jobs` table would trigger cache invalidations on tables
  the cache never knew about. The new listener:
  (a) skips a hard-coded `$systemTables` allowlist (migrations,
  cache, cache_locks, sessions, password_resets, password_reset_tokens,
  jobs, job_batches, failed_jobs, telescope_*), and
  (b) only acts on writes (`INSERT` / `UPDATE` / `DELETE`).
  Known limitation (documented in the docblock): the regex does not
  match `REPLACE`, `TRUNCATE`, raw stored-procedure calls, or
  Eloquent `upsert()`. Callers using those patterns must
  `Cache::tags([$table . '_all'])->flush()` manually.
  Diff: `src/MkServiceProvider.php`.
  Test: `tests/Unit/MkServiceProviderCacheListenerTest.php` (5 cases).

### Tooling

- **R1-004 — Pint installed and configured.** `pint.json` declares
  preset `laravel` + rules `declare_strict_types` + `ordered_imports
  alpha`. Scripts added to `composer.json`:
  `composer lint` (pint --test), `composer lint:fix` (pint), and
  `composer security:lint`. **The 93 file diff that Pint suggests
  has NOT been applied in this sprint** — it would contaminate the
  PR with unrelated cosmetic changes. Run `composer lint:fix` locally
  before opening a PR. The sprint is shipped with the tool installed
  and configured; the project-wide apply is deferred to 1.3.0.

- **R2-008 + R2-009 — `php artisan mk:security-lint` command.** New
  source-parsing linter with three checks: `$guarded = []` on
  Eloquent models, missing `belongsTo` foreign keys, and
  `MkMultiTenantPlugin::$tenantColumn` set to a value outside the
  whitelist (`tenant_id`, `client_id`, `org_id`, `company_id`).
  Exit codes: `0` on success, `1` on any error. `--strict` flag
  escalates warnings to failures. `--format=json` for CI integration.
  Source-parsing only — no Laravel app boot required, runs in < 2s
  for 100 models. Diff: `src/Console/Commands/SecurityLintCommand.php`
  (new file, 234 lines), registered in `MkServiceProvider`.
  Test: `tests/Unit/SecurityLintCommandTest.php` (6 cases).
  Spec: `openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md`.

### Observability

- **R1-005 (advisory) — `mk:check` warns on unguarded SmartControllers.**
  The `mk:status` command (`MkCheckCommand`) now scans each
  SmartController subclass and emits a `WARN` finding if the source
  has no obvious auth wiring (no `middleware(`, no `MkAuthenticate` /
  `MkAbility` reference, no auth-aware `__construct`). This is
  advisory, not enforcement: SmartController does not enforce auth by
  itself (BC: pre-existing apps rely on it being a pass-through), and
  the responsibility to add a middleware remains the developer's.
  Diff: `src/Console/Commands/MkCheckCommand.php`.
  Test: `tests/Unit/MkCheckCommandAuthWarningTest.php` (4 cases).

### Deferred to 1.3.0

- **R1-001** ADR `Contracts/` vs `Api/` (architectural decision
  required from Mario).
- **R1-003** phpstan + Larastan. Larastan 3.10.0 (the only Laravel 13
  compatible version as of 2026-06-18) crashes on
  `Undefined constant "Larastan\Larastan\LARAVEL_VERSION"` in
  `LarastanStubFilesExtension.php:25`. Bug is upstream. The
  install/remove dance confirmed the lockfile stays clean if we
  wait for Larastan 3.11+ to ship.
- **R1-005 (test)** end-to-end test of `LintBoundariesCommand` that
  actually invokes `handle()`. Out of scope for hardening.
- **R2-001** rewrite of commit `6303844` (`sec(laravel): mitigate
  SQL injection in ListManager`). Git history is git history.
- 27 additional Medium/Low items from the 4R audit. Tracked in
  `openspec/changes/2026-06-18-1.2.2-hardening/proposal.md` and
  to be re-prioritized at the start of the 1.3.0 cycle.

### Process notes

- All 6 task commits are atomic and follow the
  `<type>(<scope>): <subject>` convention.
- The 3 `sec:`-prefixed commits (R2-010) cite both the diff path and
  the test file. R-NEW-001 satisfied.
- The Pint diff (93 files) is NOT shipped in this sprint — see the
  Tooling section above.
- `phpstan` install was attempted, locked to the package's
  compatibility window, then reverted when the Larastan bug
  surfaced. The lockfile is byte-identical to `1.2.0-rc1`.

## [1.2.0-rc1] - 2026-06-17

### Security (R-NEW-001: every entry below cites the diff to `src/`)

The audit found 57 issues (10 critical, 17 high, 19 medium, 12 low).
This RC closes all 10 P0 and all 17 P0-targeted high-severity findings.
Items #2–#4 below are code-only changes that fail loudly if a consumer
was relying on the previous (looser) behavior — there is no data
migration for those.

- **R4-001 — `HasAbilities::canMk` now delegates to `AbilityResolver`.**
  The resolver caches the resolved ability names per user with a
  configurable TTL (default 300s) and short-circuits via Sanctum
  `currentAccessToken()->can()` before any DB query. Every mutator
  (`giveAbilityTo`, `revokeAbilityTo`, `syncDirectAbilities`,
  `assignRole`, `revokeRole`, `syncRoles`) invalidates the cache so
  the next `canMk` reads fresh data. Commit 656360e.

- **R1-002 — `ability_user` migration published.** The pivot table
  for direct ability grants was referenced by `HasAbilities` but
  the migration was never shipped. Now in
  `src/Auth/Database/Migrations/2026_06_10_000007_create_ability_user_table.php`
  with FK to `abilities`, `uuid('user_id')` matching `auth_users.id`,
  and a unique composite index on `(ability_id, user_id, user_type)`.
  Commit d015cfc.

- **R3-005 — `AuthUser` docblock declares `$id` as `string`.**
  Was `@property int $id` even after the UUID migration. Tests parse
  the source file directly because the class uses Sanctum's
  `HasApiTokens` (not in `composer.json`). Commit 568111d.

- **R4-002 — `MkAuthenticate` eager-loads `roles.abilities` and
  `directAbilities` after the scope resolver runs.** Closes the N+1
  every downstream `canMk` would otherwise trigger. Commit 74bfe95.

- **R2-002 / R2-003 — `MkAbility` rejects empty ability lists and
  short-circuits via Sanctum.** Before: `Route::middleware(['mk.ability:'])`
  silently passed every request through (privilege escalation trap).
  Now: returns 500 + `ERR_MIDDLEWARE_MISCONFIGURED`. Sanctum
  `currentAccessToken()->can($ability)` is checked before the role/
  direct-grants path. Commit 1d5ffdb.

- **R2-004 — `AuthUser` uses `HasTenantMembership`; `TenantResolver`
  validates user↔tenant membership.** The middleware now reads the
  user's tenant via `$user->getTenantId()` and returns 403
  `ERR_TENANT_MISMATCH` if the resolved tenant differs. Prevents
  tenant-A tokens from accessing tenant-B data via header. Commit 5dd846d.

- **R2-005 / R2-006 — `TenantContext` is flushed at request end;
  `HasTenantScope` is per-model opt-in.** `MkServiceProvider::boot`
  registers a `terminating` callback that calls `TenantContext::flush()`
  so long-lived workers (Octane / Swoole) do not leak tenant state
  across requests. `HasTenantScope` now requires `protected static
  bool $usesTenant = true` per model — adding the trait alone is a
  no-op. Commit 9a807da.

- **R2-009 / R2-018 / R4-003 — `MkMultiTenantPlugin` whitelist,
  strict comparison, and mutex with `HasTenantScope`.** The plugin
  rejects any `$tenantColumn` not in the documented whitelist
  (`tenant_id`, `client_id`, `org_id`, `company_id`). `beforeDelete`
  uses strict `!==` (was `!=`) to prevent the int-vs-string coercion
  bug where `'00000000-...' (string) != 0 (int)` was truthy. When the
  model already uses `HasTenantScope` with `$usesTenant = true`, the
  plugin skips its own `where()` to avoid applying the tenant
  predicate twice. Commit 7004a3d.

- **R2-014 / R3-007 / R2-012 — `ListManager` LIKE escape, operator
  whitelist, and `restoreState` sanitize.** `escapeLikeWildcards()`
  wraps `addcslashes($value, '\\%_')` and is used by both `applySearch`
  and the `like` filter operator so a search for `'50%'` matches
  the literal string instead of every row containing `50`. The
  search term is also capped at `mk_director.search.max_length`
  (default 256). `applyFilterOperator` now throws
  `InvalidArgumentException` on unknown operators (was a silent
  fallback to `=`). `restoreState` hashes the storage key with HMAC-
  SHA256 of `app.key` and sanitizes the rehydrated filter/sort state
  against the same whitelists used by the apply path. Commit 373007d.

- **R4-005 / R4-006 / R2-016 — Performance + symlink rejection.**
  `OpenApiController::spec` is wrapped in `Cache::remember()` with a
  24h TTL; `mk:generate-docs` calls `Cache::forget()` on the same
  key. `ModuleProviderRegistry::discover()` caches the discovered
  providers for 1h, keys the cache on `md5(realpath(modules path))`
  so any directory change invalidates automatically, and rejects
  symlinked module directories (R2-016) — a symlink under
  `app/Modules` pointing to `/tmp/evil` would otherwise be discovered
  as a legitimate module. Commit 77ad693.

### Process (R-NEW-001)

- **CI: monorepo now has a `pest-laravel` job** that runs
  `./vendor/bin/pest` against the package and is in the `build`
  dependency chain. Path filter so the job only fires when the
  package or the workflow file changes. This closes the gap that
  allowed the previous sprint (PR #3) to declare 6 security fixes
  and ship only 3 — the monorepo CI never exercised the sub-repo's
  pest suite.
- **Spec: `openspec/specs/architecture/modular-encapsulation.md`**
  documents the rule with the three required scenarios (claim
  without diff, claim with diff but without test, monorepo CI
  does not run pest-laravel). R-NEW-001 is enforced at review time.

### Testing infrastructure

- `MkLaravelTestCase` — boots a minimal Laravel Container with
  config/cache/db/files bindings so the 5 pre-existing pest failures
  (R3-009, R3-010, R3-011) are green. No new composer dependency
  (we deliberately avoided `orchestra/testbench` which would pull
  in the full application kernel).
- `StrictTypesTest` extended to scan `tests/` (was `src/` only) and
  asserts `declare(strict_types=1)` is positioned right after
  `<?php` — 8 pre-existing test files were missing the declaration.
- `AuthUserMigrationTest`, `RoleUserMigrationTest` — refactored to
  parse the migration source directly because the chainable
  `Blueprint` mock fights Laravel's real signatures.

### Documentation

- `docs/UPGRADE_1.2.md` — full upgrade guide covering the breaking
  changes, pre-flight checklist, runbook for the UUID migration,
  rollback procedure.
- `bin/migrate-1.1-to-1.2.php` — standalone CLI script with
  `--dry-run`, `--connection`, `--chunk`, `--help`. Idempotent,
  refuses to run outside CLI, refuses to migrate a table whose id
  is neither BIGINT nor CHAR(36).

### Notes

- **DO NOT publish to Packagist yet.** This RC is tagged locally so
  the team can validate against `apps/sandbox-laravel` before
  publishing. Mario will run `composer publish` from his machine
  after sandbox validation passes.
- 9 of the 12 medium-priority and all 12 low-priority findings from
  the audit are deferred to `1.2.2-hardening` (next sprint).

## [1.2.0] - 2026-06-17

### Security

- **A1 — SQL injection mitigado en `ListManager`.** `applyFilters` y `applyJoins` ahora
  requieren una whitelist explícita (`allowed_filters`, `allowed_joins`). Sin whitelist,
  cero filtros/joins se aplican (comportamiento defensivo por defecto). Cualquier campo
  fuera de la lista es ignorado silenciosamente antes de llegar a la query.

### Changed

- **A2 — `MkAuthMiddleware`: respuesta 401 nativa.** Import de `MkResponse` (clase
  inexistente) removido. Reemplazado por `response()->json(['success' => false,
  'message' => 'Unauthenticated.', 'code' => 'ERR_UNAUTHENTICATED'], 401)`.

- **A3 — Migración `auth_users`: ID como UUID.** `$table->id()` reemplazado por
  `$table->uuid('id')->primary()`. Requiere `HasUuids` en el modelo `AuthUser`.

- **A4 — Migración `role_user`: FK como UUID y default configurable.** `user_id`
  tipado como `uuid` (foreign key coherente con A3). El `user_type` por defecto
  ahora se lee de `config('mk_director.auth.default_user_type', 'App\\Models\\User')`.

- **A5 — `MkAuthenticate`: excepción correcta al faltar autenticación.** Reemplazado
  `MissingAbilityException` (de Sanctum, semántica incorrecta) por
  `AuthenticationException` (framework HTTP estándar de Laravel), pasando el guard
  activo como guards array para que el handler de exceptions produzca un 401 correcto.

- **A6 — `declare(strict_types=1)` en todo el source del paquete.** Todos los
  archivos PHP bajo `src/` tienen la declaración strict_types al inicio. Incluye
  un test automatizado `StrictTypesTest.php` que falla si algún archivo nuevo la omite.

> ⚠️ **Nota de migración**: A3 y A4 son cambios en migraciones de base de datos.
> Si ya corriste las migraciones anteriores en un entorno, necesitás rollback +
> re-run (`php artisan migrate:rollback --step=2 && php artisan migrate`).
> En entornos frescos no hay impacto.

## [1.1.1] - 2026-06-16


### Fixed (1.1.1)

- **`HasTenantScope` now uses `TenantScope` instead of an inline closure** —
  the global-scope class shipped in 1.1.0 was dead code at runtime because
  `HasTenantScope::booted()` registered an inline closure with the same
  `where($column, '=', $tenantId)` logic. The code review that landed
  alongside 1.1.0 flagged this as 🔴 bloqueante (B-3): the
  `TenantScope::apply()` was never invoked, the JSDoc on both classes
  claimed a contract that was not in effect, and the duplication was a
  footgun for any future change.
- **`TenantScope::apply()` is now context-aware.** It resolves the current
  tenant from the `TenantContext` singleton on every apply when no
  explicit `tenantId` was passed to the constructor. The constructor
  still accepts an explicit id (used by the 5 unit tests on
  `TenantScope` and by programmatic scopes); when set, that value wins.
  The "fresh read" semantics that the inline closure had are preserved,
  so the scope is safe under long-lived workers (Octane / Swoole) where
  the tenant can change mid-process.
- **No behavior change at the `HasTenantScope` trait boundary** — the
  trait still registers the scope under the alias `'tenant'`, the opt-in
  guard (`mk_director.tenant.enabled`) is unchanged, and the bypass path
  (`Model::withoutGlobalScope('tenant')`) still works.

### Cross-repo coordination

This 1.1.1 ships in lockstep with the `create-mk-director@1.1.1` CLI,
whose templates now require `makroz/director-laravel: ^1.1`. Publishing
this 1.1.1 is what makes the scaffoldeado de proyectos actually
`composer install`-able end-to-end. The CLI bump is in
`makroz/MK-Director#17` (PR #17, already merged into the monorepo's
`dev`).

## [1.1.0] - 2026-06-12

### Added (1.1.0)

- **Multi-tenant opt-in (M-1 of the 1.1.0 sprint).** Three new
  classes under `Mk\Director\Tenancy\*` ship behind a single
  config flag (`mk_director.tenant.enabled`, default `false`):
  - `TenantScope` — Eloquent `Scope` that filters by `tenant_id`
    on every `apply()`. No-op when no tenant is bound, so
    console / queue jobs see all rows by design.
  - `HasTenantScope` — model trait that auto-registers a
    closure-based global scope at `booted()` time. The closure
    reads `TenantContext` on every query, so the tenant id is
    always fresh (no Octane-style freeze).
  - `TenantContext` — singleton service that holds the current
    tenant id for the duration of a request.
  - `TenantResolver` — HTTP middleware that reads the tenant
    from a header (default `X-Tenant-ID`), a path segment, or a
    subdomain, and writes it into the `TenantContext`. Strict
    mode (default) returns 400 when the tenant cannot be
    resolved.
- **Config flag**: `config/mk_director.php` gains a `tenant` key
  (`enabled`, `resolver`, `header_name`, `model`, `strict`).
  The `MkServiceProvider` always registers the middleware on
  the `api` group, but the middleware itself short-circuits to
  a pass-through when `tenant.enabled = false` (opt-in per
  ADR-003).
- **Sandbox fixtures**: `apps/sandbox-laravel` now ships with
  a `mk_tenants` table, a `DemoTenantableModel` that uses
  `HasTenantScope`, and an end-to-end feature test in
  `tests/Feature/TenantScopeTest.php` that proves isolation
  (header, 400, cross-tenant 404, write-back with the right
  tenant).
- **Documentation**: `docs/guides/MULTI_TENANT.md` is updated
  to reflect the shipped feature (no more "Available from
  v1.1.0" warning — it ships with 1.1.0).

### Changed (1.1.0)

- `branch-alias.dev-main` in `composer.json` bumped from
  `1.0.x-dev` to `1.1.x-dev` to track the new minor line.
- `composer.json` gained `minimum-stability: dev` +
  `prefer-stable: true` so the dev toolchain (Pest 5 has only
  RC releases at the time of this writing) installs cleanly
  without affecting consumers (the dev deps are not
  installed by `composer require`).
- `MkServiceProvider::registerTenantMiddleware()` now always
  registers the middleware on the `api` group. The middleware
  is the one that checks `tenant.enabled` and short-circuits.
  This is more flexible than registering conditionally at
  boot — flipping the config at runtime (e.g. in tests)
  picks up the new state without re-booting the framework.

### Fixed (1.1.0)

- `HasTenantScope`'s closure scope was originally typed as
  `function (Model $model)`, but Eloquent invokes closure
  scopes with the **Builder** (not the model). The
  signature is now `function (Builder $builder)`, and the
  model is obtained via `$builder->getModel()`. This was
  caught and fixed before merge by the sandbox feature
  test in `tests/Feature/TenantScopeTest.php`.

## [1.0.0] - 2026-06-10

### Added (1.0.0)

- **Strict Laravel 13 support**: `makroz/director-laravel` 1.0.0 declares hard
  `^13.0` constraints on `illuminate/support`, `illuminate/database`, and
  `illuminate/http`. Laravel 10/11/12 are no longer supported.
- **PHP 8.4 baseline**: hard `^8.4` requirement (typed const, asymmetric
  visibility, property hooks). PHP 8.2/8.3 are no longer supported.
- **Pest 5 baseline**: `pestphp/pest` and `pestphp/pest-plugin-laravel` upgraded
  to `^5.0` for dev testing.
- **OpenAPI 3.x generation**: the `Mk\Director\Controllers\OpenApiController` and
  `Mk\Director\Services\OpenApiGeneratorService` (already in 0.0.1) now
  target the Laravel 13 schema introspection API (`Schema::getColumns()`)
  and are stable for production B2B usage.
- **SmartController + CRUDSmart trait**: stable, with `beforeList` /
  `beforeSearch` / `afterList` / `beforeCreate` / `afterCreate` /
  `beforeUpdate` / `afterUpdate` / `beforeDelete` / `afterDelete` hooks
  contractually documented via `Mk\Director\Contracts\MkModuleServiceInterface`.
- **ListManager** (`Mk\Director\Managers\ListManager`): stable API for
  pagination, filtering, sorting, search, dynamic includes, with-count,
  and joins.
- **Plugin Manager + MkPluginInterface**:
  - `Mk\Director\Managers\PluginManager` is now stable, with full hook
    coverage (`boot`, `beforeQuery`, `beforeSave`, `afterSave`,
    `beforeDelete`, `afterDelete`, `afterResponse`).
  - `Mk\Director\Plugins\FileStoragePlugin` (auto file uploads) and
    `Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin` +
    `Mk\Director\Plugins\Enterprise\MkAuditLoggerPlugin` ship as
    reference implementations.
- **DTO layer**: `Mk\Director\DTOs\MkDTO` and `Mk\Director\DTOs\DTOFactory`
  provide enum-aware type-safe hydration of DTOs and Eloquent casts
  (datetime, json, int, float, bool).
- **MkServiceProvider** registers the package's commands, config, routes
  (`/mk/openapi.json`, `/mk/docs`), and the global DB→Cache listener
  (the "Magic Cache" feature, opt-in via `mk_director.features.auto_cache`).
- **Artisan commands**:
  - `mk:status` — diagnose SmartControllers and their plugin health.
  - `mk:module {Name}` — scaffold a full MME module
    (Controllers, Contracts, DTOs, Enums, Models, Repositories, Requests,
    Resources, Routes, Services, ServiceProvider, Database/Migrations).
  - `mk:service {Name}` — generate a Service that implements
    `MkModuleServiceInterface`.
  - `mk:dto {Name}` — generate a DTO extending `MkDTO`.
  - `mk:generate-docs` — emit a static OpenAPI 3.x JSON file from all
    registered SmartControllers.
- **Module structure documentation**: see `DEVELOPER_GUIDE.md` for the
  canonical `Mk\Director` namespace layout and MME conventions.

### Changed (1.0.0)

- **Composer constraints** tightened from `^10|^11|^12|^13` → `^13.0`
  for all `illuminate/*` dependencies. This is a hard break for projects
  still on Laravel 12 or earlier.
- **PHP requirement** raised from `^8.2` → `^8.4`.
- **Pest test framework** raised from `^3.0` → `^5.0` (Pest 4 skipped
  deliberately; 5 is the long-term stable line).
- **MkServiceProvider** is now the only auto-discovered provider. The
  `extra.laravel.providers` array in `composer.json` is the source of
  truth (no `config/app.php` registration required on Laravel 11/12/13).

### Removed (1.0.0)

- **Support for Laravel 10, 11, 12**: the package no longer resolves on
  these versions. Downgrade to `0.0.x` if you need them.
- **Support for PHP 8.2 / 8.3**: the package relies on PHP 8.4 language
  features in future minor releases; 1.0.0 still compiles on 8.4 but
  is the floor.

### Fixed (1.0.0)

- `Mk\Director\Models\BaseModelBuilder::cacheGet()` and `cacheFirst()`
  are now consistent about the table-tagging fallback when the cache
  store does not support tags (file / database driver) — they no longer
  crash in CI / SQLite environments.
- `Mk\Director\DTOs\DTOFactory::makeFromArray()` correctly casts
  `datetime` and `timestamp` casts to `Carbon\Carbon` (instead of the
  non-existent `Carbon\DateTime`) via the explicit `Carbon\Carbon::parse()`
  path, fixing a regression introduced in 0.0.1.
- `Mk\Director\DTOs\MkDTO::fromArray()` now throws a clear
  `LogicException` when a `readonly` property is re-hydrated after
  construction, instead of silently failing on PHP 8.4.
- `Mk\Director\Traits\CRUDSmart::getCacheTags()` no longer crashes when
  the controller does not declare an explicit `cache_tags` config — it
  falls back to the model's table name, matching the documented contract.
- `Mk\Director\Services\OpenApiGeneratorService` gracefully degrades to
  `fillable`-based introspection when `Schema::getColumns()` is not
  available (e.g. when doctrine/dbal is not installed), preventing 500s
  on a missing driver in dev.
- `Mk\Director\Managers\PluginManager::auditRequirements()` now reports
  both `error` and `warning` findings without truncating the message,
  and is wired into `mk:status` for end-to-end diagnosability.

### Known issues / Deferred to 1.0.1+

- `Mk\Director\Middleware\MkAuthMiddleware` references a non-existent
  `Mk\Director\Utils\MkResponse` class (a pre-0.0.1 stub that was never
  extracted from the original `condaty` code base). The middleware is
  not auto-registered anywhere and is not loaded by the sandbox
  application, so it does not block 1.0.0 — but the file should be
  either completed (with a proper `MkResponse` utility) or removed in
  1.0.1. Tracking: see `tasks.md` follow-up notes.
- `tests/Unit/ListManagerTest.php` still uses a non-existent
  `new ListManager($request, $config)` constructor — the production
  class is a static-method utility. The sandbox-level
  `tests/Feature/ListManagerApiTest.php` covers the real behaviour
  via the HTTP layer. The unit test is a candidate for deletion in
  1.0.1.

### Security

- No security-relevant changes from 0.0.1 → 1.0.0. The `MkMultiTenantPlugin`
  continues to enforce `client_id` isolation in `beforeQuery`,
  `beforeSave`, and `beforeDelete` — this is the recommended path until
  a `TenantScope` global-scope variant ships in 1.1.
- The global DB→Cache listener (`mk_director.features.auto_cache`) only
  flushes on `INSERT/UPDATE/DELETE` against tables whose names appear
  in the SQL — it does not execute untrusted SQL.

### Upgrade guide from 0.0.x

1. Bump your host application's `composer.json`:
   - `"makroz/director-laravel": "^1.0"`
2. Ensure your host project runs on **PHP 8.4** (`composer.json` → `require.php`).
3. Ensure your host project runs on **Laravel 13**:
   `"laravel/framework": "^13.0"`.
4. If you maintain your own DTOs extending `MkDTO`, audit any `readonly`
   properties — the new hydration guard will surface
   re-initialization attempts as `LogicException`.
5. Update dev dependencies in your host project: `pestphp/pest ^5.0`,
   `pestphp/pest-plugin-laravel ^5.0`.
6. Re-run `php artisan package:discover` after `composer update` so
   `MkServiceProvider` is registered.

[1.0.0]: https://github.com/makromania/mk-director/releases/tag/mk-laravel-v1.0.0
