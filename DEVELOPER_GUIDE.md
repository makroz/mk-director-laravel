# 📖 Manual del Desarrollador — MK-Director (`mk-laravel`)

Bienvenido a la guía oficial de **MK-Director Core**, el motor de backend diseñado para acelerar el desarrollo de APIs robustas mediante una capa de abstracción potente sobre Laravel.

---

## 🏗️ 1. Arquitectura y Filosofía

MK-Director se basa en el principio de **Zero-Coupling** y **Configuración sobre Código**. El objetivo es que puedas definir el comportamiento de un módulo completo (CRUD, búsquedas, caché, plugins) simplemente configurando un arreglo en tu controlador.

### Flujo Estándar de Respuesta

**R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE**. Todas las respuestas de MK-Director siguen este formato canónico:

**Colección paginada** (single-level, sin `data.data`):

```json
{
  "success": true,
  "message": "",
  "data": [...items...],          // ← array directo de items (NO paginator nested)
  "__extraData": {                // ← top-level (sibling de `data`)
    "current_page": 1,
    "last_page": 10,
    "per_page": 20,
    "total": 200,
    "has_more_pages": true,
    "plugin_verified": true       // ← Inyectado por plugins
  },
  "debugMsg": []
}
```

**Single resource** (no collection, no pagination):

```json
{
  "success": true,
  "message": "Recurso creado con éxito",
  "data": {                       // ← objeto único (resource)
    "id": "uuid-...",
    "name": "Juan Pérez",
    ...
  },
  "debugMsg": []
}
```

**Error response**:

```json
{
  "success": false,
  "message": "Recurso no encontrado",
  "errors": {                     // ← validación errors (FormRequest)
    "email": ["El email es requerido"]
  },
  "debugMsg": []
}
```

