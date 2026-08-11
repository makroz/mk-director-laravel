# 📖 Manual del Desarrollador — MK-Director (`mk-laravel`)

Bienvenido a la guía oficial de **MK-Director Core**, el motor de backend diseñado para acelerar el desarrollo de APIs robustas mediante una capa de abstracción potente sobre Laravel.

> ## 🔴 Antes de leer nada de acá
>
> **1. La versión.** Lo publicado en Packagist es `v1.8.0` (2026-06-29); la
> rama `dev` está **más de 200 commits** por delante y **sin tag**. Un
> `composer require makroz/director-laravel` se lleva la vieja —sin el flujo
> OTP de contraseña, sin los fixes de `canMk()`— y **no avisa**. El cableado
> correcto (`path repository` con symlink) está en
> **`docs/guides/ARRANQUE.md`** del monorepo.
>
> **2. Los flags `--with-*` ya no existen.** `--with-crud`,
> `--with-auth-rbac`, `--with-status` y `--status-values` fueron **eliminados**
> (BC break R-PKG-047 D2): hoy son defaults ON y se apagan con `--no-crud`,
> `--no-rbac`, `--no-status`. **Varias secciones de este documento todavía los
> muestran** — quedaron como registro de qué se arregló en cada sprint. Leelas
> por el *por qué*, no copies los comandos. La tabla de flags vigente está en
> el `README.md`.
>
> **3. Si estás arrancando un proyecto**, no empieces por esta guía:
> `docs/guides/ARRANQUE.md` (de cero a una API corriendo) y después
> `docs/guides/TRAMPAS.md` (lo que muerde cuando ya anda). Esta guía es la
> referencia del motor, no el camino de entrada.

---

## 🏗️ 1. Arquitectura y Filosofía

MK-Director se basa en el principio de **Zero-Coupling** y **Configuración sobre Código**. El objetivo es que puedas definir el comportamiento de un módulo completo (CRUD, búsquedas, caché, plugins) simplemente configurando un arreglo en tu controlador.

### Flujo Estándar de Respuesta

**R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE**. Todas las respuestas de MK-Director siguen este formato canónico:

**Colección paginada** (single-level + grouped pagination envelope, R-PKG-024 v1.7.0 + R-PKG-032 v1.8.0):

