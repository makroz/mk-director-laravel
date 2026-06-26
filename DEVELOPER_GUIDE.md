# 📖 Manual del Desarrollador — MK-Director (`mk-laravel`)

Bienvenido a la guía oficial de **MK-Director Core**, el motor de backend diseñado para acelerar el desarrollo de APIs robustas mediante una capa de abstracción potente sobre Laravel.

---

## 🏗️ 1. Arquitectura y Filosofía

MK-Director se basa en el principio de **Zero-Coupling** y **Configuración sobre Código**. El objetivo es que puedas definir el comportamiento de un módulo completo (CRUD, búsquedas, caché, plugins) simplemente configurando un arreglo en tu controlador.

### Flujo Estándar de Respuesta
Todas las respuestas de MK-Director siguen este formato:
```json
{
  "data": {
    "data": [...], // Colección de objetos o un objeto único
    "__extraData": {
       "total": 150,
       "perPage": 15,
       "page": 1,
       "plugin_verified": true, // Inyectado por plugins
       ...
    }
  },
  "message": "Operación exitosa",
  "status": 200,
  "execution_time": "0.02s" // (Solo en modo Debug)
}
```

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
del provider del módulo (preferred) y las persiste en `{scope}_abilities`
via UPSERT idempotente. Es el segundo paso del workflow de scaffolding
RBAC (después de `php artisan migrate`).

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

#### Scope detection (D6)

El scope se deriva del nombre del módulo: `Str::snake(Str::plural($name))`.
Ejemplos:
- `Admin` → tabla `admin_abilities`
- `Member` → tabla `member_abilities`
- `Billing` → tabla `billing_abilities`

#### Spec & tests

- **Spec / Design**: `openspec/changes/2026-06-24-discover-abilities-to-core/`
- **Tests**: 17 Pest tests en `tests/Feature/DiscoverAbilitiesCommandTest.php`
  (75 assertions). Cubren: signature con 4 flags, hybrid D1
  (provider OR fallback, never both), interactive prompt D3,
  UPSERT idempotente, scope detection, `#[Ability]` attribute
  TARGET_METHOD + IS_REPEATABLE, PHP 8.5 PCRE2 regex sin escape de
  llaves, end-to-end con tempdir + SQLite in-memory (5 escenarios).

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
],
```

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

### Spec

- Spec: `openspec/changes/2026-06-24-auth-controller-rbac-stub/proposal.md`
- Spec formal: `openspec/changes/2026-06-24-auth-controller-rbac-stub/specs/auth-controller-rbac-stub.md`

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

Toda respuesta exitosa (200 OK) garantiza la presencia de la llave `data` y, en colecciones, la llave `__extraData` con metadatos de paginación y telemetría.

---

## 💡 Consideraciones de Performance y Seguridad

1.  **`allowedIncludes`**: Siempre define qué relaciones puede pedir el frontend para evitar fugas de información.
2.  **`auto_cache`**: Úsalo para tablas con mucha lectura y poca escritura. MK-Director invalidará los tags de caché automáticamente.
3.  **`MK_DIRECTOR_DEBUG`**: Manténlo en `true` solo en local para ver el análisis de queries y tiempos de ejecución.