> **R-PKG-024 (v1.7.0 GA) — PROHIBIDO `data.data`**: el legacy nested shape (`data: { data: [...], links, meta }`) está **ELIMINADO** en v1.7.0. No hay flag opt-in, no hay BC bridge. El envelope es siempre single-level. Frontend `@makroz/web` + `@makroz/mobile` consumen este shape vía `useMkList` / `useMkInfiniteList` (que ahora leen solo top-level `__extraData`).
>
> **Migration**: ver CHANGELOG.md `## [v1.7.0] - GA - Single-level envelope (R-PKG-024)` para el migration guide completo (consumer code, frontend hooks, `ListManager::getExtraData` keys).
>
> **Audit**: `php artisan mk:status --response-shape` ahora detecta `data.data` y reporta como `error` (no warning). Non-ignorable post-GA.
>
> **v1.7.1-rc1 (post-fase 12 RETO feedback, 2026-06-28)** — 3 fixes pineados en este release:
>
> - **PKG-NEW-17 (HIGH) — Scaffolder `MakeAuthUserCommand` ya NO emite los placeholders `{{moduleNameLower}}` / `{{moduleNamePluralLower}}` literales en strings PHP dinámicos** (register + verify-email routes). Causa raíz: `generateStub()` solo aplica `str_replace` a los stubs, NO a los replacement values. Runtime symptom pre-fix: HTTP 500 `Auth guard [{{moduleNameLower}}] is not defined.` en `POST /api/{scope}/auth/register`. Fix: PHP interpolation `{$scopeLower}` / `{$scopePlural}` en todos los strings dinámicos + cambio de `<<<'PHP'` (NOWDOC) a `<<<"PHP"` (heredoc con interpolation) en el array de verify replacements. Solution of root: bug class completo pineado (incluye verify routes, no solo el register reportado por RETO). Consumer ya NO necesita el workaround `sed` (R-AD-020).
> - **PKG-NEW-18 (MEDIUM) — `BaseController::extractPaginationMetadata()` ahora incluye `has_more_pages` (boolean) para `LengthAwarePaginator`**. Antes solo emitía 4 keys (`current_page, last_page, per_page, total`). `ListManager::getExtraData()` ya emitía las 5 — drift entre 2 helpers del mismo paquete, fixed. `CursorPaginator` NO emite `has_more_pages` (no tiene el método). `@makroz/core` `MkListResponse<T>.__extraData` type YA pineaba `has_more_pages?: boolean` — NO requiere cross-stack update.
> - **BUG-NEW-auto-discover-serve (CRITICAL) — `MK_AUTO_DISCOVER_ABILITIES=true` + `php artisan serve` ya NO bricked dev server**. Fix: skip argv para long-running CLI contexts + `Artisan::call()` en lugar del malformed `$this->app->call(Class, params)`. Consumer ya NO necesita comentar el flag en `.env` (R-AD-021).
>
> Ver [CHANGELOG.md `## [v1.7.1-rc1]`](CHANGELOG.md) para el detalle completo + sprint `makromania/260628-2030--pkg-new-17-18-and-bug-auto-discover-serve` (PR #40 mergeado a dev 2026-06-29).

---

## ⚙️ 2. Configuración (`mk_director.php`)

Después de publicar la configuración (`php artisan vendor:publish --tag=mk-config`), puedes ajustar el comportamiento global:

- **`debug`**: Habilita tiempos de ejecución y análisis de queries (EXPLAIN).
- **`list`**: Configura `default_per_page` y el `max_per_page`.
- **`features.auto_cache`**: Activa el **"Magic Cache"** global, el cual invalida automáticamente tags de caché al detectar escrituras (`INSERT`, `UPDATE`, `DELETE`) en las tablas correspondientes.

---

## ⚡ 3. Creando un Módulo con `SmartController`

La forma más rápida de crear un CRUD completo es extender `SmartController` y declarar la configuración del módulo en `$mkConfig`.

### Ejemplo de Controlador:
```php
namespace App\Modules\Surveys\Controllers;

use Mk\Director\Controllers\SmartController;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Services\SurveyService;
use App\Modules\Surveys\Resources\SurveyResource;

class SurveyController extends SmartController
{
    protected array $mkConfig = [
        'model'      => Survey::class,      // Modelo Eloquent
        'service'    => SurveyService::class, // (Opcional) Lógica de negocio (hooks)
        'resource'   => SurveyResource::class,// (Opcional) API Resource para transformar data
        'searchable' => ['title', 'description'], // Campos habilitados para búsqueda `q=`
        'with'       => ['category'],        // Eager loading fijo
        'features'   => [
            'auto_cache'       => true,      // Sobrescribe el global para este módulo
            'pagination_type'  => 'cursor',  // Options: length_aware, cursor
        ],
    ];
}
```

> 💡 **Cómo funciona**: `SmartController` ya incluye el trait `CRUDSmart`, que lee `$mkConfig` y ejecuta el CRUD completo. Los plugins (`MkAuditLoggerPlugin`, `MkMultiTenantPlugin`) detectan automáticamente los `SmartController` y hookan el ciclo de vida (audit log, multi-tenancy) sin código extra. El scaffolder `mk:module` genera exactamente este esqueleto.

### 3.1 Parámetros Disponibles en `$mkConfig`

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| **`model`** | `string` | **Requerido**. Nombre de clase FQCN del modelo Eloquent. |
| **`service`** | `string` | **Opcional**. Nombre de clase FQCN del servicio que implementa `MkModuleServiceInterface` para interceptar eventos. |
| **`resource`** | `string` | **Opcional**. Nombre de clase FQCN del API Resource de Laravel para dar formato a las respuestas. |
| **`dto`** | `string` | **Opcional**. DTO para validación estricta del payload de entrada. |
| **`searchable`** | `array` | **Opcional**. Lista de columnas de la tabla del modelo en las cuales buscar mediante el parámetro `q=`. |
| **`with`** | `array` | **Opcional**. Relaciones Eloquent fijas a cargar mediante Eager Loading. |
| **`withCount`** | `array` | **Opcional**. Contadores de relaciones Eloquent fijos a cargar mediante `withCount()`. |
| **`allowedIncludes`** | `array` | **Opcional**. Relaciones que el frontend puede solicitar dinámicamente mediante `include=rel1,rel2`. |
| **`allowedWithCount`** | `array` | **Opcional**. Contadores de relación que el frontend puede solicitar dinámicamente mediante `with_count=rel1`. |
| **`enumMap`** | `array` | **Opcional**. Mapa de `[campo => EnumClass]` para validar y castear automáticamente valores a Enums de PHP 8.1+. |
| **`plugins`** | `array` | **Opcional**. Lista de plugins locales para interceptar el ciclo del controlador. |
| **`cache_ttl`** | `int` | **Opcional**. Tiempo de vida del caché del controlador (segundos). Sobrescribe el valor global. |
| **`cache_tags`** | `array\|string` | **Opcional**. Tags de caché a usar. Por defecto es el nombre de la tabla del modelo. |
| **`features`** | `array` | **Opcional**. Toggles de features locales: `auto_cache` (bool), `pagination_type` (`'length_aware'` o `'cursor'`). |

---

### 3.5 Scaffolding modules with RBAC (`--with-rbac`)

A partir de **v1.5.0**, el comando `mk:module` acepta el flag `--with-rbac`
que genera un trío RBAC completo (User + Role + Ability + 2 pivots + 3
Policies + RbacService + ServiceProvider con Gate bindings) en un solo paso.

#### Uso

```bash
php artisan mk:module Admin --with-rbac
```

Genera **20 archivos** en `app/Modules/Admin/`:

| Carpeta | Archivos | Propósito |
|---|---|---|
| `Models/` | `Admin.php`, `Role.php`, `Ability.php` | Eloquent models del scope RBAC |
| `Http/Controllers/` | `AdminController.php`, `RoleController.php`, `AbilityController.php` | CRUD + acciones custom (`assignRole`, `syncAbilities`) |
| `Policies/` | `AdminPolicy.php`, `RolePolicy.php`, `AbilityPolicy.php` | Default-deny + `before()` super-admin bypass |
| `Services/` | `RbacService.php` | Helper: `assignRole`, `syncAbilities`, `userHasAbility` |
| `Database/Migrations/` | 5 archivos con timestamps secuenciales | 3 entity tables + 2 pivots con FK + `cascadeOnDelete` |
| `Routes/` | `api.php` | CRUD endpoints para los 3 controllers |
| `Contracts/`, `DTOs/`, `Repositories/` | (reusados del módulo estándar) | DTO, Repository Interface + Implementation |
| (raíz del módulo) | `AdminModuleServiceProvider.php` | `Gate::policy()` + `Gate::define()` auto-bind, `RbacService` singleton |

#### Convenciones

- **Naming de tablas**: scope-prefixed — `admin_users`, `admin_roles`,
  `admin_abilities`, `admin_role_user`, `admin_ability_role` (decisión D3:
  evita colisión de pivots con otros módulos).
- **FK constraints**: ambos lados de los pivots tienen
  `constrained('admin_X')->cascadeOnDelete()` (R-RISK-001, hardening
  R3-014 — el bug histórico de `role_user` sin FK).
- **User model**: extiende `Illuminate\Foundation\Auth\User` (NO
  `Mk\Director\Auth\Models\AuthUser` — decisión D2). Para agregar
  login al módulo, ejecutá `mk:make:auth-user Admin` **por separado**
  (R-PKG-009 cubre el flag `--login-field`).
- **Default-deny**: cada Policy usa `$user->hasAbility('admin.admins.X')`
  en todos los métodos. `before()` retorna `true` para users con role
  `super-admin`, `null` para que el chain normal de abilities corra
  (RBAC-004).
- **Ability names**: `{scope}.{resource}.{action}` — `admin.admins.view`,
  `admin.roles.syncAbilities`, `admin.abilities.viewAny`, etc. Total: 15
  abilities explícitas en `discoverAbilities()` (D5 — fuente de verdad
  para `mk:discover-abilities` en R-PKG-007).

#### End-to-end example

```bash
$ php artisan mk:module Admin --with-rbac
🚀 Iniciando generación del módulo MK-API: Admin
  📁 Controllers/, Contracts/, DTOs/, Enums/, Models/, ...
  📄 Generando archivos...
   ✅ Models/Admin.php
   ✅ Models/Role.php
   ✅ Models/Ability.php
   ✅ Http/Controllers/AdminController.php
   ... (16 archivos más)
   ✅ Database/Migrations/2026_06_24_153022_create_admin_users_table.php
   ... (4 migrations más)
 - Auto-registrado en bootstrap/providers.php

✅ Módulo Admin con RBAC triad generado:
   • 3 Models    (User, Role, Ability) — tablas `admin_users`, `admin_roles`, `admin_abilities`
   • 3 Controllers (CRUD + assignRole/revokeRole/syncAbilities)
   • 3 Policies  (AdminPolicy, RolePolicy, AbilityPolicy) — default-deny + super-admin bypass
   • 1 Service   (RbacService — singleton)
   • 5 Migrations con FK constraints
   • 1 ServiceProvider con Gate::policy + Gate::define auto-bind

   ⚠️  Próximos pasos:
      1. php artisan migrate (corre las 5 migrations en orden)
      2. mk:discover-abilities --module=Admin (crea las abilities en la tabla)
      3. mk:auth:create-super-admin (bootstrap inicial)
```

#### Cuándo usar `--with-rbac`

| Situación | Recomendación |
|---|---|
| Necesitás roles + abilities para un bounded context (ej: Admin, Member, Vendor) | `mk:module X --with-rbac` |
| Necesitás un scope de login independiente del RBAC del módulo | `mk:make:auth-user X` (R-PKG-009) **adicional** a `--with-rbac` |
| Solo necesitás CRUD simple, sin RBAC | `mk:module X` (estándar, sin flag) |
| Tenés un módulo RBAC custom pre-1.4.0 (ej: rama huérfana de RETO) | Sprint de retrofit: borrar custom, re-generar con `--with-rbac`, portar lógica de negocio sobre los stubs |

#### Composición con otros flags

- `--with-rbac` es **ortogonal** a `--login-field` (R-PKG-009):
  el primero genera RBAC triad, el segundo agrega login al scope.
  Corren en comandos separados (`mk:module X --with-rbac` +
  `mk:make:auth-user X --login-field=ci`).
- `--with-rbac` es **ortogonal** a `--profile-fields` (futuro): el
  primero genera los stubs base, el segundo agrega columnas custom al
  User model (ej: `phone`, `avatar`).

#### ¿Por qué per-module isolation (D1) en vez de reuse central?

La alternativa — reusar las tablas `roles`/`abilities` del package
central con un `guard='admin'` — acoplaría el RBAC del módulo a las
tablas de AuthUser. Esto viola el espíritu de **R-MK-001 (MME)** que
exige bounded contexts aislados. Además, hace que el FK integrity del
pivot sea imposible: si 2 módulos comparten `role_user`, no podés FK
a `{scope}_users.id`. Ver `design.md` § "Decision: D1" para el
análisis completo.

#### Spec & tests

- **Spec**: `RBAC-001..005` en
  `openspec/changes/2026-06-24-admin-with-rbac/specs/admin-with-rbac.md`
- **Tests**: 15 Pest tests en `tests/Feature/MkModuleWithRbacTest.php`
  (157 assertions). Cubren: scaffolding genera 20 archivos, FK
  constraints con `cascadeOnDelete` en pivots, `Gate::policy` auto-bind,
  15 abilities via `discoverAbilities()`, default-deny via
  `hasAbility()` en CRUD, `before()` super-admin bypass, User
  extiende `Authenticatable` NO `AuthUser`, end-to-end tempdir.

---

### 3.6 Auto-poblar abilities con `mk:discover-abilities` (R-PKG-007)

A partir de **v1.5.0-rc2**, `mk:discover-abilities` lee las abilities
del provider del módulo (preferred) y las persiste via UPSERT idempotente.
Es el segundo paso del workflow de scaffolding RBAC (después de `php artisan migrate`).

> **R-PKG-021 BUG-NEW-29 (HIGH, v1.6.0-rc10)**: la tabla destino del UPSERT ahora es **schema-aware**. Si `{scope}_abilities` per-scope existe (caso `mk:module X --with-rbac`), UPSERT ahí. Si NO existe, UPSERT en `abilities` global (caso `mk:make:auth-user X --with-crud`). Antes de rc10, el comando SIEMPRE escribía en `{scope}_abilities` y fallaba con `relation "{scope}_abilities" does not exist` para consumers `--with-crud`. Si NINGUNA tabla existe, lanza `RuntimeException` con mensaje accionable (`php artisan migrate` después de scaffoldear?).

#### Source-of-truth: hybrid (D1)

| Provider implementa `discoverAbilities()` | ¿Qué pasa? |
|---|---|
| **Sí** | Solo se usan las abilities del provider. Atributos PHP y docblocks se IGNORAN. |
| **No** | Fallback combinado: atributos PHP 8.4 (`#[\Mk\Director\Auth\Attributes\Ability]`) + docblock (`@mk-ability name|description`). |

Esto evita drift entre abilities en `Gate::define()` (provider) y filas
en `{scope}_abilities` (DB). El provider es la única fuente por módulo.

#### Atributo PHP 8.4 (primary dentro del fallback)

```php
use Mk\Director\Auth\Attributes\Ability;

class InvoiceController
{
    #[Ability('billing.invoices.list', 'Listar facturas')]
    public function index() {}

    #[Ability('billing.invoices.create')]
    public function store() {}
}
```

Atributo es repeatable (varios `#[Ability(...)]` apilados en un método).
Constructor property promotion requiere PHP 8.4+; para apps pre-8.4 usar
el docblock fallback.

#### Docblock fallback

```php
/**
 * Reembolsar una factura.
 *
 * @mk-ability billing.invoices.refund Reembolsar factura
 */
public function refund() {}
```

El prefijo `mk-` evita colisión con otros generadores de docs (ApiGen,
phpDocumentor). Regex escapada correctamente para PHP 8.5+ PCRE2.

#### Uso

```bash
# Default (interactive prompt: "¿Escribir a {scope}_abilities? [y/N]").
php artisan mk:discover-abilities --module=admin

# Skip prompt + escribir.
php artisan mk:discover-abilities --module=admin --force

# Preview sin escribir (también skip prompt).
php artisan mk:discover-abilities --module=admin --dry-run

# CI-friendly: --force + JSON output.
php artisan mk:discover-abilities --module=admin --force --json

# Todos los módulos (no --module).
php artisan mk:discover-abilities --force
```

#### Write intent (D3 — interactive con escape hatch CI)

| Flags | TTY | Result |
|---|---|---|
| `--dry-run` | * | No writes. Imprime preview. |
| `--force` | * | Writes. No prompt. |
| Sin flags | TTY | `$this->confirm(..., false)` — default **No**. |
| Sin flags | no-TTY (CI) | Laravel `--no-interaction` → confirm retorna false → safe no-op. |
| `--dry-run` + `--force` | * | Error: "No combines --dry-run y --force". |

#### Auto-register en boot (D4)

Setear `mk_director.features.auto_discover_abilities = true` (o env
`MK_AUTO_DISCOVER_ABILITIES=true`) corre `mk:discover-abilities --force
--json` automáticamente después del boot del kernel (solo consola).
Útil en sandbox/dev. **Off por default** — recomendado apagado en prod.

> **v1.7.1+ (BUG-NEW-auto-discover-serve fix)**: el boot hook ahora **skip**
> cuando `$_SERVER['argv']` incluye long-running CLI contexts
> (`serve`, `octane:start`, `octane:reload`, `horizon`, `horizon:supervisor`,
> `queue:work`, `queue:listen`, `schedule:work`, `schedule:run`). Antes
> v1.7.1, pinear el flag con `php artisan serve` brickeaba el primer
> request con HTTP 500 `Call to undefined function DiscoverAbilitiesCommand()`.
> Post-v1.7.1, el flag es **seguro** para sandbox/dev con `artisan serve`.
> El boot usa `Artisan::call('mk:discover-abilities', [...])` (no el
> malformed `$this->app->call(Class, params)` de v1.7.0).

#### Scope detection (D6)

El scope se deriva del nombre del módulo: `Str::snake(Str::plural($name))`.
Ejemplos:
- `Admin` → tabla `admin_abilities`
- `Member` → tabla `member_abilities`
- `Billing` → tabla `billing_abilities`

#### Spec & tests

- **Spec / Design**: `openspec/changes/2026-06-24-discover-abilities-to-core/`
- **Tests**: 21 Pest tests en `tests/Feature/DiscoverAbilitiesCommandTest.php`
  (104 assertions acumuladas incluyendo los 4 nuevos de R-PKG-019 OBS-NEW-02).
  Cubren: signature con 4 flags, hybrid D1 (provider OR fallback, never
  both), interactive prompt D3, UPSERT idempotente, scope detection,
  `#[Ability]` attribute TARGET_METHOD + IS_REPEATABLE, PHP 8.5 PCRE2 regex
  sin escape de llaves, end-to-end con tempdir + SQLite in-memory (5 escenarios),
  R-PKG-018 OBS-NEW-01 (4 tests del path `discoverAbilitiesFromMkConfig`),
  R-PKG-019 OBS-NEW-02 (2 tests del path `require_once` para force-load
  classes no autoloaded).

#### Force-require de classes no autoloaded (R-PKG-019 OBS-NEW-02)

Desde **v1.6.0-rc9**, `discoverClassesInDir()` hace `require_once` de cada
archivo PHP antes de iterar `get_declared_classes()`. Esto fuerza la
declaración de la clase sin depender del autoload trigger.

**Por qué**: en contexto artisan CLI (comando ejecutándose sin pasar por
`route:list` o el bootstrap completo del framework), las controllers
scaffoldeadas típicamente NO están loaded. `get_declared_classes()` solo
retorna clases ya cargadas en memoria, así que sin el force-require el
command perdía las 3 controllers scaffoldeadas (solo veía el ServiceProvider).

**Side-effects del `require_once`**: en proyectos Laravel siguiendo la
convención PSR-4 (cada archivo = una clase, sin código top-level), el
`require_once` es seguro. Si tu consumer tiene archivos PHP con código
top-level (helpers, registro de side-effects), esos side-effects ocurrirán
al ejecutar `mk:discover-abilities`. Trade-off explícito: la alternativa
(parsear namespace via regex sin ejecutar el archivo) requiere conocer
el root namespace y rechazar clases con namespaces mixtos.

**Namespace prefix configurable**: el matching de clases discovered usa
`App\Modules` como prefijo default (regla R-MK-001 — módulos bounded context
viven bajo `app/Modules/`). El método `classesNamespacePrefix()` es
overridable para tests o consumers que usen otro namespace root:

```php
class CustomDiscoverAbilitiesCommand extends DiscoverAbilitiesCommand
{
    protected function classesNamespacePrefix(): ?string
    {
        return 'Acme\\Modules'; // o `null` para skip prefix check
    }
}
```

---

## ⚙️ 3.7. Login field configurable (`--login-field=<campo>`)

`mk:make:auth-user` ahora soporta campos de login no-email. Útil para:

| País/vertical | Campo | Ejemplo |
|---|---|---|
| Bolivia | `ci` | Cédula de identidad (RETO Bolivia) |
| Genérico | `phone` | Teléfono como login |
| Genérico | `username` | Username en vez de email |
| Genérico | `documento` | Número de documento |

### Uso

```bash
# Default (BC): email — comportamiento idéntico a v1.4.0
php artisan mk:make:auth-user Admin

# Campo custom
php artisan mk:make:auth-user Admin --login-field=ci
php artisan mk:make:auth-user Member --login-field=phone
```

### Qué cambia cuando pasás `--login-field=ci`

1. **Model stub** (`app/Modules/{Scope}/Models/{Scope}.php`):
   ```php
   protected string $loginField = 'ci';

   protected $fillable = [
       'name',
       'ci',                     // ← en lugar de 'email'
       'password',
       'auth_scope',
       'client_id',
   ];

   protected $casts = [           // ← sin 'email_verified_at'
       'password' => 'hashed',
   ];
   ```

2. **Migration stub** (`..._create_{scope}s_table.php`):
   ```php
   $table->string('ci')->unique();        // ← en lugar de 'email'
   // (NO se genera email_verified_at)
   ```

3. **AuthController stub**: validación `['required', 'string']`
   (en lugar de `['required', 'email']`) y lookup `where('ci', ...)`
   (en lugar de `where('email', ...)`).

### Config global

`mk_director.auth.login_field` (env `MK_LOGIN_FIELD`, default `email`):

```php
// config/mk_director.php
'auth' => [
    'login_field' => env('MK_LOGIN_FIELD', 'email'),
],
```

### Constraints (R-PKG-009 D1-D6)

- **D1**: Solo string fields. NO int, json, composite.
- **D2**: Default `email` (BC). Sin flag, idéntico a v1.4.0.
- **D3**: Columna DB = nombre del campo (`ci`, NO `login_field`).
- **D4**: Validación mínima. Consumer customiza vía `LoginRequest` override.
- **D5**: `MustVerifyEmail` interface solo se importa cuando loginField=email.
- **D6**: `AuthUser::scopeWhereLoginField($value)` para queries dinámicas.

### Queries dinámicas agnósticas

```php
// Default email: WHERE email = ?
Admin::query()->whereLoginField('admin@example.com')->first();

// Override ci: WHERE ci = ?
class AdminReto extends AuthUser {
    protected string $loginField = 'ci';
}
AdminReto::query()->whereLoginField('1234567')->first();
```

### Spec

- Spec: `openspec/changes/2026-06-24-auth-user-login-field/proposal.md`
- Design: `openspec/changes/2026-06-24-auth-user-login-field/design.md`

---

## ⚙️ 3.8. RBAC integration en AuthController (`--with-auth-rbac`)

`mk:make:auth-user` ahora puede generar un `AuthController` con integración
RBAC completa: ability checks en endpoints privados, rate-limit en endpoints
públicos, y audit log automático vía eventos. Default (sin flag) preserva
el comportamiento idéntico a v1.5.0-rc3.

### Uso

```bash
# Default (BC): sin RBAC, sin rate limit, sin audit log — idéntico a v1.5.0-rc3
php artisan mk:make:auth-user Admin

# Habilitar RBAC + rate limit + audit log
php artisan mk:make:auth-user Admin --with-auth-rbac

# Combinar con --login-field (R-PKG-009)
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac
```

### Qué cambia cuando pasás `--with-auth-rbac`

1. **Ability checks en `/me` y `/logout`** vía `authorizeAbility()` helper:
   ```php
   public function me(Request $request): JsonResponse
   {
       $this->authorizeAbility('me', $request->user());
       return $this->sendResponse($request->user());
   }

   public function logout(Request $request): JsonResponse
   {
       $this->authorizeAbility('logout', $user);
       // ...
   }

   protected function authorizeAbility(string $endpoint, mixed $user): void
   {
       $ability = config("mk_director.auth.abilities.{$endpoint}");
       if ($ability === null || $ability === '') {
           return; // BC mode: sin check.
       }
       if ($user === null || ! $this->abilityResolver->can($user, $ability)) {
           throw new AuthorizationException("Missing ability: {$ability}");
       }
   }
   ```

2. **Rate limit middleware** en endpoints públicos (vía `routes/api.php`):
   ```php
   Route::post('login', [AuthController::class, 'login'])
       ->middleware('throttle:' . config('mk_director.auth.rate_limits.login', '5,1'));
   Route::post('forgot', [AuthController::class, 'forgot'])
       ->middleware('throttle:' . config('mk_director.auth.rate_limits.forgot', '3,1'));
   Route::post('reset', [AuthController::class, 'reset'])
       ->middleware('throttle:' . config('mk_director.auth.rate_limits.reset', '3,1'));
   ```

3. **Audit events** vía `Mk\Director\Auth\Events\AuthEvent`:
   ```php
   // Login exitoso:
   AuthEvent::dispatch('auth.login.success', [
       'user_id' => $user->id,
       'ip' => $request->ip(),
       'user_agent' => $request->userAgent(),
       'scope' => $user->getAuthScope(),
   ]);

   // Login fallido:
   AuthEvent::dispatch('auth.login.failed', [
       'login_field_value' => $credentials['email'] ?? null,  // NUNCA password
       'ip' => $request->ip(),
       'user_agent' => $request->userAgent(),
   ]);

   // Logout, password_reset.requested también se emiten automáticamente.
   ```

   Consumido por `MkAuditLoggerPlugin` si está activo.

### Config global

```php
// config/mk_director.php
'auth' => [
    // ... login_field, user_model, default_user_type ...
    'abilities' => [
        'me' => env('MK_AUTH_ABILITY_ME'),          // null = sin check (BC)
        'logout' => env('MK_AUTH_ABILITY_LOGOUT'),  // null = sin check (BC)
    ],
    'rate_limits' => [
        'login' => env('MK_AUTH_RATE_LIMIT_LOGIN', '5,1'),
        'forgot' => env('MK_AUTH_RATE_LIMIT_FORGOT', '3,1'),
        'reset' => env('MK_AUTH_RATE_LIMIT_RESET', '3,1'),
    ],
    // v1.6.0-rc4 (R-PKG-014 BUG-07 fix): rotación de refresh tokens.
    'refresh' => [
        'rotate_on_refresh' => env('MK_AUTH_REFRESH_ROTATE', false),
    ],
],
```

### Ability naming convention for `--with-auth-rbac`

**v1.6.0-rc4 (R-PKG-014 MEJORA-05)**. Convención recomendada:

```php
'abilities' => [
    'me' => 'auth.{scope}.me',
    'logout' => 'auth.{scope}.logout',
],
```

Donde `{scope}` es el nombre del scope en snake_case (`admin`, `member`, `partner`, etc.).

**Por qué `auth.{scope}.{endpoint}`**:
- `auth.*` agrupa abilities del flow de autenticación (no de la lógica de negocio).
- `{scope}` previene colisiones entre scopes (`auth.admin.me` vs `auth.member.me`).
- `{endpoint}` es el verbo del endpoint (`me`, `logout`).

**Discovery automático**: después de generar el scope con `--with-auth-rbac`, el scaffolder corre `mk:discover-abilities` automáticamente (MEJORA-03). Si querés registrar estas abilities manualmente:

```bash
php artisan tinker --execute='\Mk\Director\Auth\Events\AuthEvent::dispatch("auth.ability.register", ["name" => "auth.admin.me", "description" => "View own admin profile"]);'
```

**Wildcard para super-admin**: el role `super-admin` que crea `mk:auth:create-super-admin --roles=super-admin,admin,editor,viewer` recibe automáticamente la ability wildcard `*` que matchea cualquier otra ability (incluyendo `auth.*`).

### Cómo registrar un listener para `AuthEvent`

```php
// app/Listeners/AuthAuditListener.php
namespace App\Listeners;

use Mk\Director\Auth\Events\AuthEvent;
use Illuminate\Support\Facades\Log;

class AuthAuditListener
{
    public function handle(AuthEvent $event): void
    {
        Log::channel('audit')->info("[{$event->type}]", $event->payload);
    }
}

// app/Providers/EventServiceProvider.php
protected $listen = [
    AuthEvent::class => [AuthAuditListener::class],
];
```

### Anti-patterns (rejected)

- **Habilitar RBAC por default**: rompe BC. El flag es opt-in.
- **Loggear passwords** (ni hasheados) en audit events — **NUNCA**.
- **Rate limit muy agresivo** (5/min puede bloquear usuarios reales) —
  configurable por endpoint via `MK_AUTH_RATE_LIMIT_*`.

#### 3.8.1. `AuthUser::safeLogoutCurrentToken()` — logout null-safe (R-PKG-027, rc14)

Desde rc14, el modelo base `AuthUser` expone un helper para hacer logout sin riesgo de null-dereference:

```php
$user = $request->user();
$revoked = $user->safeLogoutCurrentToken();
// $revoked === true  → había un token bearer que se revocó
// $revoked === false → no había token (cookie-based auth stateful SPA, o token ya revocado)
```

**Por qué existe**: el patrón naive `$token = $user->currentAccessToken(); $token->delete();` revienta con `Call to a member function delete() on null` cuando `currentAccessToken()` retorna `null`. Esto pasa en:

- **Sanctum stateful SPA** (auth via cookies httpOnly, no bearer token) — `currentAccessToken()` retorna `null`.
- **Logout idempotente** — segunda llamada después de que el primer logout ya revocó el token.
- **Token ya revocado por otra request** concurrente.

El `AuthController::logout()` scaffoldeado usa este helper desde rc14. Si override `logout()` manualmente en tu consumer, **usá siempre el helper en lugar del patrón naive**.

```php
// ✅ Correcto (rc14+)
public function logout(Request $request): JsonResponse
{
    $user = $request->user();
    $user->safeLogoutCurrentToken();
    return $this->sendResponse(true, 'Sesión cerrada.');
}

// ❌ Naive (rompe con cookie-based auth)
public function logout(Request $request): JsonResponse
{
    $user = $request->user();
    $token = $user->currentAccessToken();
    $token->delete(); // FatalError si currentAccessToken() es null
    return $this->sendResponse(true, 'Sesión cerrada.');
}
```

#### 3.8.2. `is_active` check en el flow de auth (R-PKG-027, rc14)

Desde rc14, el `AuthController` scaffoldeado consulta `is_active` por default en `login`/`forgot`/`reset` cuando la columna existe en la tabla del scope:

```php
// login() — bloquea usuarios inactivos
$isActiveCheck = Schema::hasColumn($user?->getTable() ?? '{{moduleNamePluralLower}}', 'is_active')
    && $user->is_active === false;

if (! $user
    || ! Hash::check($credentials['password'], $user->password)
    || $user->getAuthScope() !== '{{moduleNameLower}}'
    || $isActiveCheck
) {
    return $this->sendError('Credenciales inválidas.', [...], 422);
}
```

**Semántica**:

- `is_active = true` → puede loguearse / recibir reset / resetear password.
- `is_active = false` → 401 (login bloqueado, sin email de reset, reset denegado).
- `is_active = null` → permitido (compat con datos preexistentes sin la columna).

El check usa `Schema::hasColumn()` (cacheado en memoria), así que es zero-cost en runtime para consumers que NO usan la columna.

**Si override `login()`/`forgot()`/`reset()` manualmente** en tu consumer, mantené la misma lógica de `Schema::hasColumn` + `=== false` para no romper el patrón.

### Spec

- Spec: `openspec/changes/2026-06-24-auth-controller-rbac-stub/proposal.md`
- Spec formal: `openspec/changes/2026-06-24-auth-controller-rbac-stub/specs/auth-controller-rbac-stub.md`
- **rc14 update** (R-PKG-027): `safeLogoutCurrentToken()` helper (3.8.1) + `is_active` check default (3.8.2). Spec en `packagist/mk-director-laravel/CHANGELOG.md` § R-PKG-027.

### 3.9 Profile fields per-scope (`--profile-fields=<csv>`) (R-PKG-011)

Cada scope autenticable (`Admin`, `Member`, `Customer`) puede declarar sus
propias columnas de perfil via `--profile-fields=<csv>`. Las columnas viven
**solo en la tabla del scope** (encapsulación MME/R-MK-001) — no se
comparten entre scopes.

#### Uso

```bash
# Admin scope: name + dni + phone
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# Member scope: name + phone + birthdate (independiente del Admin)
php artisan mk:make:auth-user Member --profile-fields=name,phone,birthdate
```

#### Qué cambia cuando pasás `--profile-fields`

1. **Migración** (`{timestamp}_create_admins_table.php`):
   - Columnas `string` nullable para cada field (`dni`, `phone`).
   - Nullable para que la migration corra sobre tablas con data existente.
   - Si necesitás `unique()` o tipos custom, override la migration post-generación.

2. **Modelo** (`app/Modules/Admin/Models/Admin.php`):
   - `$fillable` incluye cada field después del `loginField`.
   - Docblock con `@property string|null $dni` (autocomplete en IDEs).
   - Default type: `string`. Sin cast explícito (Laravel auto-castea).

3. **AuthController** (`app/Modules/Admin/Http/Controllers/AuthController.php`):
   - **NUEVO** método `register(Request $request)`: valida `required|string|max:255`
     para cada field, crea el `Admin`, setea `authScope`, opcionalmente dispatch
     de `VerifyEmail` notification (si `--verify-email` activo).
   - **NUEVO** método `updateProfile(Request $request)`: valida + actualiza
     profile fields del user autenticado. Llamado vía `PATCH /api/admin/auth/me`.

4. **Routes** (`app/Modules/Admin/Http/Routes/api.php`):
   - **NUEVO** `Route::post('register', ...)`: crea user.
   - **NUEVO** `Route::patch('me', ...)`: actualiza profile fields (en grupo protegido).

5. **Endpoints expuestos**:
   - `GET /api/admin/auth/me` — read (incluye profile fields via `$fillable`).
   - `PATCH /api/admin/auth/me` — update con validación default.
   - `POST /api/admin/auth/register` — create con profile fields.

#### Encapsulación (MME/R-MK-001)

Cada scope tiene su propia tabla. No hay leak cross-scope:

```bash
# Genera tabla `admins` con columnas: id, name, ci, dni, phone, password, auth_scope, ...
# NO incluye `birthdate` (eso es de Member)
php artisan mk:make:auth-user Admin --login-field=ci --profile-fields=name,dni,phone

# Genera tabla `members` con columnas: id, name, phone, birthdate, password, auth_scope, ...
# NO incluye `dni` (eso es de Admin)
php artisan mk:make:auth-user Member --login-field=email --profile-fields=name,phone,birthdate
```

`Admin::$fillable = ['name', 'ci', 'dni', 'phone', 'password', 'auth_scope', 'client_id']`
`Member::$fillable = ['name', 'email', 'phone', 'birthdate', 'password', 'auth_scope', 'client_id']`

#### Custom validation

Para validación custom (regex CI, date format, etc.), override los métodos
`register()` y `updateProfile()` en el AuthController generado:

```php
// app/Modules/Admin/Http/Controllers/AuthController.php
public function register(Request $request): JsonResponse
{
    $data = $request->validate([
        'ci' => ['required', 'string', 'regex:/^[0-9]{6,8}$/'],
        'phone' => ['required', 'string', 'regex:/^\+591[0-9]{8}$/'], // Bolivia
        // ... profile fields restantes
    ]);
    // ... resto del método
}
```

#### Constraints (R-PKG-011 ADR-007 + ADR-008)

- Cada field debe ser PHP identifier válido (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`).
- No duplicados dentro del CSV (fail-fast).
- No colisión con columnas reservadas: `id`, `password`, `auth_scope`, `client_id`,
  `remember_token`, `created_at`, `updated_at`, `email_verified_at`, ni con el
  `--login-field`.
- Tipos: en v1.5.0-rc5 todos los profile fields son `string`. Para tipos custom
  (`date`, `int`, `json`, `file`), usar v1.6.0 con `--profile-fields-types`.

### 3.10 Email verification opt-in (`--verify-email`) (R-PKG-011)

Habilita el flujo completo de verificación por email. Default: sin
verificación (BC con v1.5.0-rc4).

#### Uso

```bash
# Solo funciona si --login-field=email (default)
php artisan mk:make:auth-user Admin --verify-email

# Combo completo (RETO Bolivia)
php artisan mk:make:auth-user Admin --login-field=ci --with-auth-rbac --profile-fields=name,dni,phone --verify-email
```

⚠️ `--verify-email` se ignora si `--login-field != email`. El scaffolder
imprime warning explícito. ADR-009.

#### Qué cambia cuando pasás `--verify-email`

1. **Migración**: columna `email_verified_at` (timestamp nullable).
2. **Modelo**: cast `'email_verified_at' => 'datetime'` en `$casts`.
3. **AuthController**: métodos `verifyEmail($id, $hash)` + `resendVerification()`.
4. **Routes**:
   - `GET /api/admin/auth/email/verify/{id}/{hash}` (signed URL, marca verificado).
   - `POST /api/admin/auth/email/resend` (throttle 6,1, auth:admin required).
5. **Register dispatch**: `register()` envía `Illuminate\Auth\Notifications\VerifyEmail`
   queueable al crear el user.

#### Flujo de verificación

```
1. POST /api/admin/auth/register { name, email, password, dni, phone }
   → 201 + VerifyEmail notification dispatched
2. User hace click en el email link
   → GET /api/admin/auth/email/verify/{id}/{hash}  (signed URL)
   → 200 + email_verified_at = now()
3. (Opcional) Re-enviar si no llegó:
   → POST /api/admin/auth/email/resend
   → throttle 6,1 por user
```

#### Custom notification template

Para customizar el template del email, override la notification:

```php
// app/Notifications/CustomVerifyEmail.php
namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Support\Facades\URL;

class CustomVerifyEmail extends BaseVerifyEmail
{
    protected function buildMailMessage($url)
    {
        return (new \Illuminate\Mail\Message)->view('emails.verify', ['url' => $url]);
    }
}

// app/Models/Admin.php
use App\Notifications\CustomVerifyEmail;

public function sendEmailVerificationNotification()
{
    $this->notify(new CustomVerifyEmail);
}
```

#### Constraints

- `--verify-email` solo aplica si `--login-field=email`. Si pasás ambos
  flags con login-field != email, se ignora `--verify-email` con warning.
- Verification routes (`/email/verify/{id}/{hash}`) usan `signed` middleware
  (Laravel built-in, previene tampering del hash).
- Resend route tiene throttle `6,1` por defecto (configurable en routes stub).

#### Spec

- Spec: `openspec/changes/2026-06-25-profile-fields-per-scope/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-per-scope/specs/profile-fields.md`

---

### 3.11 Profile fields con tipos custom (`--profile-fields=key:type,...`) (R-PKG-012)

Extensión backward-compatible de `--profile-fields` (R-PKG-011) que permite
declarar el tipo de cada profile field. En v1.5.0-rc5 todos los profile
fields eran `string`; en v1.6.0-rc1 hay 8 tipos soportados.

#### Sintaxis

```bash
# v1.5.0-rc5 (BC): todos string
php artisan mk:make:auth-user Admin --profile-fields=name,dni,phone

# v1.6.0-rc1: tipos custom con key:type
php artisan mk:make:auth-user Admin \
  --profile-fields=name:string,birthdate:date,age:int,biography:text,active:bool

# Mixed: default string cuando no se especifica tipo
php artisan mk:make:auth-user Admin --profile-fields=name,age:int,active:bool
```

#### Tabla de tipos (8 tipos, lista cerrada)

| Tipo | Migration column | Model cast | Validation rule |
|---|---|---|---|
| `string` (default BC) | `string` | (sin cast) | `['required', 'string', 'max:255']` |
| `text` | `text` | (sin cast) | `['required', 'string']` |
| `int` | `integer` | `'integer'` | `['required', 'integer']` |
| `decimal` | `decimal(8,2)` | `'decimal:2'` | `['required', 'numeric']` |
| `bool` | `boolean` | `'boolean'` | `['required', 'boolean']` |
| `date` | `date` | `'date'` | `['required', 'date']` |
| `datetime` | `dateTime` | `'datetime'` | `['required', 'date']` |
| `json` | `json` | `'array'` | `['required', 'array']` |

#### Ejemplo end-to-end

```bash
php artisan mk:make:auth-user Member \
  --login-field=email \
  --with-auth-rbac \
  --profile-fields=name:string,phone:string,birthdate:date,active:bool,registered_at:datetime,metadata:json
```

Output:

```php
// app/Modules/Member/Database/Migrations/YYYY_MM_DD_HHMMSS_create_members_table.php
Schema::create('members', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('email')->unique();
    // ── Profile fields (R-PKG-012 con tipos custom) ──────
    $table->string('phone')->nullable();
    $table->date('birthdate')->nullable();
    $table->boolean('active')->nullable();
    $table->dateTime('registered_at')->nullable();
    $table->json('metadata')->nullable();

    $table->string('password');
    $table->string('auth_scope')->default('member')->index();
    $table->rememberToken();
    $table->timestamps();
});
```

```php
// app/Modules/Member/Models/Member.php — $casts
protected $casts = [
    'birthdate' => 'date',
    'active' => 'boolean',
    'registered_at' => 'datetime',
    'metadata' => 'array',  // Laravel moderno usa 'array' para JSON
    'password' => 'hashed',
];
```

```php
// app/Modules/Member/Http/Controllers/AuthController.php — register()
public function register(Request $request): JsonResponse
{
    $data = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'max:255'],
        'phone' => ['required', 'string', 'max:255'],
        'birthdate' => ['required', 'date'],
        'active' => ['required', 'boolean'],
        'registered_at' => ['required', 'date'],
        'metadata' => ['required', 'array'],
        'password' => ['required', 'string', 'min:8'],
    ]);
    // ...
}
```

#### Ortogonalidad con otros flags

R-PKG-012 es extensión pura de R-PKG-011. No cambia comportamiento de:
`--login-field`, `--with-auth-rbac`, `--verify-email`. Las 16 (2⁴)
combinaciones de los 4 flags + 8 tipos = 32 combinaciones posibles.
Todas válidas (excepto `--verify-email` con `--login-field != email`,
R-PKG-011 ADR-009).

#### Consumer override

Si necesitás validación custom (regex CI, date format estricto, decimal
precision custom, etc.), override `register()` o `updateProfile()` en
el AuthController generado:

```php
// app/Modules/Member/Http/Controllers/AuthController.php
public function register(Request $request): JsonResponse
{
    $data = $request->validate([
        'birthdate' => ['required', 'date_format:Y-m-d'],  // strict format
        'metadata' => ['required', 'array'],
        // ... override el resto según necesidad
    ]);
    // ... resto de la lógica
}
```

#### Constraints

- **Tipos case-sensitive** (lowercase only): `String`, `STRING`, `sTrInG`
  se rechazan. No normalización implícita.
- **Lista cerrada**: tipos no en la tabla (e.g. `varchar`, `enum`, `file`)
  se rechazan fail-fast con error listando los 8 tipos válidos.
- **BC preservada**: `--profile-fields=name,dni` (sin tipos) se interpreta
  como `name:string,dni:string`. Output idéntico a v1.5.0-rc5.
- **`decimal` con precisión default `(8,2)`**: no custom precision en
  v1.6.0-rc1 (post-RC si RETO necesita).
- **`json` cast como `array`** (Laravel moderno): `'metadata' => 'array'`,
  NO `'metadata' => 'json'`. Consumer puede override si quiere string.
- **`date`/`datetime` validation loose**: acepta múltiples formatos
  (`Y-m-d`, `Y-m-d H:i:s`, ISO 8601). Para strict format, override.

#### Out of scope v1.6.0-rc1

- `file` / `avatar`: storage uploads (S3/R2 pluggable) → R-PKG-013+.
- `enum`: Laravel 11+ `Rule::enum()`, requiere análisis MME → R-PKG-014+.
- `decimal` con precisión custom (`decimal:10,4`): post-RC si RETO necesita.
- `uuid` / `ulid`: PKs custom → R-PKG-015+ (ortogonal, separado).
- Tipos array indexados (`int[]`, `string[]`): no es caso de uso común.

#### Spec

- Spec: `openspec/changes/2026-06-25-profile-fields-types/proposal.md`
- Spec formal: `openspec/changes/2026-06-25-profile-fields-types/specs/profile-fields-types.md`
- Design: `openspec/changes/2026-06-25-profile-fields-types/design.md`

---

### 3.12 Actualización interactiva con `mk:update` (R-PKG-013)

`php artisan mk:update` es el command de **auto-actualización del paquete**: detecta la versión instalada vía `InstalledVersions::getPrettyVersion()`, consulta Packagist por versiones superiores, deja al dev elegir interactivamente cuál instalar, corre `composer require`, y luego audita el schema + código del proyecto buscando incompatibilidades con la nueva versión.

#### Uso

```bash
# Default: menú interactivo con todas las versiones superiores
php artisan mk:update