```json
{
  "success": true,
  "message": "",
  "data": [...items...],          // ← array directo de items (NO paginator nested)
  "__extraData": {                // ← top-level (sibling de `data`)
    "pagination": {               // ← R-PKG-032 v1.8.0 MAJOR — pagination metadata GROUPED
      "current_page": 1,
      "last_page": 10,
      "per_page": 20,
      "total": 200,
      "has_more_pages": true
      // CursorPaginator emite: per_page + next_cursor + prev_cursor
    },
    "plugin_verified": true       // ← Custom keys del consumer viven FLAT aquí
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
> **R-PKG-032 (v1.8.0 MAJOR) — PAGINATION ENVELOPE GROUPING**: post-v1.8.0, las 5 (LengthAwarePaginator) / 3 (CursorPaginator) keys snake_case de paginación se agrupan bajo `__extraData.pagination`. Custom keys (audit_checked, request_id, etc.) siguen planas. **BC break clean** sin flag opt-in. Consumers v1.7.x que lean `response.__extraData.last_page` flat deben migrar a `response.__extraData.pagination.last_page`. Ver [CHANGELOG.md `## [v1.8.0]`](CHANGELOG.md) + `docs/UPGRADE_1.7_1.8.md` (migration guide completo con snippets PHP+TS BEFORE/AFTER).
>
> **v1.7.1-rc1 (post-fase 12 RETO feedback, 2026-06-28)** — 3 fixes pineados en este release:
>
> - **PKG-NEW-17 (HIGH) — Scaffolder `MakeAuthUserCommand` ya NO emite los placeholders `{{moduleNameLower}}` / `{{moduleNamePluralLower}}` literales en strings PHP dinámicos** (register + verify-email routes). Causa raíz: `generateStub()` solo aplica `str_replace` a los stubs, NO a los replacement values. Runtime symptom pre-fix: HTTP 500 `Auth guard [{{moduleNameLower}}] is not defined.` en `POST /api/{scope}/auth/register`. Fix: PHP interpolation `{$scopeLower}` / `{$scopePlural}` en todos los strings dinámicos + cambio de `<<<'PHP'` (NOWDOC) a `<<<"PHP"` (heredoc con interpolation) en el array de verify replacements. Solution of root: bug class completo pineado (incluye verify routes, no solo el register reportado por RETO). Consumer ya NO necesita el workaround `sed` (R-AD-020).
> - **PKG-NEW-18 (MEDIUM) — `BaseController::extractPaginationMetadata()` ahora incluye `has_more_pages` (boolean) para `LengthAwarePaginator`**. Antes solo emitía 4 keys (`current_page, last_page, per_page, total`). `ListManager::getExtraData()` ya emitía las 5 — drift entre 2 helpers del mismo paquete, fixed. `CursorPaginator` NO emite `has_more_pages` (no tiene el método). `@makroz/core` `MkListResponse<T>.__extraData` type YA pineaba `has_more_pages?: boolean` — NO requiere cross-stack update.
> - **BUG-NEW-auto-discover-serve (CRITICAL) — `MK_AUTO_DISCOVER_ABILITIES=true` + `php artisan serve` ya NO bricked dev server**. Fix: skip argv para long-running CLI contexts + `Artisan::call()` en lugar del malformed `$this->app->call(Class, params)`. Consumer ya NO necesita comentar el flag en `.env` (R-AD-021).
>
> Ver [CHANGELOG.md `## [v1.7.1-rc1]`](CHANGELOG.md) para el detalle completo + sprint `makromania/260628-2030--pkg-new-17-18-and-bug-auto-discover-serve` (PR #40 mergeado a dev 2026-06-29).

### 1.4. `__extraData` opt-in para paginators (HALLAZGO-NEW-FASE14-02)

**Regla canónica**: el campo `__extraData` se emite **SOLO** cuando el endpoint retorna un paginator (`AbstractPaginator` o `CursorPaginator`). Para endpoints no paginados, el envelope canónico es el simple `{success, message, data, debugMsg}` — **sin** `__extraData`.

Esta es la tabla resumen de qué endpoints emiten `__extraData` y cuáles no, pineada a partir del feedback RETO fase 14 (2026-06-29):

| Endpoint | ¿Emite `__extraData`? | Forma de `data` | Notas |
|---|---|---|---|
| `POST /api/{scope}/auth/login` | NO | `{access_token, refresh_token, token_type, expires_in, admin}` | Login scaffolded |
| `POST /api/{scope}/auth/refresh` | NO | `{access_token, refresh_token, token_type, expires_in}` | Refresh scaffolded |
| `POST /api/{scope}/auth/logout` | NO | `true` | Logout scaffolded (usa `AuthUser::safeLogoutCurrentToken()`) |
| `POST /api/{scope}/auth/register` | NO | subset del user model | Register scaffoldeado |
| `POST /api/{scope}/auth/forgot` | NO | `null` | Forgot scaffolded |
| `POST /api/{scope}/auth/reset` | NO | `true` | Reset scaffolded |
| `GET /api/{scope}/auth/me` | NO | user model (con `roles[].abilities[]`) | Me endpoint |
| `GET /api/{scope}/{resource}` (paginated) | **SÍ** | `data: [...items...]` + `__extraData.pagination: {...}` | Collection paginada — **único caso** que agrupa |
| `GET /api/{scope}/{resource}/{id}` | NO | user model (resource) | Show endpoint |
| `POST /api/{scope}/{resource}` | NO | model creado (resource) | Store endpoint |
| `PUT/PATCH /api/{scope}/{resource}/{id}` | NO | model actualizado (resource) | Update endpoint |
| `DELETE /api/{scope}/{resource}/{id}` | NO | `true` | Destroy endpoint — ver §1.5 (REST conventions) |

**Custom keys del consumer** (`audit_checked`, `request_id`, etc.) — viven planas en `__extraData` cuando este se emite (i.e. en endpoints paginados). NO se anidan bajo `__extraData.pagination`.

**Reglas binding**:
- `__extraData` es **opt-in** — SOLO paginators lo emiten.
- `__extraData.pagination` es grouping (R-PKG-032, v1.8.0+) — keys snake_case dentro.
- Endpoints no paginados NUNCA emiten `__extraData` (ni siquiera un objeto vacío `{}`).
- Si tu consumer necesita `__extraData` en un endpoint no paginado (e.g. agregar `audit_checked` al login), usa el parámetro `$extra` del helper `sendResponse()`: `return $this->sendResponse($result, '', 200, ['audit_checked' => true])`.

**Spec**: HALLAZGO-NEW-FASE14-02, feedback RETO fase 14 (2026-06-29).

---

### 1.5. REST conventions — DELETE 200+body vs 204 No Content (HALLAZGO-NEW-FASE14-04)

El paquete usa **HTTP 200 con envelope canónico** para TODOS los endpoints de mutación (DELETE / PUT / PATCH), incluido DELETE. Esto es decisión de diseño deliberada para mantener consistencia con el envelope JSON canónico del paquete (ver §1 arriba).

| Endpoint | HTTP status | Body | Por qué |
|---|---|---|---|
| `DELETE /api/{scope}/{resource}/{id}` | **200** | `{success: true, message: "...", data: true, debugMsg: []}` (envelope canónico) | Consistencia con envelope — 200 con body confirma éxito + audit message. El campo `data: true` es el "ack" semántico. |
| (REST-canonical alternativo) | 204 | (sin body) | Más REST-strict pero rompe la consistencia del envelope canónico |

**¿Por qué 200+body en vez de 204?**

El envelope canónico del paquete siempre incluye `success`, `message`, `data`, `debugMsg`. Forzar 204 sin body rompería esta consistencia (clientes que esperan el envelope fallarían al parsear). Adicionalmente, 200 con `data: true` permite que el front distinga "borrado OK" de un "idempotent no-op" (e.g. si el ID no existía → `success: false` con `message: "Resource not found"`).

**Workaround del consumer** (si querés aceptar ambos en tests, e.g. RETO fase 14):

```php
// En vez de assertNoContent() (estricto 204)
expect($response->status())->toBeIn([200, 204]);
expect(Model::find($id))->toBeNull();  // verificación real (la fuente de verdad)
```

**Opt-in a 204 No Content**: NO soportado en v1.8.x. Si tu consumer lo requiere explícitamente, override el método `destroy()` en tu controller scaffoldeado y retornar `response()->noContent()`. Considerar soporte nativo en v1.9.0 si hay demanda concreta (RETO no lo requiere, ningún otro consumer público todavía).

**Spec**: HALLAZGO-NEW-FASE14-04, feedback RETO fase 14 (2026-06-29).

### 1.7. Modelos globales vs per-scope y tablas compartidas (R-PKG-035)

Decisión arquitectónica pineada en R-MK-001 (MME): algunos modelos son **per-scope** (viven en `App\Modules\{Scope}\Models`), otros son **globales del paquete** (compartidos por todos los scopes). Pregunta recurrente de consumers: "¿por qué existen estas tablas y cómo identifican a qué scope pertenecen?". Tabla canónica:

| Tabla | Filas por scope | Polimórfica | Quién la usa | Por qué existe |
|---|---|---|---|---|
| `users` | 0 (Laravel default) | — | Laravel | Default migration de `0001_01_01_000000_create_users_table.php`. **NO la usa el paquete** — la mantenemos por BC con defaults Laravel (no romper consumers que esperan la tabla). |
| `auth_users` | 0 (vacía siempre) | — | Base `AuthUser::$table = 'auth_users'` | Default `$table` del modelo abstracto `Mk\Director\Auth\Models\AuthUser`. **Las subclases (Admin, Member, etc.) DEBEN sobreescribirla con `protected $table = '{scope}s'`** vía scaffolder. Si una subclase NO override, **v1.8.3-rc0+ `AuthUser::boot()` defensivo lanza `LogicException`** con mensaje accionable. Antes de v1.8.3, drift footgun: el modelo intentaba usar `auth_users` (tabla vacía) → queries de login/me/refresh retornaban `null` silenciosamente. **Ahora error explícito en runtime**. |
| `{scope}s` (e.g. `admins`, `members`, `customers`) | Una fila por user | — | Modelo del scope (`Admin`, `Member`, `Customer`) | **Per-scope**. Cada scope es un bounded context autocontenido (R-MK-001). Cross-scope data leak es estructuralmente imposible. |
| `roles` | Global (4 default) | — | `Mk\Director\Auth\Models\Role` | **Global del paquete** — compartida por todos los scopes. NO crear `App\Modules\Admin\Models\Role`. Usá los globales vía `use Mk\Director\Auth\Models\Role`. Lleva `is_fixed` (`FixedStatus` enum: `0` Editable / `1` Fixed); el rol `super-admin` se siembra Fixed. |
| `abilities` | Global (~16 default) | — | `Mk\Director\Auth\Models\Ability` | **Global del paquete** — compartida por todos los scopes. Misma justificación que `roles`. Lleva `is_fixed` (`FixedStatus` enum: `0` Editable / `1` Fixed); la ability wildcard `*` se siembra Fixed. |
| `role_user` | Polimórfica | ✅ `(role_id, user_id, user_type)` con UNIQUE index | Pivot `MkRoleUserPivot` | **Polimórfica**. `user_type = 'App\Modules\Admin\Models\Admin'` (auto-set por `MkBelongsToMany::from()`). Permite que un admin y un member tengan roles independientes sin colisión. **Cross-scope data leak imposible**. |
| `ability_user` | Polimórfica | ✅ `(ability_id, user_id, user_type)` con UNIQUE index | Pivot `MkAbilityUserPivot` | **Polimórfica** (idéntica justificación a `role_user`). `user_type` discrimina el scope. |
| `ability_role` | Global (no polimórfica) | ❌ `(ability_id, role_id)` | Tabla de abilities del paquete | **NO polimórfica — y está BIEN**. Las abilities son globales del paquete, los roles son globales del paquete, por lo tanto el vínculo `ability ↔ role` también es global. La dimensión que cambia por scope es el `user` (cada scope tiene su tabla), y eso lo resuelve `user_type` en `role_user`/`ability_user`. |
| `personal_access_tokens` | Sanctum v4 | — | `Laravel\Sanctum\PersonalAccessToken` | Sanctum tokens. Si tu scope usa `HasUuids`, aplicá `php artisan mk:fix:sanctum-uuids` para parchear `morphs` → `uuidMorphs`. |

**Implicancia práctica**:
- Cuando agregues un scope nuevo (e.g. `Member` con `php artisan mk:make:auth-user Member`), el scaffolder crea la tabla `members` + asigna roles via la pivot polimórfica `role_user` con `user_type = 'App\Modules\Member\Models\Member'`. Las roles existentes (4 default: `super-admin`, `admin`, `editor`, `viewer`) son compartidas con scope Admin — un `member` puede tener el rol `viewer` si se lo asignás. Si querés segregar abilities por scope visualmente, usá convención de naming (`admin.admins.view`, `member.profiles.edit`).
- `AuthUser::boot()` defensivo (R-PKG-035, v1.8.3-rc0+) — si tu scope NO override `protected $table`, lanza error explícito en lugar de usar `auth_users` silenciosamente. Ver § 1.6 de este DEVELOPER_GUIDE para el detalle.
- **`is_fixed` (FixedStatus enum)** — ambas tablas globales `roles` y `abilities` llevan la columna `is_fixed` (`unsignedTinyInteger`, default `0`), casteada al enum int-backed `Mk\Director\Auth\Enums\FixedStatus` (`0` = Editable, `1` = Fixed). El rol `super-admin` y la ability wildcard `*` se siembran con `is_fixed = 1` (`mk:auth:create-super-admin` los pinea, y el `{Scope}RolesSeeder` scaffoldeado los marca Fixed). Semántica: una fila Fixed es de sistema y **NO debe editarse ni eliminarse desde el CRUD** — trátala como read-only en frontend/policies. Los `RoleResource`/`AbilityResource` exponen el valor numérico (`->value`, contrato API).

**Cross-ref**: R-MK-001 (MME), R-PKG-021 + R-PKG-022 (polimorfismo triple defensa), R-PKG-035 (DB defensive + helper público).

### 1.8. CORS para Sanctum cookie mode futuro (R-PKG-035 + HALLAZGO-NEW-FASE15-09)

Hoy el paquete funciona con Bearer tokens via `Authorization` header (Laravel default CORS OK). El `@makroz/web` + `@makroz/mobile` están pineando cookies httpOnly como storage backend (`CookieStorageAdapter` + `SecureStore`), pero el flujo actual sigue siendo Bearer header.

**Cuando migres a Sanctum v4 cookie mode** (cookies httpOnly emitidos por el server, browser los manda automáticamente):
- Laravel default `HandleCors` middleware NO incluye `Access-Control-Allow-Credentials: true` + el origin debe ser específico (NO `*`). Si no lo configurás, el browser bloquea la response.

**Snippet para `config/cors.php`** (futuro, post-v1.8.3 cuando bumpees a Sanctum cookie mode):
```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => explode(',', env('FRONTEND_ORIGINS', 'http://localhost:3000')),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,  // ← CRÍTICO para cookies httpOnly cross-origin
];
```

**Variables de entorno necesarias** (`.env`):
```bash
FRONTEND_ORIGINS="http://localhost:3000,https://admin.tu-dominio.com"
SANCTUM_STATEFUL_DOMAINS="localhost:3000,admin.tu-dominio.com"
SESSION_DOMAIN=".tu-dominio.com"
```

**Por qué NO está pineado por default en el scaffolder**: Bearer flow funciona perfectamente con defaults. Cookie mode es decisión del consumer (YAGNI pinearlo si no se usa). El scaffolder `mk:make:auth-user --with-cookie-mode` (futuro) podría pinearlo automáticamente si Mario lo aprueba en un sprint próximo.

**Cross-ref**: HALLAZGO-NEW-FASE15-09 (R-PKG-035), `mk-director-web/SKILL.md` § CookieStorageAdapter, `mk-director-mobile/SKILL.md` § SecureStore.

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

#### 3.6.5. Compatibilidad con `RefreshDatabase` testing (HALLAZGO-NEW-FASE14-01, v1.8.1+)

Cuando `MK_AUTO_DISCOVER_ABILITIES=true`, el hook de boot corre `mk:discover-abilities --force` durante el boot del `MkServiceProvider`. En testing con `RefreshDatabase`, las migrations del paquete (`loadMigrationsFrom`) corren **DESPUÉS** del boot del framework → la tabla `abilities` (o `{scope}_abilities`) todavía no existe cuando auto-discover intenta ejecutarse.

**Síntoma pre-v1.8.1** (RETO fase 14 feedback):

```
RuntimeException: Ninguna tabla de abilities existe.
Esperaba 'admins_abilities' o 'abilities'.
¿Corriste `php artisan migrate` después de scaffoldear?
```

**Fix pineado en v1.8.1+** (HALLAZGO-NEW-FASE14-01): el boot hook ahora chequea `Schema::hasTable($abilitiesTable)` antes de invocar `mk:discover-abilities`. Si la tabla no existe aún (caso testing con `RefreshDatabase`), skip con `Log::debug(...)` y `return` — sin `RuntimeException`.

**En producción** (`php artisan serve`, octane, queue:work): las migrations se ejecutan **ANTES** del boot del service provider → la tabla existe → el guard es **no-op** (1 check de schema por boot, costo despreciable).

**Workaround pre-v1.8.1** (ya NO necesario, RETO fase 14 lo pineó):

```xml
<!-- phpunit.xml -->
<env name="MK_AUTO_DISCOVER_ABILITIES" value="false"/>
```

```php
// tests/Feature/Auth/AuthFlowTest.php
beforeEach(function () {
    $this->artisan('mk:discover-abilities', ['--force' => true])->assertSuccessful();
    $this->artisan('mk:auth:create-super-admin', [...])->assertSuccessful();
});
```

**Por qué `Log::debug` y no `Log::warning`**: el skip es un caso esperado en testing environments, no un error. Logging at warning level llenaría los logs del consumer con ruido innecesario.

**Spec**: HALLAZGO-NEW-FASE14-01, feedback RETO fase 14 (2026-06-29). Cross-ref: R-PKG-007 D4 (auto-discover en boot), BUG-NEW-auto-discover-serve (skip long-running CLI contexts).

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
   Route::post('password/forgot', [AuthController::class, 'forgotPassword'])
       ->middleware('throttle:' . config('mk_director.auth.rate_limits.forgot', '3,1'));
   Route::post('password/reset', [AuthController::class, 'resetPassword'])
       ->middleware('throttle:' . config('mk_director.auth.rate_limits.reset', '3,1'));
   ```

   > **F10-B06 (RETO corrida 10)**: el stub llegó a apuntar `forgot`/`reset` a métodos
   > inexistentes en `BaseAuthController` (la clase real expone `forgotPassword()`/
   > `resetPassword()`) → 500 `Call to undefined method`. Fijo desde entonces: los
   > paths son `password/forgot`/`password/reset` y los métodos son
   > `forgotPassword`/`resetPassword` (los config keys `rate_limits.forgot`/`.reset`
   > NO cambiaron, solo el path/método de la ruta). El stub también expone ahora
   > `logout-all` (`logoutAll()`) y `password/change` (`changePassword()`), previamente
   > implementados en la base pero nunca rutados.

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



**Testing gotcha — guard cache reset (HALLAZGO-NEW-FASE14-03, v1.8.1+)**:

En testing Pest/PHPUnit, todas las requests dentro del mismo test comparten el container PHP. Sanctum cachea el user resuelto en `Auth::guard($scope)` durante el lifecycle del container.

Si testeás `POST /api/admin/auth/logout` y después intentás `GET /api/admin/auth/me` con el mismo Bearer token (ahora revocado), el cache del guard devuelve el user authed → el test falla con 200 en vez del 401 esperado.

**Fix pineado en v1.8.1+** (HALLAZGO-NEW-FASE14-03): `safeLogoutCurrentToken()` ahora llama `\Auth::forgetGuards()` post `$token->delete()`. Esto invalida el cache del guard en el mismo process.

**En producción** (cada HTTP request = PHP process fresco): `\Auth::forgetGuards()` es **no-op** (no hay guards cacheados en un process que arranca de cero). El fix es transparente para consumidores production.

**Workaround pre-v1.8.1** (ya NO necesario):

```php
// Después del logout en el test
\Auth::forgetGuards();

// Verificar que el token está revocado
$reuse = $this->withHeaders(['Authorization' => "Bearer {$accessToken}"])->getJson('/api/admin/auth/me');
$reuse->assertStatus(401);
```

**Spec**: HALLAZGO-NEW-FASE14-03, feedback RETO fase 14 (2026-06-29). Cross-ref: §3.8.2 (is_active check).

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

**Si override `login()`/`forgotPassword()`/`resetPassword()` manualmente** en tu consumer, mantené la misma lógica de `Schema::hasColumn` + `=== false` para no romper el patrón.

#### 3.8.3. Ability checks — `canMk()` vs `can()` vs `hasAbility()` (HALLAZGO-NEW-FASE14-06)

El trait `HasAbilities` (en `Mk\Director\Auth\Concerns\HasAbilities`) expone **`canMk(string $ability): bool`** como método canónico para chequear abilities. **NO expone `can()` ni `hasAbility()`**.

| Método | ¿Existe? | ¿Qué hace? |
|---|---|---|
| `$user->canMk('admin.admins.view')` | ✅ **Canónico (paquete)** | Consulta `UNION ALL` de abilities directas + abilities via roles. Cache via `AbilityResolver`. |
| `$user->can('admin.admins.view')` | ❌ NO es del paquete | `can()` es de Laravel `AuthorizesRequests` (policy-based). Llamarlo con ability name como argumento falla con `Call to undefined method` o `SQLSTATE HY000` (columna inválida). |
| `$user->hasAbility('admin.admins.view')` | ❌ Inexistente | No existe método con ese nombre. Solo `canMk()`. |

**Ejemplo correcto**:

```php
// ✅ Correcto (paquete):
if ($admin->canMk('admin.admins.view')) {
    // allowed
}

// ❌ Incorrecto (NO existe en paquete):
if ($admin->can('admin.admins.view')) {       // Laravel Gate — no del paquete
    // ...
}

if ($admin->hasAbility('admin.admins.view')) { // Inexistente
    // ...
}
```

**Por qué `canMk` y no `can`**: el trait evita colisión con Laravel `AuthorizesRequests::can()` (que es para policy methods, e.g. `$user->can('update', $post)`). `canMk` es específico del paquete y consulta via `AbilityResolver` con cache (union subquery cross-engine portable MySQL/MariaDB/PostgreSQL/SQLite).

**Workaround del consumer** (ya NO necesario post-v1.8.1 doc): si tu consumer usa `can()` esperando Laravel Gate behavior, fallará con `Call to undefined method Admin::can()` (PHP error) o `SQLSTATE HY000` con columna inválida (DB error). El paquete solo expone `canMk` — usá ese.

**Matiz importante — `hasAbility()` SÍ existe, pero en el otro pack**: la tabla de arriba habla del trait `HasAbilities`, que usan los modelos generados por `mk:make:auth-user` (extienden `AuthUser`). El pack `mk:module --with-rbac` es un mundo aparte: genera un modelo que extiende `Illuminate\Foundation\Auth\User` (decisión D2), con su propio `Role` módulo-local, sus propias pivots, y **su propio `hasAbility()` definido a mano**. Ahí `hasAbility()` es lo correcto y `canMk()` no existe.

| Pack | Modelo base | Método de abilities | Modelo `Role` |
|---|---|---|---|
| `mk:make:auth-user [--with-crud]` | `Mk\Director\Auth\Models\AuthUser` | **`canMk()`** | Central del paquete |
| `mk:module --with-rbac` | `Illuminate\Foundation\Auth\User` | **`hasAbility()`** | Módulo-local |

No son intercambiables, y el error clásico es copiar una Policy de un pack al otro: compila, pasa el lint, y muere en runtime con `BadMethodCallException` la primera vez que alguien la invoca. Regresión real: hasta v1.9.x el generador de `--with-crud` reusaba `module-rbac/policy-user.stub`, así que TODAS las policies de ese pack salían llamando a `hasAbility()`. Nadie lo notó porque el gate efectivo lo hace el middleware `mk.ability:` y las policies quedaban como código muerto. Ver §3.8.4.

**Spec**: HALLAZGO-NEW-FASE14-06, feedback RETO fase 14 (2026-06-29). Cross-ref: §3.8 RBAC integration, §3.8.4 (policies sobre modelos centrales), `references/04-auth-flow.md` (HasAbilities trait detail).

#### 3.8.3-bis. 🔴 Las Policies generadas NO corren solas — hay que prenderlas

**Éste es el matiz más importante de toda la sección, y hasta ahora no estaba escrito en ningún lado.**

`Gate::policy(Modelo::class, Policy::class)` en el ServiceProvider **registra** la policy. Quien tiene que **invocarla** es `CRUDSmart`, y hasta la versión que introdujo `authorize_with_policy` **no la invocaba nunca**: no había una sola referencia a `Gate`, `authorize()` ni `can()` en todo el trait.

O sea: `--with-crud` generaba una Policy, la registraba, la documentaba, y el archivo quedaba como **código muerto**. Medido en el piloto NetPizza — con la Policy devolviendo `false` en **todos** sus métodos, `before()` incluido, los nueve tests del backoffice seguían en verde. La única autorización real era el `mk.ability:` de la ruta.

**Cómo prenderla:**

```php
// config/mk_director.php — global
'features' => ['authorize_with_policy' => true],

// o por controller, que gana sobre el global EN LOS DOS SENTIDOS
protected array $mkConfig = [
    'model' => Producto::class,
    'features' => ['authorize_with_policy' => true],
];
```

Con eso, `CRUDSmart` autoriza así: `index→viewAny`, `show→view`, `store→create`, `update→update`, `destroy→delete`. Los de fila (`show`/`update`/`destroy`) autorizan **después** del `findOrFail`, así una fila ajena da 404 y no 403 — un 403 confirmaría que ese id existe en algún lado.

**El default es `false`, y no es timidez.** Una Policy que nunca corrió es una Policy que **nunca se probó**: prenderla de golpe en un `composer update` convierte 200 en 403 sin que nadie lo haya pedido, o directamente en 500. En NetPizza, la primera vez que la Policy del scope gestionado se ejecutó tiró `TypeError: Argument #1 ($user) must be of type Mesero, Admin given` — porque el CRUD de un scope `consumer` lo rutea el **manager**, y la Policy tipaba el consumer.

**Lo que sí es seguro por construcción**: un modelo **sin** Policy registrada no se ve afectado por el toggle. Sin esa condición, prenderlo cerraría el CRUD entero de todo consumer sin Policies — `Gate::authorize()` sin policy cae en las abilities sueltas del Gate, no encuentra ninguna, y **deniega**.

##### 🔴 Cómo probar que tu Policy corre (el test obvio no sirve)

Dos tests que quedan **en verde con la Policy desconectada**, o sea que no miden nada:

- Un test unitario de la Policy (`(new ProductoPolicy)->viewAny($user)`). Claro que pasa: la Policy funciona perfecto; lo que falta es quien la llame.
- Un request con un usuario autorizado que espera 200. Quien lo deja pasar es el `mk.ability:` de la ruta.

Lo único que discrimina es la aserción **al revés**, por HTTP:

```php
Gate::policy(Producto::class, PolicyQueNiegaTodo::class);

$this->withHeaders($cabeceras)->getJson('/api/productos')->assertForbidden();
```

Si eso da **200**, el enganche no está.

##### ⚠️ Si lo enganchás a mano: `Gate::authorize()` a secas NO sirve

El Gate resuelve el usuario por el guard **por defecto** (`web`); a tu usuario lo autenticó `mk.auth:{scope}`, que es otro. Sin `Gate::forUser($request->user())` el Gate ve `null` y **todo** da 403 — una defensa que no distingue nada, y que devuelve **el mismo código HTTP** que la implementación correcta, así que el síntoma no la delata. Al escribir el test, afirmá **qué usuario llegó** a la Policy, no sólo el status.

##### Qué va en la Policy y qué va en la ruta

No es redundancia. El middleware `mk.ability:` pregunta *"¿tenés el permiso?"* **antes** de saber sobre qué fila. La Policy recibe **la fila**, que es donde va la regla que el middleware no puede expresar: *"el encargado edita sólo lo de su sucursal"*, *"nadie se borra a sí mismo"*.

##### ⚠️ Sólo cubre los cinco verbos heredados

Los métodos propios de tu controller (`assignAccess`, `syncAbilities`, `resetPassword`, …) **no** pasan por acá: siguen defendidos únicamente por su `mk.ability:` de ruta. Si una Policy tiene que opinar sobre uno de ellos, el `authorize` va escrito a mano adentro del método.

#### 3.8.4. Policies sobre modelos centrales (`Role` / `Ability`) — colisión entre scopes

`Mk\Director\Auth\Models\Role` y `Ability` son **una sola clase compartida por todos los scopes**. `Gate::policy()` mapea clase → policy, así que dos scopes manager que registren la misma clase **no conviven**:

```php
// AdminServiceProvider::boot()
Gate::policy(\Mk\Director\Auth\Models\Role::class, \App\Modules\Admin\Policies\RolePolicy::class);

// MemberServiceProvider::boot()  ← bootea después: GANA
Gate::policy(\Mk\Director\Auth\Models\Role::class, \App\Modules\Member\Policies\RolePolicy::class);
```

Gana el provider que bootea último según `bootstrap/providers.php`. Y no es un simple "la otra policy no corre": el `before()` de la policy ganadora typehintea SU modelo (`Member $user`), así que un `Admin` pasando por ese gate entra como tipo incompatible → **`TypeError`, no un deny**.

**No es resoluble desde el Gate.** Con `Role::class` a secas no hay información para saber si el actor es Admin o Member. Cualquier "arreglo" a ese nivel es un workaround contra la arquitectura.

**El gate scope-aware es el middleware `mk.ability:`** — la ability lleva el scope en el nombre (`admin.roles.viewAny` vs `member.roles.viewAny`), así que discrimina sin ambigüedad. Es el que ya protege las rutas generadas.

**Qué hace el generador**: desde v1.9.x, `mk:make:auth-user --with-crud` detecta si otro scope ya registró Policy sobre los modelos centrales (`centralPolicyOwner()`). Si lo hay, **cede el registro al primero y avisa por consola**. Las Policies se generan igual — podés invocarlas directo (`new RolePolicy)->viewAny($user)`) — pero no se registran en el Gate.

**Recomendación para el consumer**: si tenés un solo scope manager, no te afecta. Si tenés dos o más, no dependas de `Gate::authorize()` sobre `Role`/`Ability`: usá el middleware `mk.ability:` (que ya está en las rutas) y, si necesitás el chequeo en código, `$user->canMk('{scope}.roles.{action}')` directo. Reservá las Policies para los modelos **propios** de cada scope (`Admin`, `Member`, …), donde el mapeo clase → policy sí es unívoco.

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

### 3.14 R-PKG-043 RETO fase 19 feedback fixes (`--with-permissions-endpoint` scaffolder hardening + `.env.example` reference)

> **Sprint**: `makromania/260701-1030--r-pkg-043-fase19-feedback-fixes`.
> **Source**: RETO fase 19 clean rebuild sobre v1.8.5-rc0.
> **Cross-stack companion**: `@makroz/web` SKILL sync (R-PKG-043 docs batch — HALLAZGO-NEW-FASE19-04..07, 10).

#### 3.14.1 `MakeAuthUserCommand` ahora pinea `use ...\MePermissionsController;` (HALLAZGO-NEW-FASE19-01, HIGH)

Pre-R-PKG-043, el scaffolder pineaba el route `Route::get('me/permissions', [MePermissionsController::class, 'show'])` en `Http/Routes/api.php` pero **NO pineaba el `use App\Modules\{Scope}\Http\Controllers\MePermissionsController;`** en el bloque `use` statements al inicio del archivo.

**Síntoma runtime**: `ReflectionException: Class "MePermissionsController" does not exist` en `php artisan route:list` / `route:cache`. Bloqueante — el consumer no podía bootear el route:list out-of-the-box.

**Post-R-PKG-043 (3 estrategias defensivas)**:

1. **Primary**: si `Http/Routes/api.php` ya pineó `use App\Modules\{Scope}\Http\Controllers\AuthController;` (canónico del scaffolder), insertar el import de `MePermissionsController` justo después.
2. **Fallback**: si el consumer customizó el routes sin AuthController import, buscar el último `use ...;` antes del primer `Route::` y pinear después.
3. **Last resort**: pinear después del `<?php` inicial.

Idempotente: si el consumer ya pineó manualmente el import, el `str_replace` no duplica.

**Workaround consumer pre-bumpear** (RETO pino en fase 19):

```php
// app/Modules/Admin/Http/Routes/api.php
use App\Modules\Admin\Http\Controllers\AuthController;
use App\Modules\Admin\Http\Controllers\MePermissionsController;  // ← agregado manualmente
use Illuminate\Support\Facades\Route;
```

#### 3.14.2 Route `me/permissions` pineada DENTRO del prefix group (HALLAZGO-NEW-FASE19-02, HIGH)

Pre-R-PKG-043, el scaffolder pineaba el route con `file_put_contents` append al final del archivo `Http/Routes/api.php`. Sin el bloque `Route::prefix('api/{scope}/auth')->group(...)` que contiene el resto del auth flow (generado por `auth-user.routes.stub`), la ruta se registraba como **`GET /me/permissions`** (path absoluto en raíz) en vez de **`GET /api/{scope}/auth/me/permissions`**.

**Síntoma runtime**: `php artisan route:list` muestra `GET|HEAD me/permissions` en vez de `GET|HEAD api/admin/auth/me/permissions`. El frontend `@makroz/web` esperaba `/api/admin/auth/me/permissions` (canónico cross-stack) → 404 silent o 401 según middleware.

**Post-R-PKG-043**: el scaffolder ahora usa `preg_replace` con el pattern `Route::get\(\s*'me'\s*,\s*\[AuthController::class,\s*'me'\]` para pinear la línea **DESPUÉS** de `Route::get('me', [AuthController::class, 'me'])` que ya está dentro del bloque `Route::middleware('mk.auth:{scope}')...->group(...)`. Esto garantiza:

- **Path canónico**: `GET /api/{scope}/auth/me/permissions` (vía `Route::prefix` del stub).
- **Middleware canónico**: `mk.auth:{scope}` (vía `Route::middleware` del stub).
- **Consistencia con `/me` y `/logout`**: misma sintaxis que el resto del flow.

**Defense-in-depth fallback**: si el consumer customizó el routes y `Route::get('me', ...)` no se encuentra, se pinea el route con `Route::prefix('api/{scope}/auth')->middleware('mk.auth:{scope}')->group(function () {...})` explícito al final del archivo + warning al scaffolder output.

**Workaround consumer pre-bumpear** (RETO pino en fase 19):

```php
// app/Modules/Admin/Http/Routes/api.php — mover manualmente la route DENTRO del group
Route::middleware('mk.auth:admin')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('me/permissions', [MePermissionsController::class, 'show']);  // ← movido aquí
});
```

#### 3.14.3 Nuevo `.env.example` documentando env vars del paquete (HALLAZGO-NEW-FASE19-03, MEDIUM)

Pre-R-PKG-043, el scaffolder pineaba `config/cors.php` consumiendo `env('FRONTEND_ORIGINS', ...)` (HALLAZGO-NEW-FASE18-07) Y el `mk_director.frontend.*` config leía `env('MK_TENANT_*')`, `env('MK_AUTH_RATE_LIMIT_*')`, `env('MK_CACHE_*')`. PERO **NO había un `.env.example` en el paquete** — los consumers descubrían las env vars leyendo el config PHP (post-boot).

**Post-R-PKG-043**: `packagist/mk-director-laravel/.env.example` con todas las env vars en 9 secciones (Frontend CORS, Auth/Sanctum, Rate limiting, Cache, Multi-tenant, Module loader, List/Pagination, Debug, Cross-cutting). Cada var con default value + descripción + ejemplo dev/staging/prod.

```bash
# Comma-separated, sin espacios alrededor de comas.
FRONTEND_ORIGINS=http://localhost:3000,http://127.0.0.1:3000

# Sanctum SPA flow (cookie-based) requiere true. Bearer-only puede false.
FRONTEND_SUPPORTS_CREDENTIALS=true
```

**Conveniencia operacional**:

```bash
# El consumer puede listar todas las env vars disponibles:
grep -E '^[A-Z_]+=' vendor/makroz/director-laravel/.env.example

# O pinearlas al .env del consumer (cross-check):
diff <(grep -oE "^[A-Z_]+" .env | sort) \
     <(grep -oE "^[A-Z_]+" vendor/makroz/director-laravel/.env.example | sort)
```

**Workaround consumer pre-bumpear**: pinear `FRONTEND_ORIGINS` en `.env` manualmente vía `grep mk-director config/mk_director.php`. Ya documentado out-of-the-box post-bumpear.

---

### 3.15 S8 Fase 7 scaffolder hardening (HALLAZGO-NEW-FASE18-A/B/C — RETO fase 6 clean rebuild feedback)

> **Sprint**: `makromania/260704-1935--fase18-scaffolder-fixes`.
> **Source**: RETO fase 6 clean rebuild feedback (sprint `makromania/2026-07-04-1855--s8-fase6-admin-clean-rebuild`, commit `c2dd3f5`).
> **Acumula al lote RELEASE_AT_END**: Mario retiene tag GA + Packagist publish.
> **R-G-033**: BC break documentado (HALLAZGO-NEW-FASE18-C), autorizado por dogfooding-first (único consumer = RETO, migración en mismo sprint).

#### 3.15.1 HALLAZGO-NEW-FASE18-A — `forgot()` llaves extras en `AuthController.stub` (HIGH, parse error)

Pre-fix, el stub `src/Stubs/auth-user.auth-controller.stub` tenía 5 líneas huérfanas + 1 llave extra entre el early-return del anti-enumeration check (`if (! $user || ...)`) y la generación del token (`$token = bin2hex(random_bytes(32))`). El bloque huérfano era una copia near-duplicada del early-return.

**Síntoma runtime**: `php artisan route:list` (y `php -l` sobre el `AuthController.php` scaffoldeado) fallaba con `ParseError: syntax error, unexpected variable "$token", expecting "function"` en línea ~370. Bloqueante — el consumer no podía bootear el scaffolder out-of-the-box.

**Post-fix**: stub ahora tiene llaves balanceadas en `forgot()`. El early-return aparece una sola vez antes de la generación del token.

**Tests pineados** (regression guards en `tests/Unit/Scaffolders/HallazgoFase18AForgotBracesTest.php`):

- Source-parsing: `forgot()` body contiene exactamente 2 `$this->sendResponse(...)` calls (early-return + success-return). Pre-fix: 3 calls (extra huérfano).
- Source-parsing: balanced braces check (count `{` vs `}` después de strip strings + comments).
- Source-parsing: early-return position antes de `$token = bin2hex(...)`.

**Workaround consumer pre-bumpear** (RETO pino en fase 6, commit `c2dd3f5`):

```php
// app/Modules/Admin/Http/Controllers/AuthController.php — eliminar manualmente líneas ~363-367
        if (! $user || $user->getAuthScope() !== 'admin' || $isActiveCheck) {
            return $this->sendResponse(
                null,
                'Si el email existe, recibirás un enlace para reset.',
            );
        }
//        return $this->sendResponse(         ← ELIMINAR
//            null,                             ← ELIMINAR
//            'Si el email existe, ...',        ← ELIMINAR
//        );                                    ← ELIMINAR
//    }                                         ← ELIMINAR
```

#### 3.15.2 HALLAZGO-NEW-FASE18-B — `me/permissions` regression test guard (HIGH, HTTP 500)

Pre-R-PKG-043 (commit `99084ad`), `MakeAuthUserCommand::generatePermissionsEndpoint()` pineaba el route `Route::get('me/permissions', [MePermissionsController::class, 'show'])` al FINAL del archivo `Http/Routes/api.php` sin bloque `Route::prefix()->group(...)` ni middleware `mk.auth:{scope}`. R-PKG-043 HALLAZGO-NEW-FASE19-02 fix pineá el route DENTRO del bloque middleware existente (vía `preg_replace` sobre `Route::get('me', ...)`).

**Riesgo residual**: el fix depende del regex match sobre la línea canónica `Route::get('me', [AuthController::class, 'me'])`. Si el consumer customiza el routes y elimina/renombra esa línea, el scaffolder cae al fallback (líneas 2485-2492 del scaffolder) que pineá un SEGUNDO grupo con prefix+middleware explícito + warning al scaffolder output.

**Pre-R-PKG-043 fix**, el consumer (RETO) pineaba manualmente la route dentro del grupo (commit `c2dd3f5`).

**Tests pineados** (regression guards en `tests/Unit/Scaffolders/HallazgoFase18BPermissionsRouteTest.php`, 7 tests):

- Source-parsing: presencia de `Route::get('me/permissions', [MePermissionsController::class, 'show'])`.
- Source-parsing: anchor regex `Route::get\(\s*'me'\s*,` en `$meRoutePattern`.
- Source-parsing: `preg_replace` con `$1\n{routeLine}` placement.
- Source-parsing: primary `use` strategy (MePermissionsController + str_replace + AuthController anchor).
- Source-parsing: fallback path (Route::prefix + middleware group explícito).
- Source-parsing: fallback warning al consumer.
- Source-parsing: idempotency check (`str_contains` pre-injection).

#### 3.15.3 HALLAZGO-NEW-FASE18-C — `mk.ability` per-route en CRUD (HIGH, privilege escalation) — **BC BREAK**

Pre-fix, las 21 rutas CRUD scaffoldeadas por `--with-crud` pinean SOLO `mk.auth:{scope}` middleware a nivel de `Route::prefix(...)->middleware(...)->group(...)`. Las abilities per-action (`{scope}.{resource}.{action}`) NO se chequean — solo el RBAC del `AuthController` (`--with-auth-rbac`) cubre `/me`, `/logout`, `/login`, etc. CRUD endpoints son gateados por auth check ONLY.

**Síntoma runtime verificado en RETO** (sprint `makromania/2026-07-04-1855--s8-fase6-admin-clean-rebuild`): editor con abilities reducidas `[admin.admins.viewAny, admin.admins.view, admin.admins.update]` (sin `create`, sin `delete`) pudo ejecutar `POST /api/admins/{id}/roles` y escalar privilegios a `super-admin`. Cualquier user autenticado puede escalar privilegios en CRUD endpoints.

**Post-fix**: cada `Route::xxx` carga `->middleware(['mk.auth:{scope}', 'mk.ability:{scope}.{resource}.{action}'])` PER-ROUTE. Action mapping canónico (Laravel conventions):

| HTTP verb | Action del controller | Ability pineada |
|---|---|---|
| `GET /` | `index` | `{scope}.{resource}.viewAny` |
| `GET /{id}` | `show` | `{scope}.{resource}.view` |
| `POST /` | `store` | `{scope}.{resource}.create` |
| `PUT /{id}` | `update` | `{scope}.{resource}.update` |
| `PATCH /{id}` | `update` | `{scope}.{resource}.update` |
| `DELETE /{id}` | `destroy` | `{scope}.{resource}.delete` |
| `POST /{id}/roles` | `assignRoles` | `{scope}.{resource}.update` |
| `POST /{id}/abilities` | `assignDirectAbilities` | `{scope}.{resource}.update` |
| `PUT /{role}/abilities` (Roles) | `syncAbilities` | `{scope}.roles.update` |

Rutas de **Roles** (prefix `api/roles`): usan `{scope}.roles.{action}`.
Rutas de **Abilities** (prefix `api/abilities`): usan `{scope}.abilities.{action}`.

**BREAKING CHANGE** (R-G-033 autoriza):

- **Consumers con abilities pre-pineadas por-resource** (canónico si usaste `mk:discover-abilities` o pineaste manualmente abilities `{scope}.{resource}.{action}`): NO se rompen. Las rutas ahora chequean abilities que ya existen en DB.
- **Consumers SIN abilities pre-pineadas** (raro — el patrón canónico es `mk:discover-abilities` post-scaffolder): **TODAS las rutas CRUD devuelven HTTP 403** post-bumpear. Fix: `php artisan mk:discover-abilities --force` ANTES de bumpear.

**Workaround consumer pre-bumpear** (RETO pino en fase 6):

```php
// Opción 1: discover abilities ANTES de bumpear
php artisan mk:discover-abilities --force

// Opción 2: si no podés bumpear el paquete todavía, override los routes
// app/Modules/Admin/Http/Routes/api.php — agregar mk.ability per-route manualmente
Route::prefix('api/admins')->group(function () {
    Route::middleware(['mk.auth:admin', 'mk.ability:admin.admins.viewAny'])
        ->get('/',         [AdminController::class, 'index']);
    // ... etc para cada Route::xxx
});
```

**Tests pineados** (regression guards en `tests/Unit/Scaffolders/HallazgoFase18CCrudAbilityPerRouteTest.php`, 8 tests):

- Source-parsing: NO group-level `->middleware('mk.auth:{scope}')->group(...)` (defense-in-depth: ability check per-route, not group).
- Source-parsing: presencia de las 5 actions CRUD canónicas (`viewAny`, `view`, `create`, `update`, `delete`) en el resource group.
- Source-parsing: `assignRoles` + `assignDirectAbilities` requieren `update` ability (escalation guard).
- Source-parsing: roles routes usan `{scope}.roles.{action}` ability.
- Source-parsing: abilities routes usan `{scope}.abilities.{action}` ability.
- Source-parsing: count consistency — N middleware blocks === N verb routes (no routes sin middleware).
- Source-parsing: BC break documentado inline (HALLAZGO-NEW-FASE18-C + BC BREAK + `mk:discover-abilities --force`).

#### Spec

- Sprint: `makromania/260704-1935--fase18-scaffolder-fixes`.
- Tests: 18 nuevos Pest tests (3 + 7 + 8 across HALLAZGO-A/B/C). Total paquete: **536 passing, 0 failing**.

### 3.16 FEEDBACK7 — Scaffolder fixes (B01/B02/B03/W03 — RETO fase 19 feedback)

> **Sprint**: `makromania/260709-1000--feedback7-fixes-batch` (acumula al lote RELEASE_AT_END).
> **R-G-033**: BC-safe (todos additive). Mario retiene tag + publish.
> **Source**: `FEEDBACK7.md` (10 hallazgos: 4 🟠 + 6 🟡).
> **Tests**: 7 nuevos Pest tests en `tests/Unit/Scaffolders/Feedback7FixesTest.php`.

#### 3.16.1 F7-B01 — `--with-crud/--with-auth-rbac` auto-setup Sanctum PAT (silent 500)

**Síntoma pre-fix**: tras scaffoldear el scope Admin con `--with-crud --with-auth-rbac` y correr `migrate`, el primer `POST /api/admin/auth/login` reventaba con 500 silencioso al emitir el Bearer porque **no existía la tabla `personal_access_tokens`**. El scaffolder creaba `admins`, `admin_password_reset_tokens`, roles/abilities, etc., pero NO la tabla de tokens. El output "Siguientes pasos" solo mencionaba `migrate`, abilities y overrides opcionales — nunca Sanctum.

**Workaround pre-fix** (RETO fase 4):
```bash
php artisan vendor:publish --tag=sanctum-migrations
php artisan mk:fix:sanctum-uuids   # parchea a uuidMorphs (los modelos usan HasUuids)
php artisan migrate
```

**Fix**: cuando se pasa `--with-crud` o `--with-auth-rbac` (scopes que SÍ emiten tokens Sanctum), el scaffolder auto-invoca `vendor:publish --tag=sanctum-migrations` + `mk:fix:sanctum-uuids` sin requerir el flag `--setup-sanctum` explícito.

```php
// MakeAuthUserCommand.php handle() — justo después de $setupSanctum resolution
$emitsTokens = $withAuthRbac || $withCrud;
$setupSanctum = $setupSanctum || $emitsTokens;
```

**Output loud** cuando auto-activa (post-fix, RETO feedback 7 §1):
```
🔐 F7-B01: Sanctum PAT table setup automático.
   El scaffolder publicó y parcheó la migration de Sanctum (--setup-sanctum implícito).
   Para que el login funcione, corré `php artisan migrate` antes del primer /login.
```

**BC preservado**: el flag `--setup-sanctum` sigue funcionando como opt-in para scopes sin tokens (sin `--with-crud` ni `--with-auth-rbac`). Default mode: idéntico a v2.0.0.

**Idempotente**: si la migration de Sanctum ya está publicada, solo la parchea. Si ya está parcheada (uuidMorphs), no hace nada.

#### 3.16.2 F7-B02 — `me()` y `login()` pinean `abilities: string[]` flat en el response

**Síntoma pre-fix**: el user object de `admin` (login + `/me`) traía `abilities` como array plano de strings — contrato de `hasAbility` que `useMkAuth()` consume. Pero el de `member` (scope sin `--with-crud`, usa el AuthController scaffoldeado default) traía `roles: []` + `direct_abilities: []` pero **NO** la key `abilities`. Para un login+welcome no molestaba, pero rompía `useMkAuth().hasAbility(...)` en mobile/web si el member la usara.

**Fix**: el AuthController scaffoldeado ahora pinea `abilities: $user->getEffectiveAbilities()` ad-hoc en `me()` y `login()`, replicando lo que el `{Scope}Resource` scaffoldeado (con `--with-crud`) ya hacía. Aplica a TODO scope (con o sin `--with-crud`).

```php
// auth-user.auth-controller.stub — me() post-fix
public function me(Request $request): JsonResponse
{
    $user = $request->user();
    $user->loadMissing(['roles', 'directAbilities']);
    // F7-B02: pinear `abilities` flat top-level en el response
    $payload = $user->toArray();
    $payload['abilities'] = $user->getEffectiveAbilities();
    return $this->sendResponse($payload);
}
```

**Defense-in-depth**: el `{Scope}Resource` scaffoldeado (`--with-crud`) ya pineaba esto (R-PKG-035 HALLAZGO-NEW-FASE15-06, post-v1.8.3-rc0). El fix F7-B02 es la versión "sin Resource scaffoldeado" — el `AuthController` pinea `abilities` ad-hoc para que el shape sea consistente entre ambos tipos de scope.

**Parity cross-stack**: el shape `data.user.abilities: string[]` ahora aparece en TODAS las responses de `/api/{scope}/auth/me` y `/api/{scope}/auth/login`, independiente de si el scope tiene `--with-crud` o no. Frontend puede llamar `useMkAuth().hasAbility('xxx')` sin chequear el tipo de scope.

#### 3.16.3 F7-B03 — warning scaffolder si tabla del scope YA EXISTE

**Síntoma pre-fix**: si la DB `reto` traía `admins`/`members` de una corrida previa, `php artisan migrate` cortaba con `SQLSTATE[42P07] relation "..." already exists`. El scaffolder no avisaba — el dev descubría el problema al primer `migrate`.

**Fix**: nuevo método `checkScopeTableExists()` invocado antes de scaffoldear. Detecta tabla preexistente via `information_schema` (pgsql/mysql/mariadb) o `sqlite_master` (sqlite). Si existe, warning loud con sugerencia según ambiente:

```
⚠️  F7-B03: la tabla `members` YA EXISTE en la DB.
   Si corrés `php artisan migrate` ahora va a fallar con
   `SQLSTATE[42P07] relation "members" already exists`.

   Sugerencia para entornos piloto:
     php artisan migrate:fresh    # DESTRUCTIVO — borra datos
   Para producción, ver § "Migraciones incrementales".
```

**No-fatal**: el scaffolder sigue generando el código. El dev decide si aborta (migrate:fresh) o continúa (migration incremental).

**Si pidió `--migrate` y la tabla existe**: el scaffolder intenta migrar igual (cayendo al `migrate` específico que falla con el error SQLSTATE). Mejor que el silencio anterior.

#### 3.16.4 F7-W03 — `--profile-fields` ahora se exponen en `{Scope}Resource`

**Síntoma pre-fix**: el `--profile-fields="phone"` creaba la columna + validación (`StoreAdminRequest`/`UpdateAdminRequest`) + fillable, pero el `AdminResource::toArray()` scaffoldeado **no incluía `phone`**. Quedaba write-only: se podía crear/editar pero nunca leer de vuelta ni pre-fillear en edición.

**Fix**: el stub `admin-resource.stub` pinea el placeholder `{{profileFieldsResourceEntry}}` que el scaffolder popula reusando `buildProfileFieldsToArray()` (método que ya existía, lo usaba el DTO `AdminData`):

```php
// MakeAuthUserCommand.php generateCrudPack() — en $crudReplacements
'{{profileFieldsResourceEntry}}' => $this->buildProfileFieldsToArray($profileFields),
```

```php
// admin-resource.stub post-fix
public function toArray(Request $request): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'email' => $this->email,
        // ...
        'auth_scope' => $this->auth_scope,
{{statusResourceEntry}}{{profileFieldsResourceEntry}}            // HALLAZGO-NEW-FASE15-06: abilities flat top-level
        'abilities' => $this->getEffectiveAbilities(),
        // ...
    ];
}
```

**BC-safe**: scopes sin `--with-crud` (no tienen Resource scaffoldeado) no se afectan. El fix es solo en el flow CRUD. Si no hay `--profile-fields`, el placeholder queda string vacío (no se renderizan líneas extra).

**Round-trip completo**: `phone`, `full_name`, `address`, `birthdate`, etc. ahora son legibles de vuelta y pre-filleables en edición. Frontend puede usar `form.setField('phone', data.phone)` sin pedir el field extra fuera del Resource.
- Stubs modificados: `src/Stubs/auth-user.auth-controller.stub` (A), `src/Stubs/auth-user/auth-user.routes.with-crud.stub` (C).
- Source: `.makromania/projects/mk-director/operations/s8-f6-admin-clean-rebuild-result.md` § "Consumer-side fixes pineados" + § "Hallazgo adicional".

---

### 3.17 FEEDBACK10 — Service hooks, auth routes, RBAC + `--kind=manager|consumer` (RETO corrida 10)

> **Source**: `FEEDBACK10.md` (F10-B02/B03/B04/B06/B07/B08/B09). Todos additive/BC-safe salvo donde se indica.

#### 3.17.1 F10-B03 — `CRUDSmart::getService()` nunca resolvía el Service scaffoldeado

**Síntoma pre-fix**: `getService()` gateaba en `app()->bound($serviceClass)`, siempre `false` para la clase concreta que el scaffolder pinea en `'service' => {Scope}Service::class` (nunca bindeada en el ServiceProvider). Resultado: `beforeSearch`, `beforeShow`, `beforeCreate`, `afterCreate`, `afterUpdate`, `afterDelete`, `setExtraData`, etc. **no disparaban out-of-the-box** — silenciosamente.

**Fix**: `getService()` ahora también resuelve vía `app()->make($serviceClass)` cuando `class_exists($serviceClass)` — igual que Laravel ya auto-resuelve clases concretas sin bind explícito. No hace falta tocar el ServiceProvider para que los hooks disparen.

```php
// CRUDSmart::getService() post-fix
if (is_string($serviceClass) && (app()->bound($serviceClass) || class_exists($serviceClass))) {
    return app()->make($serviceClass);
}
```

#### 3.17.2 F10-B04 — hooks `afterCreate/afterUpdate/afterDelete` scaffoldeados sin `return` → 500

Una vez que F10-B03 hace que los hooks disparen, los 3 hooks scaffoldeados (`: mixed`, sin `return` en el cuerpo passthrough) explotaban con `TypeError: Return value must be of type mixed, none returned`. Fix: `admin-service.stub` cierra los 3 hooks con `return null;` explícito.

#### 3.17.3 F10-B06 — rutas auth apuntaban a métodos inexistentes

El stub de rutas apuntaba a `AuthController::forgot`/`reset` — la clase real (`BaseAuthController`) expone `forgotPassword()`/`resetPassword()`. Además faltaban las rutas de `logoutAll()` y `changePassword()` (ya implementados en la base, nunca expuestos). Ver §3.8.1 arriba para el detalle de paths/métodos post-fix. `{{updateProfileRoute}}` (pineado a `PATCH me → updateProfile`, solo con `--profile-fields`) ya existía pero no se estaba insertando en ningún stub — corregido.

#### 3.17.4 F10-B07 — `roles` sin columna `description` pero `RoleResource` la expone

La tabla `roles` del paquete solo tiene `id/name/guard/is_fixed/timestamps` — nunca tuvo `description` — pero el `RoleResource` scaffoldeado la exponía y el `store/update` genérico de `CRUDSmart` la aceptaba por mass-assignment, causando `SQLSTATE[42703] column "description" does not exist` en cualquier create/update de rol con ese campo. Fix: `description` retirada del `RoleResource`/requests scaffoldeados. (`Ability` SÍ tiene `description` real — no confundir.)

#### 3.17.5 F10-B08 — `--kind=manager|consumer` (scopes administrados)

Nuevo flag `mk:make:auth-user {Scope} --kind=manager|consumer` (default `manager`, BC byte-for-byte para el path default). Codifica el patrón "scope consumer self-profile-only, administrado por otro scope" que el piloto RETO tuvo que armar a mano.

```bash
php artisan mk:make:auth-user Admin --with-crud
php artisan mk:make:auth-user Member --kind=consumer --managed-by=Admin --with-crud
```

- `--kind=consumer` **requiere** `--managed-by=<Scope>` — aborta con error claro si se omite, ANTES de generar nada. El manager debe scaffoldearse primero (el comando valida que `App\Modules\{Manager}` ya exista).
- Con `--kind=consumer`, `Http/Routes/api.php` propio queda reducido a auth + self-profile (`login`, `refresh`, `logout`, `logout-all`, `me`, `PATCH me`, `password/forgot`, `password/reset`, `password/change`) — SIN CRUD propio, SIN `/roles`, SIN `/abilities`. Stub dedicado `auth-user.routes.consumer.stub` (no string-surgery sobre el stub full).
- NO se generan `RoleController`/`AbilityController` propios ni sus Policies (quedarían sin rutas que los gateen).
- `{Scope}Controller`, DTOs, Repository, Service, Factory, Seeder y `{Scope}Policy` SÍ se generan igual — los usa el recurso managed (`--managed-by`) para exponer el CRUD bajo el guard del manager (`/api/{manager}/{scopePlural}`).
- El path `manager` (default, sin `--kind`) es idéntico al comportamiento pre-existente.

Internal: `MakeAuthUserCommand::modulesPath()` (mismo patrón que `MakeModuleCommand::modulesPath()`) como punto único de resolución de `app_path("Modules/...")`, para testear la generación de archivos end-to-end contra un tempdir real.

#### 3.17.6 F10-B09 — `{Scope}Status::default()` retornaba la clase equivocada

El enum `{Scope}Status` scaffoldeado (`--with-status`) tenía `default()` retornando `ScopeStatus::Active` (clase literal, no `self`) → `TypeError` en factories/migrations. Fix: `return self::Active;`.

#### 3.17.7 F10-B02 — `FileStoragePlugin` mal cableado en el controller stub

Ver §5.4 (`plugins`/`plugins_config`) para el detalle completo del fix — el stub ahora emite `plugins` (lista de clases, registrado per-controller por `CRUDSmart::getPluginManager()`) y `plugins_config` (config que el plugin lee vía `getConfigValue('plugins_config.<name>')`) como dos keys separadas.

---

### 3.18 Cambio de contraseña por PIN de email (OTP) + `PATCH me` ampliado (SDD `2026-07-15-profile-edit-password-otp`)

> **Source**: SDD `2026-07-15-profile-edit-password-otp`. Feature aditivo/BC-safe. Habilita cambio de contraseña vía PIN de un solo uso enviado por email (sin exigir `current_password`) y amplía la edición de perfil a `email` + `avatar`.

El flujo clásico `password/change` exige `current_password` como prueba de identidad. Este flujo alternativo la reemplaza por un **PIN de 6 dígitos** que el paquete emite y despacha por evento para que el consumer lo mande por email. Es el patrón "olvidé mi contraseña estando logueado" / "confirmá con el código que te enviamos".

#### 3.18.1 Nuevos endpoints

Los tres viven bajo `/api/{scope}/auth/` (`{scope}` = `admin` | `member`) y requieren el guard `mk.auth:{scope}` (el usuario ya está autenticado — el PIN es una segunda prueba, no un login).

| Endpoint | Método base | Body | Éxito | Errores |
|---|---|---|---|---|
| `POST password/code/request` | `BaseAuthController::requestPasswordCode()` | (sin body) | `200` `{expires_at}` + `"Código enviado."` | `429` `ERR_THROTTLED` si está throttleado |
| `POST password/code/confirm` | `confirmPasswordCode()` | `{code, password, password_confirmation}` | `200` `data: true` + `"Contraseña actualizada."` | `410` `ERR_CODE_EXPIRED` · `423` `ERR_CODE_LOCKED` · `422` `ERR_VALIDATION` `"Código inválido."` |
| `PATCH me` (ampliado) | `updateProfile()` | `{name?, phone?, email?, avatar?}` | `200` user model actualizado | `422` validación |

**`POST password/code/request`** — no lleva body; emite un PIN nuevo para el usuario autenticado y despacha `auth.password_change_code.requested`. Throttle de ruta default `3,10` (3 requests / 10 min).

**`POST password/code/confirm`** — validación: `code` `required|string`; `password` `required|string|min:8|max:255|confirmed`. **NO** lleva `current_password` — el PIN es la prueba que lo reemplaza. Mapeo verdict → HTTP (una única fuente, `EmailOtpService::verify()`):

| Verdict | HTTP | Código | Mensaje |
|---|---|---|---|
| `Confirmed` | `200` | — | `"Contraseña actualizada."` (`data: true`) |
| `Expired` | `410` | `ERR_CODE_EXPIRED` | — |
| `Locked` | `423` | `ERR_CODE_LOCKED` | — |
| `Invalid` / `NotFound` | `422` | `ERR_VALIDATION` | `"Código inválido."` (genérico) |

> **Anti-oracle**: `Invalid` y `NotFound` colapsan al MISMO `422` genérico — el endpoint **no distingue** "nunca existió" vs "expirado/consumido con código equivocado". Evita filtrar si un identifier tiene o no un código activo.

En éxito, el confirm corre dentro de `DB::transaction`: `setAuthPassword()` (ver §3.18.4) y, si `config('mk_director.auth.password_change.revoke_other_sessions')` (default `true`), revoca los demás tokens Sanctum del usuario. Luego despacha `auth.password_changed`. Throttle de ruta default `5,10`.

**`PATCH me` ampliado** — antes solo validaba `name` + `phone`; ahora es un **superset estricto** BC-safe (cada regla `sometimes`-guardada): `name` (`sometimes`), `phone` (`sometimes|nullable`), `email` (`sometimes|unique` ignorando al propio usuario), `avatar` (`sometimes|file|image|max:4096`, cableado a través de `FileStoragePlugin`). Solo se scaffoldea cuando el scope se genera con `--profile-fields`.

#### 3.18.2 `EmailOtpService` — fuente única del verdict

`Mk\Director\Auth\Services\EmailOtpService`:

| Método | Firma | Qué hace |
|---|---|---|
| `issue` | `issue(string $scope, string $purpose, string $identifier): OtpIssueResult` | Emite un PIN nuevo → `{plainCode, expiresAt}`. Hashea el PIN con `Hash::make`; **el plaintext NUNCA se persiste ni se loguea** (la DB guarda solo `code_hash`, bcrypt). |
| `verify` | `verify($scope, $purpose, $identifier, $code): OtpVerifyResult` | Enum `Confirmed \| Invalid \| Expired \| Locked \| NotFound`. **Fuente única** del verdict — el controller solo mapea a HTTP. |
| `isRequestThrottled` | `isRequestThrottled($scope, $purpose, $identifier): bool` | Guard del lado request, keyeado por `(auth_scope, purpose, identifier)`. Sobrevive entre IPs/sesiones para la misma cuenta — **defense-in-depth POR ENCIMA** del throttle de ruta (que es por IP/sesión). |
| `prune` | `prune()` | Housekeeping (limpieza de códigos vencidos/consumidos). |

#### 3.18.3 Store: tabla genérica `verification_codes`

Nueva tabla `verification_codes` (la migration hace no-op si ya existe vía `Schema::hasTable`). Es **genérica y reusable** — la columna `purpose` la hace apta para futuros 2FA / verificación de email, NO es password-change-specific. Columnas relevantes: `purpose`, `attempts`, `consumed_at`, `code_hash` (bcrypt). El servicio garantiza **single-use + expiry + attempt-lock**.

#### 3.18.4 `AuthUser::setAuthPassword()` — fix del `BadMethodCallException`

Se agregó al modelo base `Mk\Director\Auth\Models\AuthUser`:

```php
public function setAuthPassword(string $password): void
{
    $this->setAttribute($this->getAuthPasswordName(), $password);
    $this->save();
}
```

Depende del cast `'password' => 'hashed'` (sin doble-hash). Esto **arregla un `BadMethodCallException` preexistente**: `BaseAuthController` ya llamaba `setAuthPassword()` en `changePassword()` y `resetPassword()`, pero el método **no existía** en `AuthUser` → ambos flujos (más el nuevo confirm) rompían. Ahora los tres funcionan.

#### 3.18.5 Dos nuevos tipos de `AuthEvent` (email desacoplado)

El paquete no manda emails: despacha eventos y el consumer cablea un Mailable + listener.

| Evento | Payload | Notas |
|---|---|---|
| `auth.password_change_code.requested` | `{scope, user_id, code, expires_at, ip}` | ⚠️ `code` es el **PIN en PLANO** — el ÚNICO lugar donde existe en cleartext, viaja solo para que el listener del consumer lo mande por email. |
| `auth.password_changed` | `{scope, user_id, ip}` | Confirmación de cambio efectivo. |

> ⚠️ **`code` es una credencial**: un listener **NUNCA** debe loguearlo, persistirlo ni reenviarlo a terceros. Úsalo exclusivamente para renderizar el email y descártalo.

Este flujo además **desbloquea** el previamente-muerto `auth.password_reset.requested` — el consumer solo necesita cablear un listener. Ver §3.8 (`Cómo registrar un listener para AuthEvent`) para el patrón de suscripción.

#### 3.18.6 Config (`config/mk_director.php` → `auth.*`)

Todo env-overridable:

| Clave | Default | Env |
|---|---|---|
| `rate_limits.password_code_request` | `'3,10'` | `MK_AUTH_RATE_LIMIT_PWD_CODE_REQ` |
| `rate_limits.password_code_confirm` | `'5,10'` | `MK_AUTH_RATE_LIMIT_PWD_CODE_CONFIRM` |
| `otp.length` | `6` (rango `4..6`) | `MK_AUTH_OTP_LENGTH` |
| `otp.ttl_seconds` | `600` (~10 min) | `MK_AUTH_OTP_TTL_SECONDS` |
| `otp.max_attempts` | `5` | `MK_AUTH_OTP_MAX_ATTEMPTS` |
| `otp.throttle.max` | `3` | `MK_AUTH_OTP_THROTTLE_MAX` |
| `otp.throttle.window_seconds` | `600` | `MK_AUTH_OTP_THROTTLE_WINDOW` |
| `password_change.revoke_other_sessions` | `true` | — |

#### 3.18.7 Fix del scaffolder: accessor de avatar emitido desde `--profile-fields`

`MakeAuthUserCommand::detectFileFields()` recibía el mapa plano `key => 'file'` en vez de los meta-arrays de `$profileFieldsRaw`, así que `getAvatarUrlAttribute` **nunca se emitía** salvo que se pasara `--with-crud` (raíz de que los consumers terminaran escribiendo a mano un accessor de avatar). **Ya está fijo**: el accessor se emite con `--profile-fields` solo. `buildUpdateProfileMethod` se amplió al superset estricto de §3.18.1 (cada regla `sometimes`-guardada, BC-safe).

#### 3.18.8 "Olvidé mi contraseña" por PIN (reset OTP, flujo NO autenticado)

> **Source**: mismo motor de §3.18.1–3.18.2 (`EmailOtpService` + tabla genérica `verification_codes`), esta vez con `purpose='password_reset'`. Es la **variante PIN del flujo clásico de token** `password/forgot` + `password/reset` (ver §3.3): ambos coexisten (aditivo/BC). Aplica cuando el usuario **no está logueado** y no recuerda su contraseña.

A diferencia de §3.18 (usuario autenticado, el PIN es una segunda prueba sobre el guard), acá **no hay sesión**: los dos endpoints son **públicos** y se emiten **incondicionalmente** en la sección PUBLIC del stub de rutas auth-user, al lado de `password/forgot`/`password/reset`. Los métodos viven en `BaseAuthController` y los hereda cada wrapper thin scaffoldeado, igual que el resto de los métodos auth.

| Endpoint | Método base | Body | Éxito | Errores |
|---|---|---|---|---|
| `POST password/reset/code/request` | `BaseAuthController::requestPasswordResetCode()` | `{<loginField>}` (ej. `email`) | `200` genérico (ver abajo) | — (nunca 429 por cuenta) |
| `POST password/reset/code/confirm` | `confirmPasswordResetCode()` | `{<loginField>, code, password, password_confirmation}` | `200` `data: true` | `410` `ERR_CODE_EXPIRED` · `423` `ERR_CODE_LOCKED` · `422` `ERR_VALIDATION` `"Código inválido."` |

**`POST password/reset/code/request`** — recibe el `loginField` (típicamente `email`). Emite un PIN nuevo (`EmailOtpService` con `purpose='password_reset'`) y despacha `auth.password_reset_code.requested` con payload `{scope, user_id, code, expires_at, ip}` (`code` = PIN en plano, ver aviso en §3.18.5).

> **Anti-enumeration**: SIEMPRE devuelve un `200` genérico — `"Si el {loginField} existe, recibirás un código para restablecer tu contraseña."` — exista o no la cuenta, **y también cuando el throttle a nivel cuenta dispararía** (`isRequestThrottled` NO emite `429` acá: el corte de cuenta es **silencioso**, para no filtrar existencia). El abuso por IP lo corta el `throttle:` de ruta (default `3,10`), que es **por IP** y por eso no revela si la cuenta existe.

**`POST password/reset/code/confirm`** — validación: `code` `required|string`; `password` `required|string|min:8|max:255|confirmed`. Resuelve el usuario por `loginField`. `EmailOtpService::verify(purpose='password_reset')` es la **fuente única** del verdict; el controller solo mapea enum → HTTP:

| Verdict | HTTP | Código | Mensaje |
|---|---|---|---|
| `Confirmed` | `200` | — | `data: true` |
| `Expired` | `410` | `ERR_CODE_EXPIRED` | — |
| `Locked` | `423` | `ERR_CODE_LOCKED` | — |
| `Invalid` / `NotFound` / usuario desconocido | `422` | `ERR_VALIDATION` | `"Código inválido."` (genérico) |

> **Anti-enumeration + anti-oracle**: un email desconocido colapsa al **MISMO** `422` genérico que un código equivocado — nunca revela si la cuenta existe ni si tenía un código vivo.

En éxito corre dentro de `DB::transaction`: `setAuthPassword()` (ver §3.18.4) y **revoca TODOS los tokens Sanctum** del usuario — un reset lo **desloguea en todos lados** (no hay `currentAccessToken` en el flujo no autenticado). Luego despacha `auth.password_reset.success`. Throttle de ruta default `5,10`.

**Eventos** (email desacoplado, mismo patrón que §3.18.5):

| Evento | Payload | Notas |
|---|---|---|
| `auth.password_reset_code.requested` | `{scope, user_id, code, expires_at, ip}` | ⚠️ `code` es el **PIN en PLANO** — el consumer cablea un Mailable + listener; NUNCA loguear/persistir/reenviar. |
| `auth.password_reset.success` | — | Reset efectivo. |

**Config** (`config/mk_director.php` → `auth.rate_limits`; el bloque `otp.*` es compartido con el flujo autenticado de §3.18.6):

| Clave | Default | Env |
|---|---|---|
| `rate_limits.password_reset_code_request` | `'3,10'` | `MK_AUTH_RATE_LIMIT_PWD_RESET_CODE_REQ` |
| `rate_limits.password_reset_code_confirm` | `'5,10'` | `MK_AUTH_RATE_LIMIT_PWD_RESET_CODE_CONFIRM` |

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

#### 🔴 `allowed_filters` es una WHITELIST, y lo que no declarás DESAPARECE

```php
protected $mkConfig = [
    'features' => [
        'allowed_filters' => ['status', 'guard'],   // ← si no está acá, NO existe
    ],
];
```

`ListManager::applyFilters()` hace `continue` sobre todo campo que no esté en
la lista: **sin 422, sin log**. Y si `allowed_filters` está vacío o ausente,
descarta **todos** los filtros.

Medido contra la API corriendo, con 5 roles y un `RoleController` recién
scaffoldeado (que sale **sin** `allowed_filters`):

```
GET /api/admin/roles?per_page=100                        -> 5 filas
GET /api/admin/roles?per_page=100&filter[guard]=admin    -> 5 filas
GET /api/admin/roles?per_page=100&filter[guard]=noexiste -> 5 filas   ← ⚠️
```

El tercero es el que lo delata: un filtro que **no puede** matchear nada
devuelve todo, con `success: true`. Si sólo mirás los dos primeros, "funciona".

⚠️ **Asimetría que confunde**: un **operador** desconocido
(`filter[age][gt3]=18`) tira `InvalidArgumentException`; un **campo**
desconocido se evapora. Ver el operador fallar ruidosamente no te dice nada
sobre el campo.

**Regla operativa**: cada filtro nuevo del front necesita su columna en
`allowed_filters`. Si un filtro "no hace nada", ese es el primer lugar donde
mirar — no el front.

### 4.2.1 🔴 BC — `?restore_state=1` para restaurar los filtros guardados

Con `'remember_state' => true` (default de los controllers scaffoldeados), el
paquete guarda el `q` / `filter` / `sort` del último pedido por usuario y por
tabla. **Ya no se los re-inyecta solo** a un pedido que no los traiga: hay que
pedirlo.

```
GET /api/admins?q=Jefe                        ->  1 fila
GET /api/admins?per_page=100                  -> 10 filas   (antes devolvía 1)
GET /api/admins?per_page=100&restore_state=1  ->  1 fila
```

**La regla**: un pedido que no manda `q` está pidiendo la lista SIN buscar. Eso
es una **instrucción**, no una omisión.

El nombre del parámetro sale de `mk_director.features.remember_state_param`. Se
lee con `filter_var(..., FILTER_VALIDATE_BOOLEAN)`, así que `?restore_state=0`
y `?restore_state=false` significan lo que parecen (`(bool) 'false'` es `true`
en PHP, y el paquete ya pagó ese footgun).

**Migración**: la pantalla que dependía de la restauración implícita agrega el
parámetro en su primera carga. Es un break del lado seguro: sin él, lo peor que
pasa es que la pantalla abra sin filtros; antes devolvía datos equivocados
afirmando éxito (`200`, `success: true`, y un `total` que confirmaba el número
equivocado). Ver `CHANGELOG.md` § `[UNRELEASED] — BC: restaurar filtros`.

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
            // Post-R-PKG-032 v1.8.0: la paginación está agrupada bajo
            // '__extraData.pagination' — no interfiere con custom keys flat
            // como 'audit_checked'. El caller puede mergear extras custom
            // aquí sin tocar la shape canónica del envelope.
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

#### `FileStoragePlugin` (R-PKG-045)

Maneja automáticamente la subida de archivos (multipart) y la conversión de rutas
a URLs completas en la respuesta. **Hook = `beforeSave`** — corre ANTES del
`Model::create` / `Model::update`. El path se escribe en `$data[$column]`
que Eloquent luego persiste en la columna del modelo. **NO** se ejecuta
después del insert (`afterCreate` — drift pre-R-PKG-045, F8-B02).

**R-PKG-045 D1 — Mapeo explícito `request field → column`** (BC-safe):

```php
// BC-safe (array plano): identity map. Request field ES el column name.
'fields' => ['photo'],  // request 'photo' → column 'photo'

// NEW (asociativo): rename. Request field ≠ column name.
'fields' => ['photo' => 'photo_path'],  // request 'photo' → column 'photo_path'

// Mixto válido:
'fields' => ['photo', 'avatar' => 'avatar_path'],  // identity + rename
```

PHP `foreach` sobre array plano da keys integer (0, 1, 2...) — el plugin
detecta con `is_int($requestField)` y auto-normaliza como identity map. Consumers
con `'fields' => ['photo']` (pre-R-PKG-045) ZERO migración.

**R-PKG-045 D2 — Auto-register por default**: el plugin se carga automáticamente
vía `MkServiceProvider::registerPlugins()`. **Ya NO necesitas pinearlo en `plugins`**.
Opt-out via `'features.file_storage_plugin' => false` en config (env: `MK_FILE_STORAGE_PLUGIN=false`).

```php
// config/mk_director.php (publicado)
'features' => [
    // ...
    'file_storage_plugin' => env('MK_FILE_STORAGE_PLUGIN', true),  // R-PKG-045 D2
],
```

Si pineas el plugin explícito en `plugins: [FileStoragePlugin::class, ...]`,
**dedup** lo respeta — no se duplica.

**R-PKG-045 D3 — Audit levels**: `mk:status` ahora diferencia tres niveles:
- `key missing` → **error** (rojo) — falta config requerida.
- `key empty` (`fields: []` pineado a propósito) → **info** (gris) — decisión intencional.
- `key set` → sin finding.

Pre-R-PKG-045, ambos "missing" y "empty" generaban `warning` (amarillo) — falso
positivo que distraía al dev (FEEDBACK8 F8-B04).

**Configuración completa en Controlador:**
```php
'plugins_config' => [
    'file_storage' => [
        'fields'   => [
            // BC-safe (array plano): identity map
            'image', 'avatar',  // request field ES el column name

            // NEW (asociativo): rename request field → column
            'photo' => 'photo_path',  // request 'photo' → column 'photo_path'
        ],
        'disk'     => 'public',             // Disco de Laravel
        'path'     => 'surveys/images',     // Carpeta destino
        'auto_url' => true,                 // Convertir path a URL en afterResponse
    ]
]
```

**Configuración global** (alternativa a per-controller):
```php
// config/mk_director.php — config merge default del paquete
'plugins_config' => [
    'file_storage' => [
        'fields' => [],
        'disk'   => 'public',
        'path'   => 'uploads/files',
        'auto_url' => true,
    ],
],
```

**Notas operacionales**:
- Hook = `beforeSave` (corre ANTES del `Model::create`/`update`). El path
  se escribe en `$data[$column]` para que Eloquent lo persista al insertar.
- `afterResponse` (si `auto_url=true`) convierte el path almacenado a URL
  completa vía `Storage::disk($disk)->url($path)`.
- **YAGNI hasta que un consumer lo pida**: organizar archivos por ID del modelo
  (`uploads/{id}/file.jpg`) NO está soportado por este plugin (FEEDBACK8 F8-B02
  backlog deferred). Implementación requeriría hook `afterCreate` con modelo ya
  persistido.
- **Cleanup on delete**: `beforeDelete` / `afterDelete` están vacíos
  intencionalmente (R-PKG-034 R1 MEDIUM). Ver § 6.1 para workaround con
  Model Observer si necesitás cleanup automático.
- **Anti-pattern**: NO pinees `fields` como `[]` y luego hagas el upload a mano
  en un Service (FEEDBACK8 F8-S01 deuda). Post-R-PKG-045, el plugin resuelve
  el rename vía D1 — refactor a 5 LOC declarativas en vez de 50 LOC imperativas.
- **F10-B02 (RETO corrida 10, fijo)**: `plugins` y `plugins_config` son DOS
  keys separadas con roles distintos — no las mezcles. `plugins` es SIEMPRE una
  lista de **nombres de clase** (`CRUDSmart::getPluginManager()` la registra
  per-controller así); un array asociativo de config ahí es silenciosamente
  skippeado por `PluginManager::registerPlugins()`. `plugins_config` es donde
  va la config real, keyed por nombre de plugin (`file_storage`), que cada
  plugin lee vía `getConfigValue('plugins_config.<name>')`. El scaffolder
  `mk:make:auth-user --profile-fields="avatar:file"` llegó a emitir la config
  bajo `plugins` (shape `['file_storage' => [...]]`) en vez de `plugins_config`
  — la subida se rompía en silencio (el tmp path del `UploadedFile` quedaba
  persistido tal cual). Fijo: el stub emite `'plugins' =>
  [\Mk\Director\Plugins\FileStoragePlugin::class]` + `'plugins_config' =>
  ['file_storage' => [...]]` correctamente.

---

---

## ⚠️ 6. Limitaciones Conocidas (R-PKG-034)

> **Sección nueva (2026-06-29)**: Pineada como parte del sprint R-PKG-034 (code review 4R).
> Tabla de hallazgos del code review que **NO se aplicaron como fix en el sprint**,
> con la razón documentada. Para cada uno, ver `CHANGELOG.md` § v1.8.2-rc0 + las
> reglas binding (`R-MK-001`, `R-PKG-024`, `R-PKG-032`).

### 6.1 `FileStoragePlugin::beforeDelete()` y `afterDelete()` están vacíos

**Hallazgo**: R1 MEDIUM (4R review). Cuando un modelo con file fields se elimina,
los archivos quedan huérfanos en disk/S3.

**Por qué NO se fixea en este sprint**:

- **BC-break potencial**: implementar cleanup automático borra archivos que
  consumers pinean en otros lugares (S3 multi-tenant, shared uploads entre
  modelos, etc.). La heurística de "qué archivos son seguros de borrar" no es
  genérica — depende de la estrategia del consumer.
- **Por diseño**: el plugin pineó `beforeSave()` para upload automático, pero
  deja la limpieza como responsabilidad del consumer (extensibilidad explícita
  sobre acoplamiento implícito).

**Workaround recomendado**:

```php
// En tu AppServiceProvider o model observer, manejar el cleanup manualmente:
class YourModelObserver
{
    public function deleted(YourModel $model): void
    {
        $disk = 'public';
        foreach (config('mk_director.plugins_config.file_storage.fields', []) as $field) {
            if ($model->{$field}) {
                Storage::disk($disk)->delete($model->{$field});
            }
        }
    }
}

// Registrar en App\Providers\AppServiceProvider::boot():
YourModel::observe(YourModelObserver::class);
```

**Backlog**: track en `mk-director-issues` § "FileStoragePlugin cleanup". Se
reevaluará cuando un consumer reporte el problema concreto (R-G-033: dogfooding-first).

### 6.2 `MkBelongsToMany` reflection fragility (332 líneas)

**Hallazgo**: R3 HIGH (4R review). La clase usa reflection para copiar
properties internas de Laravel `BelongsToMany`. Si Laravel cambia internals en
un major version, puede romperse silenciosamente.

**Por qué NO se fixea en este sprint**:

- **Refactor de alto costo**: `BelongsToMany::__construct()` toma 8 argumentos
  (query, parent, table, foreignPivotKey, relatedPivotKey, parentKey, relatedKey,
  relationName). Calcular todos manualmente es frágil y duplica lógica interna
  de Laravel.
- **Defense-in-depth pineado**: el código actual tiene guards (`isInitialized`,
  `hasType()`, `isBuiltin()` checks) que skip properties conflictivas. Si Laravel
  agrega una property typed incompatible, el código la skipea en vez de crashear.
- **Trade-off documentado en source**: el docblock de `MkBelongsToMany` líneas
  14-73 explica por qué se hizo así, qué cubre (`attach`, `sync`, `toggle`,
  etc.), y cómo opt-out (`override roles() sin retornar MkBelongsToMany`).

**Backlog**: refactor a delegate-only cuando Laravel rompa BC. Pin tested
Laravel version range explícitamente en `composer.json` (`^13.0`, OK).
Agregar smoke test que asserte `BelongsToMany::$using` exists cuando
Laravel 14 salga.

### 6.3 `CRUDSmart.php` 506-line god trait

**Hallazgo**: R2 HIGH (4R review). El trait contiene `index/show/store/update/destroy`
+ 17 helper methods. Excede el "300-line comfort zone".

**Por qué NO se fixea en este sprint**:

- **Cohesivo al ciclo CRUD**: todos los métodos son parte de index/show/store/update/destroy.
  Refactor a 2-3 traits (`CRUDIndex`, `CRUDMutate`, `CRUDConfig`) introduce
  overhead de composición sin valor inmediato para el consumer.
- **R-G-030 KISS**: la solución más simple que resuelve el problema es la actual.
  Sobre-ingeniería prematura (YAGNI).

**Backlog**: extraer trait `CRUDConfig` con los helpers de configuración
(`getModel`, `getCacheTTL`, `getCacheTags`, `getDTOClass`, `getEnumMap`) si un
consumer necesita override parcial de la lógica CRUD sin override todo el trait.
Threshold para actuar: cuando un segundo consumer pida override parcial.

### 6.4 `MakeAuthUserCommand` sin transactional rollback

**Hallazgo**: R3 HIGH (4R review). El scaffolder genera 17+ archivos en una sola
ejecución. Si un file write falla a mitad, queda un módulo parcial.

**Por qué NO se fixea en este sprint**:

- **Scaffolder es dev-time, no runtime**: el escenario "disk full a mitad de
  scaffoldear 17 archivos" es rarísimo y el developer lo resuelve manualmente
  (`rm -rf app/Modules/NewScope` + retry).
- **`--dry-run` ya pineado (R-PKG-021)**: el flag existe para preview antes de
  escribir. Si se necesita, agregar un check pre-flight de disk space + permisos.
- **Alternative rechazada**: "escribir a temp dir y mover atómicamente" introduce
  complejidad significativa (atomic move cross-filesystem, permissions handling,
  symlinks) para un caso de uso edge.

**Backlog**: agregar check pre-flight (disk space + permissions) + warning
post-generation con conteo de archivos generados vs esperados. Track cuando
un developer reporte pérdida de trabajo.

### 6.5 `HasAbilities/HasRoles` `pivotExtras/abilityPivotExtras` DRY violation

**Hallazgo**: R2 MEDIUM + R4 MEDIUM (4R review). Las dos traits contienen
lógica similar (static cache + `Schema::hasColumn` + payload construction).

**Por qué NO se fixea en este sprint**:

- **Divergencias sutiles**: `pivotExtras()` (HasRoles) usa tabla `role_user`,
  `abilityPivotExtras()` (HasAbilities) usa tabla `ability_user`. El payload
  es idéntico (`['user_type' => static::class]`), pero el cache keying es
  table-specific. Extraer a un trait compartido requiere pasar `$table` por
  parámetro, lo que pierde el cache estático elegante.
- **Tests ya pinean el contrato**: ambos métodos tienen tests que assertean
  el payload correcto. Refactorizar requiere reescribir tests + validar no
  regresión.

**Backlog**: extraer a `PivotExtrasTrait` con método abstracto `pivotTableName()`.
Track cuando un tercer pivot use case aparezca (e.g., `team_user` para multi-team).

### 6.6 `LintBoundariesCommand` regex-only detection (no AST)

**Hallazgo**: R5 MEDIUM (4R review). La regla de MME parsea `use` statements
via regex. No detecta aliased imports (`use Foo\Bar as BarModel`) ni dynamic
imports dentro de closures.

**Por qué NO se fixea en este sprint**:

- **Symfony AST parsing es dependency mayor**: agrega nikic/php-parser o
  symfony/php-parser como dependencia obligatoria del paquete. Trade-off
  vs. el costo del lint imperfecto.
- **Tasa de bypass real = 0**: en RETO (single consumer dogfooding), 0
  developers han reportado bypass del lint en 5 sprints.

**Workaround si necesitás stricter enforcement**:

```bash
# Agregar como lint step custom en tu CI:
nikic/php-parser-based script que detecte use Foo\Bar as BarModel
```

**Backlog**: AST parsing si bypass incidents ocurren en producción (≥ 1 report).
Threshold: 2 incidents en 2 sprints consecutivos.

### 6.7 `MkDTO::detectEnums()` + `DTOFactory::detectEnums()` DRY violation

**Hallazgo**: R4 MEDIUM (4R review). Dos clases tienen `detectEnums()` con
lógica similar pero NO idéntica.

**Por qué NO se fixea en este sprint**:

- **Lógicas divergentes**:
  - `MkDTO::detectEnums()` (línea 89) usa namespace-prefix matching
    (`App\Modules\{Module}\Enums`).
  - `DTOFactory::detectEnums()` (línea 178) usa dirname-based resolution
    (`dirname($modelDir) . '/Enums'`) + custom resolver config
    (`mk_director.enum_namespace_resolver`).
- **No 1-a-1 refactorable** sin perder features (DTOFactory tiene config override).

**Backlog**: extraer a `EnumDetector` utility class con dos métodos
(`detectByNamespace()`, `detectByDirname()`) que ambos callers delegan. Track
cuando un tercer use case pida enum detection.

### 6.8 `OpenApiController::docs()` hardcoded Swagger CDN version

**Hallazgo**: R4 LOW (4R review). Línea 67-71 hardcoded `swagger-ui-dist@5.11.0`.

**Por qué NO se fixea en este sprint**:

- **`spec()` SÍ usa config** (`mk_director.openapi.cache_ttl`, línea 42). Solo
  `docs()` no usa config — es una inconsistencia cosmética, no un bug.
- **Upgrade path claro**: si necesitás cambiar la versión, override el método
  `docs()` en tu controller subclass.

**Backlog**: agregar `mk_director.openapi.cdn_version` config con default
`5.11.0`. Track cuando un consumer pida self-hosted Swagger UI assets.

### 6.9 Hallazgos que fueron FALSO POSITIVO del reviewer

Para referencia, estos hallazgos del 4R review **NO eran bugs reales** — el
código actual ya pineaba el patrón descrito:

| Hallazgo | Sev | Por qué es falso positivo |
|---|---|---|
| `DiscoverAbilitiesCommand` hardcoded `users.*` pattern | LOW R1 | Las abilities se generan con `"{$scope}.{$resource}.{$verb}"` (línea 658), donde scope y resource derivan del module name + model FQCN. NO pinea `users.*` hardcoded. |
| `BaseController::autoTransform()` lacks null-safe operator | MEDIUM R3 | Línea 205-208 ya tiene `if ($first && isset($first->apiResource))` — la guarda null-safe está pineada. |
| `MakeAuthUserCommand` --scope no propaga | HIGH R5 | El scope SÍ propaga a nombres de archivos generados (`{$scope}Controller.php`, etc., líneas 932-968). Los nombres de stubs internos son convención sin impacto en runtime. |
| `MkDTO::detectEnums()` couples to filesystem sin logging | MEDIUM R3 | El código actual (líneas 89-118) tiene try/catch robusto + config resolver (`mk_director.enum_namespace_resolver`). |
| `CacheManager::flush()` crashes sin recovery | MEDIUM R3 | Decisión arquitectónica pineada en R-PKG-024 (rc13): fail-fast con mensaje accionable es preferible a "nuke" con `$cache->clear()` que borra TODO el cache. La scaffolder avisa al consumer sobre la config requerida. |

### 6.10 🔴 Testear endpoints con `mk.auth:{scope}` tiene dos trampas

Las dos producen **tests verdes que no miden nada**, que es la peor clase de
resultado que un test puede dar.

**a) `actingAs()` y `Sanctum::actingAs()` NO sirven.**
`AuthScopeResolver::resolve()` exige que `currentAccessToken()` sea un
`PersonalAccessToken` **real**. `$this->actingAs($user, 'admin')` no tiene
token; `Sanctum::actingAs(...)` da un `TransientToken`. Los dos caen en la rama
`no_token` y devuelven **401 `ERR_SCOPE_MISMATCH`** — un mensaje que se lee
como "el token es de otro scope" cuando el problema real es "no hay token", y
que te manda a depurar el scope equivocado.

