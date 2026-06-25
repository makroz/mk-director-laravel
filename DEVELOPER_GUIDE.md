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