# Simular sin ejecutar composer (útil para CI/dry-run)
php artisan mk:update --dry-run
```

#### Output típico (versión instalada v1.3.1, última publicada v1.6.0-rc2)

```
🚀 Iniciando actualización interactiva de MK-Director...

Tu versión actual es: v1.3.1
Hay 7 versiones disponibles para actualizar (incluyendo pre-releases):

  [0] v1.6.0-rc2 🧪 (pre-release)
  [1] v1.6.0-rc1 🧪 (pre-release)
  [2] v1.5.0
  [3] v1.4.0 ⭐ (última estable)
  [4] v1.3.2
  [5] v1.3.1
  [6] v1.3.0

¿A qué versión querés actualizar? (↑↓ navegá con el teclado, Enter para seleccionar) [0]:
```

El dev navega con flechas ↑↓, presiona Enter, confirma, y `composer require makroz/director-laravel:vX.Y.Z` corre en segundo plano.

#### Diferencia vs versiones previas

| Aspecto | Antes (≤ v1.6.0-rc2) | Ahora (≥ v1.6.0-rc3) |
|---|---|---|
| Versiones mostradas | Solo `vX.Y.Z` (regex `/^v?\d+\.\d+\.\d+$/`) | **Todas** las superiores (RCs, betas, alphas incluidas) |
| Selección | "Última estable" hardcoded | Menú navegable con ↑↓ + Enter |
| Flags extra | Ninguno | Ninguno (no `--include-rc`, no `--channel=stable`) |
| Composer command | `composer update makroz/director-laravel` (constraint del composer.json) | `composer require makroz/director-laravel:vX.Y.Z` (versión exacta) |
| Markers visuales | — | `⭐ (última estable)`, `🧪 (pre-release)` |

#### Bug que arregla

Pre-v1.6.0-rc3, el filtro `/^v?\d+\.\d+\.\d+$/` ocultaba cualquier versión con sufijo. Si estabas en `v1.3.1`, el command decía:

```
Tu versión actual es: v1.3.1 y la última disponible es: v1.4.0
```

…incluso cuando `v1.6.0-rc2` ya estaba en Packagist. Bug detectado por Mario en RETO. Fix: la nueva implementación consulta TODAS las versiones y filtra con `version_compare($versionNorm, $currentNorm, '>')`, que sí respeta semver + sufijos `-rcN`/`-betaN`/`-alphaN`.

#### Pipeline completo post-selección

Una vez elegida la versión:

1. **`composer require makroz/director-laravel:vX.Y.Z`** (Symfony Process, 5min timeout).
2. **Re-chequeo de versión instalada** (advertencia si quedó por debajo de la solicitada → cache de Composer).
3. **`runDatabaseMigrationsPipeline()`** — si el proyecto viene de v1.1 (BIGINT id), confirma backup y migra `auth_users.id` a UUID (CHAR 36). Irreversible.
4. **`php artisan migrate`** — corre migrations estándar de Laravel.
5. **`auditCodebaseRisks()`** — escanea modelos y routes del proyecto:
   - Modelos con `use HasTenantScope` sin `protected static bool $usesTenant` (opt-in en v1.2+, hay que declararlo explícito).
   - Routes con `mk.ability:''` (vacío) → error 500 en v1.2+. Hay que especificar al menos una ability.
6. **`php artisan mk:status`** — health check final de SmartControllers.
7. **`promptForSkillDeploy()`** — pregunta si querés deployar las skills nuevas de la agencia que aún no estén en el proyecto.

#### Casos de uso

| Situación | Recomendación |
|---|---|
| Quiero el último RC para dogfooding de RETO | `mk:update` → elegir `[0] vX.Y.Z-rcN 🧪` |
| Quiero estable para producción | `mk:update` → elegir la opción con `⭐ (última estable)` |
| Necesito quedarme en una versión específica | `mk:update` → elegir manualmente la versión del menú |
| Quiero ver qué cambios vendrían sin instalar | `mk:update --dry-run` (lista versiones pero no ejecuta composer) |
| CI / scripts automatizados | No usar `mk:update` (es interactivo). Usar `composer require makroz/director-laravel:vX.Y.Z` directo |

#### Spec

- Sprint: `openspec/changes/2026-06-26-mk-update-interactive/`
- Tests: 5 Pest tests source-parsing en `tests/Unit/MkUpdateCommandTest.php` (44 assertions, todos verde).

---

### 3.13 R-PKG-015 feedback fixes (`--with-crud` hardening + Sanctum UUID helper + FK migration removal)

> **Sprint**: `makromania/260626-1845--r-pkg-015-feedback-fixes-v1.6.0-rc5`
> **Trigger**: feedback RETO fase 2 sobre `v1.6.0-rc4` (11 bugs + 2 obs).
> **Tag**: `v1.6.0-rc5` (acumulación RELEASE_AT_END, NO bumpear RETO).

Esta sección cubre los 3 cambios estructurales del sprint R-PKG-015.

#### 3.13.1 `mk:make:auth-user --with-crud` — fixes del feedback RETO

El flag `--with-crud` (introducido en v1.6.0-rc4) ahora incluye los siguientes hardening:

- **Overrides de `roles()` y `directAbilities()` con FKs explícitas** (BUG-NEW-06). El modelo generado incluye métodos `roles()` y `directAbilities()` con FK explícita `user_id` y `wherePivot('user_type', static::class)`. Sin esto, los endpoints `assignRoles`, `assignDirectAbilities`, `syncRoles`, `syncRoleAbilities` explotaban con `no such column: role_user.admin_id` en cualquier consumer MME (tablas por scope).
- **`use` statements en routes** (BUG-NEW-05). El stub `auth-user.routes.with-crud.stub` ahora importa `AdminController`, `RoleController`, `AbilityController` al inicio del bloque. Sin esto, las 14 rutas CRUD no cargaban (`ReflectionException: Class "AdminController" does not exist`).
- **Seeder sin columnas fantasma** (BUG-NEW-03, BUG-NEW-04). El `AdminRolesSeeder` ya no setea `'module' => '...'` en `abilities` ni `'description' => '...'` en `roles`. Las migrations del paquete solo definen `id, name, [description en abilities], guard, timestamps` — sin columnas extra.

**Ejemplo**:
```bash
php artisan mk:make:auth-user Admin --with-crud --profile-fields="full_name,!ci,phone"
```

#### 3.13.2 `mk:fix:sanctum-uuids` — helper para parchar la migration de Sanctum

> **Nuevo en v1.6.0-rc5** (R-PKG-015 BUG-NEW-09).

Laravel Sanctum 4 publica por default la migration `create_personal_access_tokens_table` con `$table->morphs('tokenable')` (columnas `unsignedBigInteger`). Esto es **incompatible** con consumers que usan el trait `HasUuids` en sus modelos de AuthUser (RETO Bolivia, proyectos multi-tenant con UUIDs).

**Síntoma sin este fix**:
```
SQLSTATE[22P02]: Invalid text representation
invalid input syntax for type bigint: "019f05cf-417e-7018-aa28-3f4cf4f10c0d"
```

**Uso**:
```bash
# Después de php artisan install:api, ANTES de php artisan migrate:
php artisan mk:fix:sanctum-uuids

