# makroz/director-laravel

> **Part of the [@makroz/* suite](https://github.com/makroz/MK-Director)** — Laravel 13+ core framework for rapid application development with MME (MVC Modular Encapsulated) structure.

[![Packagist](https://img.shields.io/packagist/v/makroz/director-laravel)](https://packagist.org/packages/makroz/director-laravel)
[![License](https://img.shields.io/badge/license-proprietary-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)

El motor de backend de MK-Director. Ofrece una capa de abstracción potente para APIs CRUD con estructura MME nativa (cada módulo es autocontenido y se comunica solo vía API pública).

> 📖 **[Guía Completa del Desarrollador](DEVELOPER_GUIDE.md)**: Instalación, Configuración, CRUD, ListManager, Plugins y MME.

## Características Core

- **Model & Builder**: Soporte nativo para `cacheGet()`, `cacheFirst()` y `cacheFind()`.
- **Auto-Cache Plugin**: Flushing automático de tags de cache al detectar operaciones de escritura en la DB.
- **Magic CRUD (SmartController)**: ABM declarativo extendiendo `Mk\Director\Controllers\SmartController` y configurando `$mkConfig`. Los plugins (`MkAuditLoggerPlugin`, `MkMultiTenantPlugin`) hookan automáticamente. El scaffolder `mk:module` lo genera por default. **`CRUDSmart::show/update/destroy` aceptan `string|int $id`** (v1.6.0-rc6) para soporte nativo de consumers con `HasUuids`. **`HasRoles::pivotExtras()` / `HasAbilities::abilityPivotExtras()` ahora son `public`** (v1.6.0-rc7) — el Repository scaffoldeado los consume directamente sin hardcodear el FQCN de la pivot polimórfica.
- **FileStoragePlugin (R-PKG-045 — auto-register + mapeo explícito `request field → column`)**: `FileStoragePlugin` se carga por default vía `MkServiceProvider::registerPlugins()` (opt-out via `'features.file_storage_plugin' => false`). Soporta mapeo explícito D1 `'fields' => ['photo' => 'photo_path']` (request field `'photo'` → column `'photo_path'`) que resuelve el caso real `--profile-fields=photo_path` sin reimplementar lógica en Service. BC-safe: `'fields' => ['photo']` (array plano pre-R-PKG-045) sigue funcionando como identity map (`['photo' => 'photo']`). Hook = `beforeSave` (corre ANTES del `Model::create`/`update`). Ver [`DEVELOPER_GUIDE.md § 5.4`](DEVELOPER_GUIDE.md#54-plugins-disponibles-en-el-core).
- **RBAC scaffolder (`mk:module --with-rbac`)**: genera un trío RBAC completo (User + Role + Ability + 2 pivots con FK + 3 Policies + RbacService + ServiceProvider con Gate bindings) en un solo comando. Por-módulo, scope-aislated. Ver [DEVELOPER_GUIDE.md § 3.5](DEVELOPER_GUIDE.md#-35-scaffolding-modules-with-rbac---with-rbac).
- **Auth-user scaffolder (`mk:make:auth-user`)**: scope de autenticación autocontenido con `AuthUser`, `AuthController`, tokens Sanctum. Soporta `--login-field=<campo>`, `--with-auth-rbac`, `--profile-fields=<csv>` (con tipos custom y constraint `unique` vía prefijo `!`), `--verify-email`, `--with-crud` (CRUD completo + RBAC triada + DDD artifacts en una corrida), `--managed-by=<Scope>` (recurso admin-scoped administrado por OTRO scope) y `--kind=manager|consumer` (F10-B08, default `manager`, BC — `consumer` es un scope self-profile-only sin CRUD/roles/abilities propios, administrado por su `--managed-by`). Ver [DEVELOPER_GUIDE.md § 3.6-3.17](DEVELOPER_GUIDE.md).
- **FEEDBACK10 (RETO corrida 10 — Service hooks, auth routes, RBAC, `--kind`)**: 7 hallazgos 🔴 cerrados. `CRUDSmart::getService()` ahora resuelve Services auto-resolvibles vía `app()->make()` (F10-B03, hooks del Service dejan de estar muertos); hooks scaffoldeados `afterCreate/afterUpdate/afterDelete` cierran con `return null` (F10-B04); rutas auth alineadas a los métodos reales de `BaseAuthController` (`forgotPassword`/`resetPassword`, + `logoutAll`/`changePassword` expuestos) (F10-B06); `RoleResource` scaffoldeado ya no expone `description` (columna inexistente en `roles`) (F10-B07); `FileStoragePlugin` scaffold wiring fix — `plugins` (lista de clases) vs `plugins_config` (config) como keys separadas (F10-B02). Ver [DEVELOPER_GUIDE.md § 3.17](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
- **Email-OTP password change + `PATCH me` ampliado (SDD `2026-07-15-profile-edit-password-otp`)**: cambio de contraseña vía **PIN de un solo uso** enviado por email (sin `current_password`) — endpoints `POST password/code/request` + `POST password/code/confirm`, motor `EmailOtpService` + tabla genérica `verification_codes` (`purpose` reusable para 2FA/email-verify; PIN **bcrypt-hasheado**, single-use, expiry, attempt-lock, throttle keyeado por `(scope, purpose, identifier)`). `PATCH me` ahora también edita `email` (unique ignorando self) + `avatar` (vía `FileStoragePlugin`). Email **desacoplado por evento** (`auth.password_change_code.requested` con el PIN en plano → el consumer cablea Mailable + listener; desbloquea de paso el `auth.password_reset.requested` muerto). Incluye fix del `BadMethodCallException` en `AuthUser::setAuthPassword()` que rompía `changePassword`/`resetPassword`, y del scaffolder que no emitía `getAvatarUrlAttribute` sin `--with-crud`. Ver [DEVELOPER_GUIDE.md § 3.18](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
- **Single-level envelope response shape (R-PKG-024 v1.7.0 GA — OBLIGATORIO)**: `BaseController::sendResponse()` SIEMPRE emite `{success, message, data, __extraData, debugMsg}` con `data` como array directo de items (NO paginator nested) y `__extraData` como **sibling top-level de `data`**. Forma canónica que matchea `@makroz/core` `MkResponse<T>` y que consume `@makroz/web` `useMkList` + `@makroz/mobile` `useMkInfiniteList`. **PROHIBIDO `data.data` en cualquier endpoint, scaffolder, stub o DTO**. El flag opt-in `mk_director.response.top_level_extra_data` (rc12) está **ELIMINADO** post-v1.7.0. Auditoría con `php artisan mk:status --response-shape` (reporte `error` post-GA, no warning). Para paginadores, `BaseController::extractPaginationMetadata()` emite snake_case keys (`current_page`, `last_page`, `per_page`, `total`, `has_more_pages` para LengthAwarePaginator; `per_page`, `next_cursor`, `prev_cursor` para CursorPaginator) en `__extraData.pagination` agrupado (R-PKG-032 v1.8.0 — ver bullet siguiente).
- **Pagination envelope grouping (R-PKG-032 v1.8.0 MAJOR — OBLIGATORIO)**: post-v1.8.0, las 5 (LengthAwarePaginator) / 3 (CursorPaginator) snake_case keys de paginación se agrupan bajo `__extraData.pagination` (NO flat al top-level). Custom keys del consumer (`audit_checked`, `request_id`, etc.) siguen planas en `__extraData` — el grouping es SOLO para el contrato del paquete. **BC break clean** sin flag opt-in (siguiendo política R-PKG-024). Consumers en v1.7.x que lean `response.__extraData.last_page` flat deben migrar a `response.__extraData.pagination.last_page` (1 línea). Ver `docs/UPGRADE_1.7_1.8.md` para migration guide completo.
- **Auto-discover abilities seguro con `php artisan serve` (R-PKG-031 BUG-NEW-auto-discover-serve v1.7.1+)**: `MK_AUTO_DISCOVER_ABILITIES=true` ya NO bricked dev servers con HTTP 500 `Call to undefined function DiscoverAbilitiesCommand()`. El boot hook ahora skipa cuando `$_SERVER['argv']` incluye long-running CLI contexts (`serve`, `octane:start`, `horizon`, `queue:work|listen`, `schedule:work|run`) + usa `Artisan::call('mk:discover-abilities', [...])` en lugar del malformed `$this->app->call(Class, params)`. Se puede pinear el flag con seguridad en sandbox/dev.
- **RETO fase 14 feedback fixes (v1.8.1-rc0 — acumula al lote RELEASE_AT_END)**: 6 hallazgos pineados como fixes aditivos/BC-safe. (a) **`Schema::hasTable()` guard pre-`Artisan::call()` en auto-discover** (HALLAZGO-NEW-FASE14-01) — testing con `RefreshDatabase` ya NO rompe con `RuntimeException: Ninguna tabla de abilities existe`. (b) **`\Auth::forgetGuards()` post-logout** (HALLAZGO-NEW-FASE14-03) — testing Pest/PHPUnit ya NO cachea el user en el guard entre requests del mismo test. (c) Tabla `__extraData` opt-in para paginators en `DEVELOPER_GUIDE.md §1.4` (HALLAZGO-NEW-FASE14-02). (d) REST conventions DELETE 200+body en `§1.5` (HALLAZGO-NEW-FASE14-04). (e) Models per-scope vs globales en `§1.6` (HALLAZGO-NEW-FASE14-05). (f) `canMk()` vs `can()` vs `hasAbility()` en `§3.8.3` (HALLAZGO-NEW-FASE14-06). Sin cross-stack changes — `@makroz/core/web/mobile` NO requieren update.
- **S8 Fase 7 scaffolder hardening (v2.0.1-rc0 — RETO fase 6 clean rebuild feedback, HALLAZGO-NEW-FASE18-A/B/C — BC break documentado en C)**: 3 hallazgos pineados como fixes (A, B) + 1 BC break (C). (A) **`forgot()` llaves extras en `AuthController.stub`** — 5 líneas huérfanas + llave extra eliminadas; `php artisan route:list` ya NO crashea con `ParseError: syntax error, unexpected variable "$token"` en línea ~370. (B) **`me/permissions` route regression guard** — 7 tests source-parsing pineados para asegurar que el scaffolder pineá la route DENTRO del bloque `mk.auth:{scope}` (canónico desde R-PKG-043 FASE19-02). (C) **`mk.ability` per-route en CRUD** (BC break, R-G-033 autoriza) — las 21 rutas CRUD scaffoldeadas pinean `mk.ability:{scope}.{resource}.{action}` PER-ROUTE (no group-level). Defense-in-depth contra privilege escalation: editor con abilities reducidas ya NO puede ejecutar acciones sin ability check. Ver `DEVELOPER_GUIDE.md §3.15.1-3.15.3` + `CHANGELOG.md` § v2.0.1-rc0.
- **Refresh + reset + forgot implementación completa** (v1.6.0-rc4): `RefreshTokenParser` (Sanctum v4 `id|plaintext`), `TokenIssuer::rotateRefreshToken()` con defense-in-depth contra escalación de scope, persistencia en `{scope}_password_reset_tokens`.
- **List & Search Managers**: Parsing de strings complejos para búsquedas relacionales y joins dinámicos.
- **MME (MVC Modular Encapsulated)**: ModuleLoader auto-registra módulos, comunicación inter-módulo solo vía API pública.
- **Auth + RBAC**: Sistema completo con abilities, roles, scopes y middleware `MkAbility`.

## Instalación

```bash
composer require makroz/director-laravel
```

Publica la configuración y migraciones:

```bash
php artisan vendor:publish --tag=mk-config
php artisan vendor:publish --tag=mk-migrations
php artisan migrate
```

## Comandos Artisan

| Comando | Descripción |
|---|---|
| `php artisan mk:module {Name}` | Scaffolding de módulo CRUD estándar (Controller, Model, Service, Repository, DTO, etc.). |
| `php artisan mk:module {Name} --with-rbac` | Scaffolding de módulo **con trío RBAC completo** (User + Role + Ability + 2 pivots + 3 Policies + RbacService + ServiceProvider con Gate bindings). Genera 20 archivos. **Nuevo en v1.5.0**. |
| `php artisan mk:discover-abilities {--module=*} {--force}` | Auto-pobla `{scope}_abilities` desde el provider del módulo (preferred), atributos PHP 8.4 (`#[\Mk\Director\Auth\Attributes\Ability]`), o docblock (`@mk-ability`). UPSERT idempotente. **Nuevo en v1.5.0-rc2**. |
| `php artisan mk:make:auth-user {Scope}` | Scaffolding de scope de autenticación con `AuthUser`, `AuthController`, tokens Sanctum. |
| `php artisan mk:make:auth-user {Scope} --login-field=<campo>` | Variante con campo de login configurable (default `email`). Casos: `ci` (Bolivia), `phone`, `username`, `documento`. **Nuevo en v1.5.0-rc3**. |
| `php artisan mk:make:auth-user {Scope} --with-auth-rbac` | Variante con RBAC integration: ability checks en `/me` y `/logout`, rate limit en `/login`/`/forgot`/`/reset`, audit events vía `AuthEvent`. Default BC: idéntico a v1.5.0-rc3 sin flag. **Nuevo en v1.5.0-rc4**. |
| `php artisan mk:make:auth-user {Scope} --profile-fields=name,dni,phone` | Variante con columnas adicionales para el scope (per-scope, no compartidas). Cada field se expone vía `GET /me`, `PATCH /me` y `POST /register`. Ortogonal con `--login-field` y `--with-auth-rbac`. **Nuevo en v1.5.0-rc5**. |
| `php artisan mk:make:auth-user {Scope} --profile-fields=name:string,birthdate:date,age:int` | Extensión con tipos custom: cada field puede ser `string`, `text`, `int`, `decimal`, `bool`, `date`, `datetime` o `json`. Sintaxis `key:type` (sin `:` = `string`, BC). Ortogonal con `--login-field`, `--with-auth-rbac` y `--verify-email`. **Nuevo en v1.6.0-rc1**. |
| `php artisan mk:make:auth-user {Scope} --profile-fields-required=name,email` | Override del validation default `nullable` a `required` para profile fields específicos. Default BC: todos los profile fields son nullable (consistente con migration `-->`). **Nuevo en v1.6.0-rc4** (BUG-03 fix). |
| `php artisan mk:make:auth-user {Scope} --profile-fields=name,!ci,phone` | Prefijo `!` marca el field como `unique` en la migration (`!ci` → `$table->string('ci')->unique()->nullable()`). Ortogonal con `--profile-fields-types`. **Nuevo en v1.6.0-rc4** (BUG-09 fix). |
| `php artisan mk:make:auth-user {Scope} --with-crud` | Genera CRUD completo del scope + RBAC triada: `AdminController` + `RoleController` + `AbilityController` (SmartController) + 4 FormRequests + 3 JsonResources + 2 DTOs readonly + Repository + Interface + Service + Factory DDD + Seeder con 4 roles predefinidos. ServiceProvider extendido con binding. **Nuevo en v1.6.0-rc4** (MEJORA-02) — **hardened en v1.6.0-rc5** (R-PKG-015 BUG-NEW-05/06 fixes: import statements en routes, FK overrides en el modelo) — **privilege escalation fix en v2.0.1-rc0** (HALLAZGO-NEW-FASE18-C, BC break documentado): las 21 rutas CRUD pinean `mk.ability:{scope}.{resource}.{action}` PER-ROUTE (no group-level). Defense-in-depth contra editores con abilities reducidas que ejecutaban acciones sin ability check. **Migration**: `php artisan mk:discover-abilities --force` antes de bumpear para pineá abilities. Ver `DEVELOPER_GUIDE.md §3.15.3` + `CHANGELOG.md` § v2.0.1-rc0. |
| `php artisan mk:make:auth-user {Scope} --kind=manager\|consumer --managed-by=<Manager>` | F10-B08: `--kind` (default `manager`, BC) tipa el scope. `consumer` **requiere** `--managed-by=<Scope existente>` — genera un scope self-profile-only (`Http/Routes/api.php` reducido a auth + `PATCH me`, SIN CRUD/`/roles`/`/abilities` propios, SIN `RoleController`/`AbilityController`); el manager administra su CRUD vía `/api/{manager}/{scopePlural}`. Flujo canónico: `mk:make:auth-user Admin --with-crud` luego `mk:make:auth-user Member --kind=consumer --managed-by=Admin --with-crud`. **Nuevo en FEEDBACK10** (RETO corrida 10). Ver `DEVELOPER_GUIDE.md §3.17.5`. |
| `php artisan mk:fix:sanctum-uuids` | Parchea automáticamente la migration `create_personal_access_tokens_table` cambiando `$table->morphs('tokenable')` por `$table->uuidMorphs('tokenable')`. Necesario cuando el consumer usa `HasUuids` en sus modelos `AuthUser`. Idempotente. Soporta `--dry-run`. **Nuevo en v1.6.0-rc5** (R-PKG-015 BUG-NEW-09). |
| `php artisan mk:make:auth-user {Scope} --verify-email` | Variante con verificación por email: columna `email_verified_at`, endpoints `/email/verify/{id}/{hash}` (signed URL) y `/email/resend`, dispatch de `Illuminate\Auth\Notifications\VerifyEmail` en `/register`. Default BC: idéntico a v1.5.0-rc4 sin flag. Solo aplica si `--login-field=email`. **Nuevo en v1.5.0-rc5**. |
| `php artisan mk:auth:create-super-admin --roles=super-admin,admin,editor,viewer` | Siembra los 4 roles predefinidos con abilities específicas (`*`, CRUD completo, view+update, view-only) en una sola corrida. Default BC: solo super-admin. **Nuevo en v1.6.0-rc4** (MEJORA-04). Soporta `--name="..."` flag y fallback chain que autogenera `name` del email local-part en modo `--no-interaction` (v1.6.0-rc6). |
| `php artisan mk:lint:boundaries` | Linter de R-MK-001: detecta imports cross-module en código de apps que usan el paquete. Required CI check. |
| `php artisan mk:discover-abilities` | Auto-descubre abilities de las Policies de un módulo y las inserta en la tabla `{scope}_abilities`. Companion de `--with-rbac`. |
| `php artisan mk:security-lint` | Auditoría de seguridad: secrets, RBAC, tenant isolation, etc. |

## Configuración

Habilita features en `config/mk_director.php`:

```php
'features' => [
    'auto_cache' => true,
    'dynamic_joins' => true,
    'mme_enforcement' => true,
],
```

## Stack

- PHP 8.4+ (PHP 8.5-clean: `MkBelongsToMany::from()` y accessors de `HasTenantScope` no emiten deprecation warnings)
- Laravel 13+
- Illuminate components (Support, Database, HTTP)

## Ecosistema @makroz/*

| Package | Description |
|---------|-------------|
| [`@makroz/core`](https://www.npmjs.com/package/@makroz/core) | Tipos compartidos y validadores cross-stack |
| [`@makroz/web`](https://www.npmjs.com/package/@makroz/web) | Next.js 16 + shadcn/ui module layer |
| [`@makroz/mobile`](https://www.npmjs.com/package/@makroz/mobile) | Expo SDK 56 + expo-router 6 module layer |
| `makroz/director-laravel` (este) | Laravel 13 backend con MME |
| `create-makroz-director` | CLI para scaffolding de apps nuevas |

## Licencia

Proprietary — © Mario Guzmán. Ver [LICENSE](LICENSE) si está disponible.