Hay que emitir un token de verdad, con el mismo servicio que usa el login:

```php
use Mk\Director\Auth\Services\TokenIssuer;

protected function bearerFor(Authenticatable $user): array
{
    $token = app(TokenIssuer::class)->issueAccessToken($user);

    return ['Authorization' => 'Bearer '.$token->plainTextToken];
}
```

**b) Dos requests autenticados en un mismo test se autentican como el
primero.** No es un bug del paquete —los guards viven en el container,
`RequestGuard::user()` memoiza, y entre dos requests de un mismo test el
container no se reconstruye— pero **con tokens reales la trampa es
invisible**: el segundo request lleva otro `Authorization`, el router lo enruta
bien, y `$request->user()` devuelve el usuario del primero con sus relaciones
ya cargadas.

Lo que produce:

- *"A no ve lo de B"* corre las dos veces como A. Verde, sin haber probado
  jamás el aislamiento.
- *"sin la ability 403, con la ability 200"* da 403 las dos veces, porque
  `MkAuthenticate` hizo su `loadMissing(['roles.abilities', 'directAbilities'])`
  una sola vez, **antes** del `giveAbilityTo()`. El 403 legítimo y el espurio
  son idénticos.

El fix va en el `TestCase` **base**, no en cada test: la regla la tendría que
recordar quien escribe el test siguiente, y olvidarla **no da error, da un
verde**.