# Dry-run (solo mostrar qué se cambiaría):
php artisan mk:fix:sanctum-uuids --dry-run
```

**Idempotente**: si la migration ya está parcheada (`uuidMorphs`), el command no hace nada. Si la migration no existe, sugiere `composer require laravel/sanctum:^4.3` + `php artisan install:api` primero.

**⚠️ Importante**: si ya corriste `php artisan migrate` antes del fix, la tabla `personal_access_tokens` tiene columnas bigint. Necesitás `migrate:fresh` (destructivo) o una migration custom que altere las columnas a string.

#### 3.13.3 FK polimórfica `role_user.user_id → auth_users.id` — BREAKING para consumers MME

> **BREAKING en v1.6.0-rc5** (R-PKG-015 BUG-NEW-07).

La migration `2026_06_18_000001_add_fk_role_user_to_auth_users.php` (introducida en v1.2.2 hardening) **se elimina del paquete** en esta versión. Asumía que TODOS los users viven en `auth_users`, lo cual NO es válido para consumers MME (R-MK-001) que usan tablas por scope.

**¿Por qué se elimina en vez de configurar?** Bajo R-G-033 ("BC no sagrado mientras RETO migre en mismo sprint") + RELEASE_AT_END (1 solo consumer activo, RETO clean rebuild desde 0), eliminar es más sano que agregar un config flag que solo un consumer va a usar.

**Migración para consumers que aplicaron esta FK en v1.6.0-rc4** (ejecutar ANTES de `composer update`):
```sql
ALTER TABLE role_user DROP CONSTRAINT role_user_user_id_foreign;
ALTER TABLE ability_user DROP CONSTRAINT ability_user_user_id_foreign;
-- Mantener role_user.role_id → roles.id (sí aplica)
-- Mantener ability_user.ability_id → abilities.id (sí aplica)
```

Consumers con clean rebuild desde 0 (RETO fase 3+) NO necesitan esto — la nueva DB no tendrá la FK aplicada.

Si el consumer necesita la FK a su tabla custom, agrega una migration propia:
```php
Schema::table('role_user', function (Blueprint $t) {
    $t->foreign('user_id')->references('id')->on('admins')->cascadeOnDelete();
});
```

#### 3.13.4 `HasAbilities::abilities()` — SQL Postgres-compatible

> **Fix en v1.6.0-rc5** (R-PKG-015 BUG-NEW-08).

La subquery de `whereExists` ahora hace `->join('abilities', 'abilities.id', '=', 'ability_role.ability_id')` explícito. Antes referenciaba `abilities.id` vía `whereColumn` sin joinear, lo cual MySQL/MariaDB toleraban pero PostgreSQL rompía con `SQLSTATE 42P01`.

**Síntoma sin este fix**:
- `login()` y `me()` retornaban `"abilities": []` en Postgres.
- `GET /api/admins` explotaba al eager-load `abilities`.

Con el fix, el SQL es portable cross-engine (MySQL, MariaDB, PostgreSQL, SQLite).

#### 3.13.5 R-PKG-016 feedback fixes (RETO fase 3 sobre v1.6.0-rc5)

> **Fixes en v1.6.0-rc6** (R-PKG-016). 8 bugs nuevos + 2 drift fixes pineados con 8 audit tests adicionales en `AuthUserFeedbackAuditTest.php`.

**Critical** (bloqueantes para producción):

- **BUG-NEW-13** (`--with-crud` → `routes/api.php` con DOS bloques PHP): el scaffolder insertaba el CRUD stub completo (con `<?php` opener + `use` statements + body) antes del cierre del último grupo, generando un segundo bloque PHP que rompía `loadRoutesFrom` con `ReflectionException: Class "AdminController" does not exist`. **Fix**: `MakeAuthUserCommand::extendRoutesWithCrud()` extrae los `use` statements del stub via regex (`preg_match_all('/^use\s+[^;]+;\s*$/m')`) y los inyecta al inicio del `routes/api.php` (después del primer `<?php`, con dedup via `str_contains`). Luego inserta solo el cuerpo de las rutas (sin `<?php`, sin `use`) antes del cierre del grupo. Resultado: UN solo bloque PHP con imports consolidados al inicio (PSR-12).

- **BUG-NEW-16** (mutations sin `user_type` en pivot MME-polimórfica): `HasRoles::assignRole()` y `HasAbilities::giveAbilityTo()` usaban `syncWithoutDetaching([$id])` sin setear `user_type` en el pivot. En consumers MME con FK polimórfica (`role_user` con columna `user_type`), el INSERT quedaba con `user_type = NULL` → `NOT NULL violation`. **Fix**: helpers `pivotExtras()` y `abilityPivotExtras()` detectan via `Schema::hasColumn('role_user', 'user_type')` (cacheado en memoria del proceso); si la pivot tiene la columna, agregan `['user_type' => static::class]` al payload del sync. BC-safe: si la pivot NO tiene `user_type`, el comportamiento es idéntico al previo.

- **BUG-NEW-17** (`HasAbilities::abilities()` retornaba `[]` para users sin direct abilities): el método hacía `belongsToMany(static::class, 'ability_user', ...)` (JOIN directo a la pivot) + filtro `whereExists` que no aplicaba a filas inexistentes. Para users sin direct abilities, el JOIN retornaba 0 rows. **Fix**: refactor a `whereIn('abilities.id', $unionSubquery)` con subqueries `UNION ALL` (path 1: `ability_user.user_id = ?`, path 2: `ability_role JOIN role_user`). Portable cross-engine, lazy. El cambio de `whereExists` a `whereIn` también resuelve el BUG-NEW-08 (Postgres SQLSTATE 42P01).

- **BUG-NEW-20** (`CRUDSmart::show/update/destroy(int $id)` rompía con UUIDs): consumers que usan `HasUuids` generan IDs string tipo `01HXYZ...`. **Fix**: signatures cambiadas a `string|int $id` (PHP 8.0+ union type). BC: cualquier código existente que pase `int` sigue funcionando.

**High**:

- **BUG-NEW-19** (rutas con `'{ admin }'` con espacios): el stub `auth-user.routes.with-crud.stub` emitía rutas con espacios alrededor del placeholder (`'{ {{moduleNameLower}}}'`). Después del str_replace con `admin`, quedaba `'{ admin }'` que Laravel interpretaba como ` admin` (con espacio). **Fix**: stub ahora emite `'{ {{moduleNameLower}}}'` sin espacios → `'{admin}'` post-resolve.

**Medium**:

- **BUG-NEW-15** (`create-super-admin` sin `--name` en `--no-interaction` → NULL): el `$this->ask('Nombre')` retorna `null` en modo no-interactive. **Fix**: fallback chain (1) `--name=` flag, (2) prompt interactivo, (3) `ucfirst(strtolower(explode('@', $email)[0]))`, (4) default `'Admin'`.
- **BUG-NEW-18** (`AbilityController with:['roles']` no existe): el modelo `Ability` NO tiene relation `roles()`. **Fix**: stub ahora usa `'with' => []` y `'allowedIncludes' => []`.
- **BUG-NEW-14** (docblock suelto, 3er ciclo): `*/` quedaba pegado al `/**` del siguiente bloque. **Fix**: terminación con `\n\n` (doble newline).

**Drift fix**:

- **BUG-NEW-10** (`checkSanctumInstalled` drift post `composer require`): el comando seguía reportando "Sanctum no instalado" después de que el consumer instalara el package, porque `class_exists()` falla si el autoloader no regeneró el classmap. **Fix**: helper `isSanctumInstalled()` con fallback `file_exists(base_path('vendor/laravel/sanctum/composer.json'))`.

#### Spec

- Sprint: `makromania/260626-2200--r-pkg-016-feedback-fixes-v1.6.0-rc6` (en `projects/mk-director/packagist/mk-director-laravel/`).
- Tests: 22 Pest tests source-parsing + reflection en `tests/Feature/AuthUserFeedbackAuditTest.php` (117 assertions, todos verde).
- Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (sección "🆕 Bugs nuevos en v1.6.0-rc5").

---

#### 3.13.6 R-PKG-017 feedback fixes (RETO fase 4 sobre v1.6.0-rc6)

> **Fixes en v1.6.0-rc7** (R-PKG-017). 5 bugs nuevos pineados con 5 audit tests adicionales en `Fase4FeedbackAuditTest.php` + 1 pineo actualizado (BUG-NEW-14 test reescrito para reflejar la nueva fix de BUG-NEW-25).

**Critical** (bloqueantes para producción):

- **BUG-NEW-21** (`--with-crud` rompía bootstrap con `declare(strict_types=1)`): REGRESIÓN del fix de BUG-NEW-13. El scaffolder inyectaba los `use` statements del CRUD stub DESPUÉS del `<?php` opener y ANTES del `declare`, dejando `<?php\nuse ...\ndeclare(strict_types=1);` — PHP rechaza con `Fatal error: strict_types declaration must be the very first statement in the script`. Resultado: `php artisan route:list` crasheaba con `ReflectionException` / FatalError. **Fix**: `MakeAuthUserCommand::extendRoutesWithCrud()` ahora detecta vía regex si el archivo tiene un bloque `declare(...)` (ej: `declare(strict_types=1);`) inmediatamente después del `<?php` opener (con whitespace flexible entre medio). Si lo hay, inserta los `use` statements NUEVOS DESPUÉS del bloque `declare`, no antes. Si NO hay `declare`, mantiene el comportamiento previo (insertar después de `<?php\n`). BC-safe: solo cambia el orden cuando el `declare` está presente, que es lo que `auth-user.routes.stub` SIEMPRE emite. Patrón regex: `/^(<\?php[ \t]*\R)((?:[ \t]*\R)*)(declare\s*\([^)]+\)\s*;\s*\R)((?:[ \t]*\R)*)/m`.

- **BUG-NEW-22** (`AdminRepository::syncRoles/syncDirectAbilities` sin `user_type`): el endpoint CRUD `POST /api/admins/{uuid}/roles` fallaba con `SQLSTATE[23502]: null value in column "user_type"` porque el Repository scaffoldeado usaba `$admin->roles()->sync($ids)` sin extras. La fix de BUG-NEW-16 solo había cubierto `mk:auth:create-super-admin --roles=`, no el endpoint CRUD. **Fix**: 3 cambios: (a) `HasRoles::pivotExtras()` y `HasAbilities::abilityPivotExtras()` ahora son `public` (BC-safe: solo agrega visibilidad) — el Repository scaffoldeado puede consumirlos directamente; (b) `admin-repository.stub` ahora invoca `$admin->pivotExtras()` / `$admin->abilityPivotExtras()` en el payload del `sync()` via `mapWithKeys(fn ($id) => [$id => $admin->pivotExtras()])`; (c) si la pivot NO tiene `user_type` (consumer legacy), `pivotExtras()` retorna `[]` y el comportamiento es idéntico al previo. Antes el consumer tenía que hardcodear `['user_type' => Admin::class]` manualmente — ahora el scaffolder genera el código correcto out-of-the-box.

- **BUG-NEW-23** (`TokenIssuer::rotateRefreshToken` con `Hash::check()` RuntimeException → HTTP 500): `POST /api/admin/auth/refresh` con un refresh token no-bcrypt retornaba HTTP 500 en vez de HTTP 401. Causa: `Hash::check($plaintext, $tokenModel->token)` lanza `RuntimeException: This password does not use the Bcrypt algorithm` cuando el hash en la columna `token` no es bcrypt válido (caso edge: tokens legacy con sha256, datos corruptos, scope mismatching). El `AuthController::refresh()` solo captura `InvalidRefreshTokenException`, NO `RuntimeException`. **Fix**: envolver el `Hash::check()` en try/catch; mapear CUALQUIER `RuntimeException` a `InvalidRefreshTokenException::hashMismatch()` (que SÍ retorna HTTP 401 via el controller). El path normal (hash bcrypt válido, hash no matchea) sigue funcionando idéntico. Caso test pineado: cualquier excepción de `Hash::check` se mapea consistentemente al error estándar de invalidación.

**Medium**:

- **BUG-NEW-24** (`Admin::newFactory()` retorna `AdminFactory` sin importarlo): drift nuevo introducido en `v1.6.0-rc5`/`rc6` que el scaffolder pineaba parcialmente. El modelo concreto se generaba con `protected static function newFactory(): AdminFactory` PERO sin el `use App\Modules\Admin\Database\Factories\AdminFactory;` necesario. Resultado: cualquier test que use `Admin::factory()->create()` fallaba con `Class "App\Modules\Admin\Database\Factories\AdminFactory" not found`. **Fix**: el placeholder `{{factoryHasFactoryUse}}` ahora emite 2 imports cuando `$withCrud` está activo: (a) `use Illuminate\Database\Eloquent\Factories\HasFactory;` (existente) + (b) `use App\Modules\{$scope}\Database\Factories\{$scope}Factory;` (NUEVO). Tests del factory funcionan out-of-the-box sin workarounds.

**Cosmetic (low)**:

- **BUG-NEW-25** (docblock de profile fields con drift en indentación, 4to ciclo): el fix anterior terminaba el docblock generado con `\n\n` (doble newline) para separar del próximo bloque vía `*/`. PERO el control del blank line entre docblocks vivía en el GENERADOR (no en el stub), y con 5+ profile fields el doble newline acumulaba drift visual. **Fix robusta**: el docblock generado cierra con `\n` simple (`     */\n`); el control del blank line entre docblocks vive en el STUB (`{{profileFieldsDocblock}}\n\n    /**`). Esto elimina el drift y mantiene el control de espaciado en UN lugar (single source of truth). El pineo de BUG-NEW-14 se actualizó para reflejar la nueva realidad (newline simple en el docblock generado, blank line en el stub).

#### Spec

- Sprint: `makromania/260626-2254--r-pkg-017-feedback-fixes-v1.6.0-rc7` (en `projects/mk-director/packagist/mk-director-laravel/`).
- Tests: 14 nuevos Pest tests source-parsing + reflection en `tests/Feature/Fase4FeedbackAuditTest.php` (33 assertions, todos verde). Total paquete: 489 passing, 4 pre-existing failures (UPGRADE_1.2.md backlog RC4, sin regresión).
- Audit e2e: scaffolder corrido en `apps/sandbox-laravel` con `mk:make:auth-user Admin --with-crud --profile-fields=full_name,phone,address,dni,birthdate,is_active --no-interaction`. Genera 17 archivos del CRUD + Model con 6 profile fields + 16 rutas API registradas (incluyendo `{admin}` sin espacios, gracias a R-PKG-016 BUG-NEW-19). `php artisan route:list` funciona sin FatalError.
- Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (sección "🆕 Bugs nuevos en v1.6.0-rc6").

#### Cambios BC

- **`HasRoles::pivotExtras()` y `HasAbilities::abilityPivotExtras()` ahora son `public`** (antes `protected`). Visibilidad ampliada — no rompe ningún caller existente. Habilita que el Repository scaffoldeado (y cualquier consumer que quiera inspeccionar el payload de una pivot polimórfica) consuma el helper directamente sin reflection ni hardcodeo del FQCN. Ver BUG-NEW-22 fix arriba para el contexto completo. Si tu consumer tiene tests que mockean estos métodos con `protected function`, actualizar a `public function`. Si no, no requiere acción.

---

#### 3.13.7 R-PKG-018 feedback fixes (RETO fase 5 sobre v1.6.0-rc7)

> **Fixes en v1.6.0-rc8** (R-PKG-018). 2 bugs nuevos CRITICAL/MEDIUM pineados + 1 OBS documentada + 2 mejoras LOW. 9 nuevos tests pineados (3 BUG-NEW-26 + 3 BUG-NEW-27 + 4 OBS-NEW-01) + 2 tests actualizados (BUG-NEW-23 pineados con la nueva realidad tras descubrir la causa raíz).

**Critical** (bloqueantes para producción):

- **BUG-NEW-26** (`TokenIssuer::rotateRefreshToken` asume bcrypt, Sanctum v4 hashea con SHA256): **CAUSA RAÍZ descubierta** del BUG-NEW-23. El código previo usaba `Hash::check($plaintext, $tokenModel->token)` para comparar el plaintext del refresh token contra el hash guardado en `personal_access_tokens.token`. PERO Sanctum v4.3.2 hashea con **SHA256** (no bcrypt) — verificado en `vendor/laravel/sanctum/src/HasApiTokens.php:66` y `PersonalAccessToken.php:61,67`. El hash guardado tiene 64 chars (SHA256), no 60 (bcrypt). Resultado: `Hash::check()` SIEMPRE lanzaba `RuntimeException: This password does not use the Bcrypt algorithm`. El catch de BUG-NEW-23 mitigaba el 500 → 401, pero el refresh NUNCA funcionaba (incluso con token recién emitido y válido). **Fix**: cambiar a `hash_equals($tokenModel->token, hash('sha256', $plaintext))` — timing-safe y consistente con la implementación interna de Sanctum v4. El try/catch de BUG-NEW-23 se mantiene como **defense-in-depth** por si Sanctum rota de algoritmo en el futuro (bcrypt→argon2→sha512). Documentación actualizada para reflejar SHA256 (antes incorrectamente decía "bcrypt").

**Medium** (calidad / DX):

- **BUG-NEW-27** (`AuthController::refresh` scaffoldeado no captura `InvalidRefreshTokenException` con mensaje específico): el catch del scaffolder solo capturaba `\Illuminate\Auth\Access\AuthorizationException`. Como `InvalidRefreshTokenException` extiende `AuthorizationException`, el catch SÍ lo capturaba — pero con un mensaje genérico ("Refresh token inválido.") en vez del mensaje detallado (e.g. "Refresh token expired.", "Refresh token hash mismatch.", "Refresh token scope mismatch: expected `admin`, got `member`.", "Refresh token not found."). **Fix**: el stub `auth-user.auth-controller.stub` ahora tiene un catch específico para `InvalidRefreshTokenException` ANTES del catch genérico. El catch específico expone el mensaje detallado vía `sendError($e->getMessage(), [], 401)` para mejor DX (front-end puede mostrar mensaje preciso) y testabilidad (tests e2e pueden pinear el path específico del error). BC-safe: el catch genérico queda como defense-in-depth.

- **OBS-NEW-01** (`mk:discover-abilities` descubre abilities desde `$mkConfig` de SmartControllers, R-PKG-015): el path de fallback del comando ya estaba implementado en R-PKG-015 (release v1.6.0-rc5) para leer `$mkConfig['model']` de los SmartController scaffoldeados vía `--with-crud` y generar las 5 abilities CRUD estándar (`{scope}.{model}.viewAny|view|create|update|delete`). Sin embargo, NO había tests pineados específicos para este path — solo el código. RETO fase 5 reportó "No se descubrieron abilities" pero la causa real fue que el `ModuleServiceProvider` del módulo admin implementaba `discoverAbilities()` con un subset distinto de abilities (regla Q1 hybrid: provider es source-of-truth primario). **Pineo de tests**: 4 tests nuevos en `DiscoverAbilitiesCommandTest.php` validan que (a) `discoverAbilitiesFromMkConfig()` existe en el código, (b) se llama desde `processModule()` cuando source=fallback, (c) genera exactamente 5 abilities CRUD desde un SmartController stub con `$mkConfig['model']`, (d) ignora silenciosamente controllers que NO extienden `SmartController`. Si RETO quiere que `mk:discover-abilities` use el mkConfig path INCLUSO cuando el provider retorna abilities, es un cambio de regla Q1 (requeriría decisión de Mario).

**Low** (nice-to-have):

- **MEJORA-NEW-01** (preparación para `--with-rate-limit` flag): documentado como mejora futura. No implementado en este sprint para mantener scope acotado. Sigue el patrón de `--with-auth-rbac` (R-PKG-010).

- **MEJORA-NEW-02** (esta sección): documentar el patrón "Sanctum v4 + UUIDs + SHA256" para que otros consumers no caigan en el mismo bug.

#### Patrón Sanctum v4 + UUIDs + SHA256 (MEJORA-NEW-02)

> **Lección reusable cross-project**: cualquier consumer que use `makroz/director-laravel` con Sanctum v4.x DEBE confiar en el `TokenIssuer` del paquete para emitir/validar tokens. NO implementar la comparación hash manualmente.

**Cómo funciona Sanctum v4 internamente**:

1. **Emisión** (`HasApiTokens::createToken`):
   ```php
   'token' => hash('sha256', $plainTextToken)  // 64 chars hex
   ```

2. **Validación** (`Sanctum::findToken`):
   ```php
   hash_equals($token->token, hash('sha256', $plainText))  // timing-safe
   ```

3. **Formato del token entregado al cliente**: `<id>|<plaintext>` (e.g. `1|abc123def...`). El id y el plaintext van separados por `|`. El hash en DB se calcula SOLO sobre el plaintext.

**Anti-patterns a evitar**:

- ❌ `Hash::check($plaintext, $tokenModel->token)` — asume bcrypt (60 chars), incompatible con SHA256 de Sanctum v4 (64 chars). SIEMPRE falla con `RuntimeException`.
- ❌ `hash('sha256', $tokenCompleto)` — hashea TODO el string `<id>|<plaintext>`, no solo el plaintext. El hash en DB se calculó solo sobre el plaintext, por lo que el lookup NO matchea.
- ❌ `md5($plaintext)` / `sha1($plaintext)` — algoritmos inseguros. Sanctum usa SHA256 específicamente por balance seguridad/performance.

**Patrón correcto**:

```php
// Para refresh tokens (vía TokenIssuer del paquete, BC-safe):
$tokenIssuer->rotateRefreshToken($refreshTokenString, $expectedScope);

// Si necesitás validar manualmente (NO recomendado):
[$tokenId, $plaintext] = explode('|', $tokenString, 2);
$hashedToken = hash('sha256', $plaintext);
$token = PersonalAccessToken::find($tokenId);
$isValid = $token && hash_equals($token->token, $hashedToken);
```

**Verificación post-fix**:

```bash
# En DB: personal_access_tokens.token debe tener 64 chars (SHA256), no 60 (bcrypt)
php artisan tinker --execute 'echo strlen(Laravel\Sanctum\PersonalAccessToken::find(16)->token);'
# → 64
```

#### Spec

- Sprint: `makromania/260627-0043--r-pkg-018-feedback-fixes-v1.6.0-rc8` (en `projects/mk-director/packagist/mk-director-laravel/`).
- Tests: 9 nuevos Pest tests + 2 actualizados. Total paquete: 499 passing, 4 pre-existing failures (`UpgradeDocumentationTest` backlog RC4, sin regresión).
- BUG-NEW-26: pineado en `tests/Unit/TokenIssuerTest.php` (3 source-parsing) + `tests/Feature/Fase4FeedbackAuditTest.php` (2 actualizados de BUG-NEW-23 con la nueva realidad).
- BUG-NEW-27: pineado en `tests/Unit/Console/MakeAuthUserCommandTest.php` (3 source-parsing del stub).
- OBS-NEW-01: pineado en `tests/Feature/DiscoverAbilitiesCommandTest.php` (2 source-parsing + 2 e2e con eval-based isolated classes).
- Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (sección "🆕 Bugs nuevos en v1.6.0-rc7").

#### Cambios BC

- **`TokenIssuer::rotateRefreshToken` cambia `Hash::check` por `hash_equals(hash('sha256', ...), ...)`**. BC-safe: corrige bug crítico, no rompe ningún caller (el método `rotateRefreshToken` sigue retornando `array{access_token, refresh_token, user_id}` o lanzando `InvalidRefreshTokenException` igual que antes). Si tu consumer mockeaba `Hash::check` en tests de `rotateRefreshToken`, actualizar para mockear `hash_equals` o usar el path real con Sanctum v4.
- **`InvalidRefreshTokenException` ahora se captura explícitamente en el `AuthController::refresh` scaffoldeado**. BC-safe: el catch genérico de `AuthorizationException` se mantiene como fallback (es la parent class), pero el específico tiene precedencia para mensajes detallados. Si tu consumer custom AuthController tenía su propio catch para esta excepción, no requiere acción (la jerarquía de catches ya la cubre).

#### 3.13.8 R-PKG-019 feedback fixes (RETO fase 6 sobre v1.6.0-rc8)

> **Fixes en v1.6.0-rc9** (R-PKG-019). 2 bugs nuevos (1 HIGH + 1 MEDIUM) + 1 cambio BC-safe (`classesNamespacePrefix()` ahora overridable). 6 nuevos tests pineados (2 OBS-NEW-02 + 4 BUG-NEW-28). Patrón consistente con R-PKG-014..018: source-parsing + e2e eval-based.

**High** (feature prometida no funcionaba end-to-end):

- **OBS-NEW-02** (`mk:discover-abilities` retorna `"count": 0` por bug en `discoverClassesInDir`): el método iteraba `get_declared_classes()` que SOLO retorna clases ya loaded. En contexto artisan CLI, las controllers scaffoldeadas NO se cargan hasta que `route:list` o el bootstrap las referencia. Resultado: el comando reportaba `"Classes: 1"` (solo el `AdminServiceProvider`) en vez de 4 (provider + 3 controllers). El path `discoverAbilitiesFromMkConfig()` pineado en R-PKG-015 funcionaba en unit tests pero NO en runtime de consumers reales. **Fix**: `discoverClassesInDir()` ahora hace `require_once $realPath` ANTES de iterar `get_declared_classes()`, forzando la declaración sin depender del autoload trigger. Después del require, el matching por suffix contra `get_declared_classes()` funciona correctamente. **Side-effects documentados**: el `require_once` es seguro en proyectos Laravel siguiendo convención PSR-4 (cada archivo = una clase, sin código top-level). Si tu consumer tiene archivos con código top-level (helpers, side-effects), esos side-effects ocurrirán — trade-off explícito vs parsear namespace via regex. Ver § 3.6 "Force-require de classes no autoloaded" para el detalle completo.

**Medium** (drift entre scaffolder y runtime):

- **BUG-NEW-28** (`AdminFactory` scaffoldeado hardcodea `email_verified_at` siempre): el stub `admin-factory.stub` emitía `'email_verified_at' => now()` SIEMPRE, sin condicional al flag `--verify-email`. Si el scaffolder se llamaba SIN `--verify-email`, la tabla `{scope}s` NO tiene columna `email_verified_at`, y cualquier test que use `{Scope}::factory()->create()` fallaba con `SQLSTATE[HY000]: General error: 1 table admins has no column named email_verified_at`. Workaround aplicado en RETO fase 6: usar `{Scope}::create([...])` directo en tests (no factory), pero el factory pattern quedaba inutilizable. **Fix**: el stub ahora envuelve `email_verified_at` en `if (Schema::hasColumn((new {Scope}())->getTable(), 'email_verified_at'))`. Si la columna existe (consumer con `--verify-email`), la factory funciona idéntico a antes (BC-safe). Si NO existe (consumer sin `--verify-email`), el array `definition()` no incluye la key y la factory funciona sin error. Implementado con check runtime en vez de dos stubs distintos — más robusto y BC-clean.

**Cambios BC-safe**:

- **`classesNamespacePrefix()` ahora es un método protected overridable**. Antes el matching de clases discovered usaba `str_starts_with($declared, 'App\\Modules')` hardcoded, lo cual rechazaba clases en otros namespaces. Ahora el prefijo default sigue siendo `App\\Modules` (regla R-MK-001) pero los tests pueden override el método para retornar `null` (skip prefix check) u otro prefijo custom. BC-safe: consumers reales mantienen el comportamiento default. Solo impacta tests que extiendan `DiscoverAbilitiesCommand`.

#### Patrón de validación `Schema::hasColumn` en factories scaffoldeadas (MEJORA-NEW-04)

> **Lección reusable**: cuando un scaffolder genera código que asume columnas opcionales (gated por flags como `--verify-email`), el stub debe usar `Schema::hasColumn()` runtime check en vez de generar código estático condicional al flag. Esto evita drift entre el scaffolder y el schema real.

**Por qué**: el scaffolder no tiene acceso al estado de la migración al momento de generar el archivo. Asumir que una columna existe (porque el scaffolder se llamó con `--verify-email`) puede ser incorrecto si el consumer revierte la migración o nunca la ejecutó. El check runtime `Schema::hasColumn()` consulta el estado actual del schema y se adapta dinámicamente.

**Aplicación**: cualquier stub que genere factories, seeders, o resources que referencien columnas opcionales debe usar este patrón. Si en el futuro se agregan más flags opt-in (e.g. `--with-audit-log`, `--with-soft-deletes`), las columnas que esos flags activan deben tener el mismo check runtime.

#### Spec

- Sprint: `makromania/260627-0045--r-pkg-019-feedback-fixes-v1.6.0-rc9` (en `projects/mk-director/packagist/mk-director-laravel/`).
- Tests: 6 nuevos Pest tests. Total paquete: 505 passing, 4 pre-existing failures (`UpgradeDocumentationTest` backlog RC4, sin regresión).
- OBS-NEW-02: pineado en `tests/Feature/DiscoverAbilitiesCommandTest.php` (1 source-parsing + 1 e2e real-disk con archivos PHP creados en tempdir).
- BUG-NEW-28: pineado en `tests/Feature/AdminUserFactoryStubTest.php` (archivo nuevo, 4 tests: source-parsing del stub, anti-regresión del hardcode, render + syntax check, render content).
- Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (sección "🐛 BUGS NUEVOS" en fase 6 sobre v1.6.0-rc8).

#### 3.13.9 R-PKG-020 feedback fixes (HALLAZGO-NEW-01 + UPGRADE_1.2.md backlog cleanup)

> **Fixes en v1.6.0-rc9** (R-PKG-020). Cierra el HALLAZGO-NEW-01 reportado en RETO fase 6 (`$user->roles()->attach([$id])` no incluía `user_type`) y el backlog de 4 tests pre-existing del `UpgradeDocumentationTest` que fallaban desde v1.6.0-rc4.

##### HALLAZGO-NEW-01 — Pivot class con auto-set de `user_type` (solución de raíz)

**Problema**: en consumers MME-polimórficos (R-MK-001, FK polimórfica con columna `user_type` en la pivot), las mutaciones nativas de Eloquent sobre las relations `roles()` y `directAbilities()` (`attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot`) NO seteaban `user_type` automáticamente. Solo los métodos helper (`assignRole`, `syncRoles`, `giveAbilityTo`, `syncDirectAbilities`) lo hacían via `pivotExtras()` / `abilityPivotExtras()`.

**Síntoma sin este fix**:
```php
$admin->roles()->attach([$roleId1, $roleId2]);
// → SQLSTATE[23502]: null value in column "user_type" of relation "role_user" violates not-null constraint
```

Workaround aplicado en RETO fase 6: usar `syncRoles([...])` en tests en vez de `attach([...])`. El helper `AdminService::syncRoles()` scaffoldeado funcionaba OK, pero cualquier consumer que usara `attach()` directo en código custom seguía rompiendo.

**Solución de raíz**: las relations `roles()` y `directAbilities()` ahora usan `->using(MkRoleUserPivot::class)` y `->using(MkAbilityUserPivot::class)`. Estas clases extienden `MkPivot` (base abstracta) que registra un listener `creating` que setea `user_type = $pivot->pivotParent->getMorphClass()` automáticamente cuando la pivot tiene la columna.

**Flujo del listener** (registrado en `MkPivot::boot()`):

1. **Skip si consumer override**: si `$pivot->user_type !== null` (consumer ya lo seteó via `attach($id, ['user_type' => 'X'])`), respeta su valor.
2. **Skip si pivot legacy**: si `Schema::hasColumn($pivot->getTable(), 'user_type')` retorna `false` (cacheado en memoria del proceso), no hace nada. BC-safe con consumers legacy que NO tienen la columna.
3. **Auto-set si MME-polimórfico**: setea `$pivot->user_type = $pivot->pivotParent->getMorphClass()` (FQCN del modelo concreto, ej: `App\Modules\Admin\Models\Admin`).

**Archivos nuevos**:

| Archivo | Propósito |
|---|---|
| `src/Auth/Pivots/MkPivot.php` | Base abstracta con `boot()` que registra el listener `creating` |
| `src/Auth/Pivots/MkRoleUserPivot.php` | Concrete pivot para `role_user` (`protected $table = 'role_user'`) |
| `src/Auth/Pivots/MkAbilityUserPivot.php` | Concrete pivot para `ability_user` (`protected $table = 'ability_user'`) |

**Archivos modificados**:

| Archivo | Cambio |
|---|---|
| `src/Auth/Concerns/HasRoles.php` | `roles()` ahora retorna `->using(MkRoleUserPivot::class)->withTimestamps()` |
| `src/Auth/Concerns/HasAbilities.php` | `directAbilities()` ahora retorna `->using(MkAbilityUserPivot::class)->withTimestamps()` |

**Pinear custom pivot class** (para consumers con tablas pivot custom, ej: `member_role` en lugar de `role_user`):

```php
namespace App\Modules\Member\Pivots;

use Mk\Director\Auth\Pivots\MkPivot;

class MkMemberRolePivot extends MkPivot
{
    protected $table = 'member_role';
}
```

```php
// En tu modelo Member:
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class, 'member_role')
        ->using(\App\Modules\Member\Pivots\MkMemberRolePivot::class)
        ->withTimestamps();
}
```

**Opt-out** (si un consumer quiere deshabilitar el auto-set):

```php
// Override sin ->using(...):
public function roles(): BelongsToMany
{
    return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
}
```

**Tests pineados** (9 nuevos en `tests/Unit/Auth/HallazgoNew01PivotTest.php`):

- Source-parsing: ambas concrete classes existen y extienden `MkPivot` base.
- Source-parsing: declaran `protected $table = 'role_user'` / `'ability_user'`.
- Source-parsing: `HasRoles::roles()` y `HasAbilities::directAbilities()` usan `->using(MkRoleUserPivot::class)` / `MkAbilityUserPivot::class`.
- Source-parsing: `MkPivot::boot()` registra listener `creating` con la lógica correcta.
- Anti-regresión: listener respeta `user_type` explícito del consumer.
- Anti-regresión: listener es no-op si la pivot NO tiene `user_type` (BC-safe).

**Cross-stack impact**: 0. El cambio es interno al paquete. No afecta endpoints HTTP, contratos de Provider, ni signatures de `useAuth()` / `useMkAuth()`.

##### UPGRADE_1.2.md — backlog cleanup

**Problema**: el test `tests/Unit/Process/UpgradeDocumentationTest.php` tenía 4 tests fallando desde v1.6.0-rc4 porque `docs/UPGRADE_1.2.md` no existía. Era un **pre-existing failure** del backlog RC4 sin regresión, pero contaminaba la suite (505 passing / 4 failing en vez de 509/0).

**Fix**: archivo `docs/UPGRADE_1.2.md` creado con:

- 4 breaking changes históricos del salto 1.1.x → 1.2.x documentados con detalle (UUID primary key, opt-in multi-tenancy, MkAbility refactor, ListManager unknown operator whitelist).
- Sección `## Rollback` explícita con 3 paths (restore from backup, manual SQL rollback, forward fix).
- Aviso prominente de **irreversibilidad** del UUID migration (regex `irreversible|no rollback|backup`).
- Sección `## Migration script` referenciando el companion script `bin/migrate-1.1-to-1.2.php` con sus flags (`--dry-run`, `--help`, `--connection=`).

**Tests pineados**: 0 nuevos (los 4 tests pre-existing ahora pasan). Suite completa: **518 passing, 0 failing** (de 505+4 fail).

#### Spec

- Sprint: `makromania/260627-0045--r-pkg-020-feedback-fixes-v1.6.0-rc9` (misma rama, segunda iteración post Mario "incluir de una").
- Tests: 9 nuevos Pest tests (HALLAZGO-NEW-01). Total paquete: **518 passing, 0 failing** (backlog RC4 cerrado).
- HALLAZGO-NEW-01: pineado en `tests/Unit/Auth/HallazgoNew01PivotTest.php` (9 source-parsing tests: existence + table + using + listener logic + BC-safe).
- UPGRADE_1.2.md: 4 tests pre-existing del `UpgradeDocumentationTest` ahora verde (verificado con `--filter`).
- Source: `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md` (HALLAZGO-NEW-01) + audit-2026-06-17-R3-013 (UPGRADE_1.2.md backlog original).

---

## 🔍 4. ListManager: El Motor de Búsquedas (Guía para Frontend)

Tanto para **Next.js** como para **React Native**, el consumo de listas es estandarizado mediante parámetros URL:

### 4.1 Paginación
Controlada por `page` y `per_page` (o `cursor` en modo Cursor Pagination).

### 4.2 Filtrado Dinámico
Usa el parámetro `filter[columna][operador]=valor`.
- **Filtro exacto**: `/api/surveys?filter[status]=A`
- **Operadores**:
  - `neq`: Not Equal (ej: `filter[status][neq]=D`)
  - `gt` / `gte`: Greater than (ej: `filter[price][gt]=100`)
  - `lt` / `lte`: Less than
  - `in`: Lista de valores (ej: `filter[category_id][in]=1,2,3`)

### 4.3 Búsqueda Global (`q=`)
Realiza una búsqueda tipo "LIKE" en todos los campos definidos en la configuración `'searchable'` del controlador.
- Ejemplo: `/api/surveys?q=encuesta`

### 4.4 Ordenamiento (`sort`)
- **Ascendente**: `?sort=title`
- **Descendente**: `?sort=-title`
- **Múltiple**: `?sort=-created_at,title`

---

## 🔌 5. Sistema de Plugins (Extensibilidad)

Puedes interceptar cualquier flujo del controlador sin modificar el core.

### 5.1 Crear un Plugin:
Crea una clase que implemente `Mk\Director\Contracts\MkPluginInterface`.

```php
namespace App\MkPlugins;

use Mk\Director\Contracts\MkPluginInterface;

class AuditPlugin implements MkPluginInterface
{
    public function boot(): void { /* ... */ }
    
    public function beforeQuery($query, $request): void {
        // Ejemplo: Forzar filtrado por un tenant_id global
        // $query->where('tenant_id', Auth::user()->tenant_id);
    }

    public function beforeSave($request, array &$data, string $mode): void {
        if ($mode === 'create') {
            // Lógica solo para nuevos registros
        }
    }

    public function afterSave($model, $request, string $mode): void {
        // ...
    }

    public function beforeDelete($model, $request): void { }
    public function afterDelete($model, $request): void { }

    public function afterResponse(&$responseData): void {
        // Modificar el JSON final antes de enviarlo
        if (is_array($responseData) && isset($responseData['__extraData'])) {
            $responseData['__extraData']['audit_checked'] = true;
        }
    }
}
```

### 5.2 Registrarlo Globalmente (`config/mk_director.php`):
```php
'plugins' => [
    \App\MkPlugins\AuditPlugin::class,
],
```

### 5.3 Registrarlo Localmente (Solo en un Controlador):
Puedes habilitar plugins específicamente para un controlador añadiéndolos a su `$mkConfig`. Esto es ideal para validaciones o auditorías que solo aplican a un recurso.

```php
protected array $mkConfig = [
    'model'   => Survey::class,
    'plugins' => [
        \App\MkPlugins\SpecialValidationPlugin::class,
    ],
];
```

### 5.4 Plugins Disponibles en el Core:

#### `FileStoragePlugin`
Maneja automáticamente la subida de archivos y la conversión de rutas a URLs completas.

**Configuración en Controlador:**
```php
'plugins' => [
    \Mk\Director\Plugins\FileStoragePlugin::class,
],
'plugins_config' => [
    'file_storage' => [
        'fields'   => ['image', 'avatar'], // Campos que son archivos
        'disk'     => 'public',             // Disco de Laravel
        'path'     => 'surveys/images',     // Carpeta destino
        'auto_url' => true,                 // Convertir ruta a URL en la respuesta
    ]
]
```

---

---

## 🛡️ 7. Diagnóstico y Estándares de Calidad

MK-Director incluye un ecosistema de validación proactiva para evitar errores de configuración comunes.

### 7.1 El Comando `mk:status`
Este comando audita todos tus controladores `SmartController` y verifica:
- **Integridad de Clases**: Existencia de Modelos, Servicios y Enums configurados.
- **Base de Datos**: Verifica que los campos en `'searchable'` existan en la tabla física.
- **Plugins**: Valida que el modelo tenga los campos necesarios (`getRequirements()`).

**Uso:**
```bash
php artisan mk:status
```

### 7.2 Creación de Plugins con Requerimientos
Para que un plugin sea compatible con el sistema de diagnóstico, debe implementar `getRequirements()`:

```php
public function getRequirements(array $config): array {
    return [
        'fields' => $config['fields'] ?? [], // Campos requeridos en el modelo
        'config' => ['disk', 'path']         // Llaves requeridas en plugins_config
    ];
}
```

---

## 🚀 8. Integración con el Frontend (Guía Rápida)

MK-Director estandariza la comunicación mediante un protocolo de URL predefinido:

- **Búsqueda Global**: `?q=termino`
- **Filtros**: `?filter[campo][operador]=valor` (ej: `filter[status]=A`)
- **Ordenamiento**: `?sort=-created_at` (el prefijo `-` indica descendente)
- **Paginación**: `?page=2&per_page=15`

Toda respuesta exitosa (200 OK) garantiza la presencia de la llave `data` y, en colecciones, la llave `__extraData` con metadatos de paginación y telemetría. Con el flag `mk_director.response.top_level_extra_data` activo (rc12 opt-in / GA default), `__extraData` se emite como **sibling top-level** de `data` (forma canónica que matchea `@makroz/core` `MkResponse<T>`). Sin el flag, se emite en el shape legacy anidado dentro de `data` para BC con consumers pre-rc12.

---

## 💡 Consideraciones de Performance y Seguridad

1.  **`allowedIncludes`**: Siempre define qué relaciones puede pedir el frontend para evitar fugas de información.
2.  **`auto_cache`**: Úsalo para tablas con mucha lectura y poca escritura. MK-Director invalidará los tags de caché automáticamente.
3.  **`MK_DIRECTOR_DEBUG`**: Manténlo en `true` solo en local para ver el análisis de queries y tiempos de ejecución.