```php
// tests/TestCase.php
protected function call($method, $uri, $parameters = [], $cookies = [],
                       $files = [], $server = [], $content = null)
{
    $this->app?->make('auth')->forgetGuards();

    return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
}
```

Un guard olvidado no le cuesta nada al test: `mk.auth` lo vuelve a resolver del
token en cada request, que es justo lo que se quiere medir.

**Lo que le falta al paquete**: un trait de testing que traiga `bearerFor()`
**y** el reset del guard. Hoy cada consumer descubre las dos cosas por
separado, y la segunda sólo se descubre cuando un test miente.

### 6.11 `MK_AUTO_DISCOVER_ABILITIES` prendido contamina la suite

```xml
<!-- phpunit.xml -->
<env name="MK_AUTO_DISCOVER_ABILITIES" value="false"/>
```

El default del paquete ya es `false`, así que esto sólo hace falta si tu `.env`
lo prende — **y si lo prendiste, prendelo con esta línea puesta**. Con
auto-discovery activo, el provider escanea atributos y escribe en la tabla
`abilities` en **cada boot**: filas que ningún test creó, y asserts que pasan
por datos que aparecieron solos.

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

Toda respuesta exitosa (200 OK) garantiza la presencia de la llave `data` y, en colecciones, la llave `__extraData` (sibling top-level de `data`). Post-R-PKG-024 v1.7.0 GA — single-level envelope OBLIGATORIO. Post-R-PKG-032 v1.8.0 MAJOR — pagination metadata se agrupa bajo `__extraData.pagination` (5 LengthAware keys o 3 Cursor keys); custom keys (audit_checked, request_id, etc.) siguen planas. Ver [`docs/UPGRADE_1.7_1.8.md`](../UPGRADE_1.7_1.8.md) para el migration guide completo al bumpear de v1.7.x → v1.8.0+.

---

## 💡 Consideraciones de Performance y Seguridad

1.  **`allowedIncludes`**: Siempre define qué relaciones puede pedir el frontend para evitar fugas de información.
2.  **`auto_cache`**: Úsalo para tablas con mucha lectura y poca escritura. MK-Director invalidará los tags de caché automáticamente.
3.  **`MK_DIRECTOR_DEBUG`**: Manténlo en `true` solo en local para ver el análisis de queries y tiempos de ejecución. Vive en `mk_director.debug.enabled`; en código leelo con `MkDebugConfig::enabled()`, **nunca** como `config('mk_director.debug')` en contexto booleano — esa llave es un **array**, y un array no vacío siempre es truthy (ver CHANGELOG `[UNRELEASED]`: la llave duplicada que dejaba el switch inerte).
