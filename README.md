# makroz/director-laravel

> **Part of the [@makroz/* suite](https://github.com/makroz/MK-Director)** — Laravel 13+ core framework for rapid application development with MME (MVC Modular Encapsulated) structure.

[![Packagist](https://img.shields.io/packagist/v/makroz/director-laravel)](https://packagist.org/packages/makroz/director-laravel)
[![License](https://img.shields.io/badge/license-proprietary-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4-blue.svg)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-red.svg)](https://laravel.com)

El motor de backend de MK-Director. Ofrece una capa de abstracción potente para APIs CRUD con estructura MME nativa (cada módulo es autocontenido y se comunica solo vía API pública).

> 🚀 **¿Arrancando un proyecto?** Empezá por **`docs/guides/ARRANQUE.md`** del
> monorepo: de cero a una API corriendo, con el orden exacto de los comandos y
> las salidas reales. Después, **`docs/guides/TRAMPAS.md`** — lo que muerde
> cuando ya está andando.
>
> 📖 **[Guía Completa del Desarrollador](DEVELOPER_GUIDE.md)**: Configuración, CRUD, ListManager, Plugins y MME.

## Características Core

- **Model & Builder**: Soporte nativo para `cacheGet()`, `cacheFirst()` y `cacheFind()`.
- **Auto-Cache Plugin**: Flushing automático de tags de cache al detectar operaciones de escritura en la DB.
- **Magic CRUD (SmartController)**: ABM declarativo extendiendo `Mk\Director\Controllers\SmartController` y configurando `$mkConfig`. Los plugins (`MkAuditLoggerPlugin`, `MkMultiTenantPlugin`) hookan automáticamente. El scaffolder `mk:module` lo genera por default. **`CRUDSmart::show/update/destroy` aceptan `string|int $id`** (v1.6.0-rc6) para soporte nativo de consumers con `HasUuids`. **`HasRoles::pivotExtras()` / `HasAbilities::abilityPivotExtras()` ahora son `public`** (v1.6.0-rc7) — el Repository scaffoldeado los consume directamente sin hardcodear el FQCN de la pivot polimórfica.
- **FileStoragePlugin (R-PKG-045 — auto-register + mapeo explícito `request field → column`)**: `FileStoragePlugin` se carga por default vía `MkServiceProvider::registerPlugins()` (opt-out via `'features.file_storage_plugin' => false`). Soporta mapeo explícito D1 `'fields' => ['photo' => 'photo_path']` (request field `'photo'` → column `'photo_path'`) que resuelve el caso real `--profile-fields=photo_path` sin reimplementar lógica en Service. BC-safe: `'fields' => ['photo']` (array plano pre-R-PKG-045) sigue funcionando como identity map (`['photo' => 'photo']`). Hook = `beforeSave` (corre ANTES del `Model::create`/`update`). Ver [`DEVELOPER_GUIDE.md § 5.4`](DEVELOPER_GUIDE.md#54-plugins-disponibles-en-el-core).
- **RBAC scaffolder (`mk:module --with-rbac`)**: genera un trío RBAC completo (User + Role + Ability + 2 pivots con FK + 3 Policies + RbacService + ServiceProvider con Gate bindings) en un solo comando. Por-módulo, scope-aislated. Ver [DEVELOPER_GUIDE.md § 3.5](DEVELOPER_GUIDE.md#-35-scaffolding-modules-with-rbac---with-rbac).
- **Auth-user scaffolder (`mk:make:auth-user`)**: scope de autenticación autocontenido con `AuthUser`, `AuthController` y tokens Sanctum. **CRUD, RBAC y el enum `Status` vienen ON por default** (opt-out con `--no-crud` / `--no-rbac` / `--no-status`; los viejos `--with-*` fueron eliminados en R-PKG-047 D2). Soporta además `--login-field=<campo>`, `--profile-fields=<csv>` (tipos custom, `unique` vía prefijo `!`, `:file` para auto-wire de storage), `--verify-email`, `--managed-by=<Scope>` y `--kind=manager|consumer`. La tabla completa está más abajo; el arranque paso a paso, en `docs/guides/ARRANQUE.md` del monorepo.
- **FEEDBACK10 (RETO corrida 10 — Service hooks, auth routes, RBAC, `--kind`)**: 7 hallazgos 🔴 cerrados. `CRUDSmart::getService()` ahora resuelve Services auto-resolvibles vía `app()->make()` (F10-B03, hooks del Service dejan de estar muertos); hooks scaffoldeados `afterCreate/afterUpdate/afterDelete` cierran con `return null` (F10-B04); rutas auth alineadas a los métodos reales de `BaseAuthController` (`forgotPassword`/`resetPassword`, + `logoutAll`/`changePassword` expuestos) (F10-B06); `RoleResource` scaffoldeado ya no expone `description` (columna inexistente en `roles`) (F10-B07); `FileStoragePlugin` scaffold wiring fix — `plugins` (lista de clases) vs `plugins_config` (config) como keys separadas (F10-B02). Ver [DEVELOPER_GUIDE.md § 3.17](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
- **Email-OTP password change + `PATCH me` ampliado (SDD `2026-07-15-profile-edit-password-otp`)**: cambio de contraseña vía **PIN de un solo uso** enviado por email (sin `current_password`) — endpoints `POST password/code/request` + `POST password/code/confirm`, motor `EmailOtpService` + tabla genérica `verification_codes` (`purpose` reusable para 2FA/email-verify; PIN **bcrypt-hasheado**, single-use, expiry, attempt-lock, throttle keyeado por `(scope, purpose, identifier)`). `PATCH me` ahora también edita `email` (unique ignorando self) + `avatar` (vía `FileStoragePlugin`). Email **desacoplado por evento** (`auth.password_change_code.requested` con el PIN en plano → el consumer cablea Mailable + listener; desbloquea de paso el `auth.password_reset.requested` muerto). Incluye fix del `BadMethodCallException` en `AuthUser::setAuthPassword()` que rompía `changePassword`/`resetPassword`, y del scaffolder que no emitía `getAvatarUrlAttribute` cuando el CRUD estaba apagado. Ver [DEVELOPER_GUIDE.md § 3.18](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
  - **"Olvidé mi contraseña" por PIN (reset OTP, NO autenticado)**: variante PIN del clásico `password/forgot`/`password/reset` (ambos coexisten, aditivo/BC) para el usuario deslogueado — endpoints **públicos** `POST password/reset/code/request` + `POST password/reset/code/confirm`, mismo `EmailOtpService`/`verification_codes` con `purpose='password_reset'`. **Anti-enumeration**: el request SIEMPRE devuelve `200` genérico (aunque la cuenta no exista o el throttle-cuenta dispare — sin `429`); el confirm colapsa email desconocido y código equivocado al MISMO `422` genérico. En éxito revoca **todos** los tokens Sanctum (reset = logout global) y emite `auth.password_reset_code.requested` (PIN en plano → Mailable+listener del consumer) / `auth.password_reset.success`. Ver [DEVELOPER_GUIDE.md § 3.18.8](DEVELOPER_GUIDE.md).
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

> 🔴 **Packagist te da `v1.8.0`, del 29 de junio de 2026.** La rama `dev` está
> **más de 200 commits** por delante y **no tiene tag**. Lo que te perdés instalando
> de Packagist: todo el flujo OTP de contraseña, el fix de `canMk()` en las
> Policies generadas, el fix del `$signature` que hace `mk:make:auth-user`
> inusable, el gate de membresía de tenant y el BC de `?restore_state=1`.
> Y **nada de eso avisa**.
>
> Guía completa de arranque, con el `path repository` y el orden exacto de los
> comandos: **`docs/guides/ARRANQUE.md` del monorepo**.

```bash
# Consumo desde el checkout local (lo que usan RETO y NetPizza).
composer config repositories.mk-director '{
  "type": "path",
  "url": "../../mk-director/packagist/mk-director-laravel",
  "options": {
    "symlink": true,
    "versions": { "makroz/director-laravel": "1.99.x-dev" }
  }
}'
composer require "makroz/director-laravel:*@dev"
```

Publicá la configuración si vas a overridear algo:

```bash
php artisan vendor:publish --tag=mk-config
php artisan migrate
```

⚠️ **Las migraciones del paquete NO se publican**: se cargan solas
(`loadMigrationsFrom`). En un Laravel limpio, `php artisan migrate` corre las
16 sin ningún `vendor:publish` previo. Publicarlas te deja copias que después
divergen del paquete.

## Comandos Artisan

Los 17 comandos que registra el paquete (`php artisan list mk`).

| Comando | Qué hace |
|---|---|
| `mk:make:auth-user {Scope}` | Scaffoldea un scope de auth completo: modelo (`extends AuthUser`), migración, `AuthController`, rutas, ServiceProvider auto-registrado, **CRUD + RBAC + enum `Status`** y Policies default-deny. **30 archivos** en un Laravel limpio. |
| `mk:module {Name}` | Módulo CRUD estándar (Controller, Model, Service, Repository, DTO, Contracts). |
| `mk:module {Name} --with-rbac` | Módulo con trío RBAC propio (User + Role + Ability + 2 pivots + 3 Policies + RbacService + ServiceProvider con Gate bindings). ⚠️ Su modelo define `hasAbility()` — **no** `canMk()`, que es el del pack `mk:make:auth-user`. |
| `mk:discover-abilities [--module=*] [--force] [--dry-run] [--json]` | Puebla `abilities` desde el `discoverAbilities()` del provider (**fuente única si existe**), o si no desde atributos `#[Ability]` + docblocks `@mk-ability`. UPSERT idempotente. |
| `mk:prune-abilities` | Saca de la tabla las abilities que el código ya no declara. Complemento del anterior. |
| `mk:auth:create-super-admin` | El primer usuario (`auth_scope`, rol `super-admin`, ability `*`). Corre el `{Scope}RolesSeeder` solo. Interactivo, o con `--email/--name/--password`. |
| `mk:fix:sanctum-uuids [--dry-run]` | Parchea la migración de Sanctum a `uuidMorphs()`. `mk:make:auth-user` ya lo invoca solo. |
| `mk:status` | Diagnóstico de los controllers MK y su configuración. |
| `mk:lint:boundaries [--strict]` | Linter de R-MK-001 (imports cross-module). Check de CI obligatorio. |
| `mk:security-lint` | Auditoría estática: modelos Eloquent y config de `MkMultiTenantPlugin`. |
| `mk:generate-docs` | OpenAPI estático desde los constructores MK. |
| `mk:update` | Actualización interactiva del ecosistema + auditoría de riesgos de compatibilidad. |
| `mk:dto` / `mk:service` | Un DTO / un Service sueltos, con el estándar del paquete. |
| `mk:skill:list` / `mk:skill:deploy {nombre}` | Skills de agente para el proyecto (paquete + agencia + locales). |
| `mk:migrate-is-active` / `mk:migrate-status-to-int` | Migraciones de datos a `status` int-backed. |

### Flags de `mk:make:auth-user`

> 🔴 **BC break R-PKG-047 D2 — `--with-crud`, `--with-auth-rbac`,
> `--with-status` y `--status-values` NO EXISTEN MÁS.** Hoy son defaults ON.
> Un script viejo muere con `The "--with-crud" option does not exist.`

| Flag | Qué hace |
|---|---|
| `--login-field=<campo>` | Campo de login. Default `email`; casos comunes `ci`, `phone`, `username`. |
| `--profile-fields=<csv>` | Columnas extra del scope. Sintaxis `key[:type]` con 8 tipos (`string` default, `text`, `int`, `decimal`, `bool`, `date`, `datetime`, `json`); prefijo `!` = `unique`; sufijo `:file` = auto-wire de `FileStoragePlugin`. |
| `--profile-fields-required=<csv>` | Pasa esos fields de `nullable` a `required` en la validación. |
| `--kind=manager\|consumer` | `manager` (default) = scope completo. `consumer` = self-profile-only, administrado por otro scope. **Requiere `--managed-by`.** ⚠️ El consumer **pierde los 4 endpoints de OTP**. |
| `--managed-by=<Manager>` | Publica `/api/{manager}/{scopePlural}` gateado con `mk.auth:{manager}`. ⚠️ **No valida que el manager exista**: con un nombre inventado genera 21 rutas que responden `500 Auth guard [x] is not defined`. El manager va **primero**. |
| `--verify-email` | `email_verified_at` + `/email/verify/{id}/{hash}` (URL firmada) + `/email/resend`. Sólo con `--login-field=email`. |
| `--with-permissions-endpoint` | `GET /api/{scope}/auth/me/permissions` con el desglose de abilities. |
| `--no-crud` / `--no-rbac` / `--no-status` | Los opt-out de los tres defaults. |
| `--skip-auth-wire` | No editar `config/auth.php` (sólo imprimir los snippets). |
| `--skip-policies` | No generar las Policies default-deny. |
| `--setup-sanctum` / `--migrate` / `--seed` / `--discover` | Pasos post-scaffold. Sanctum ya se auto-invoca. |
| `--force-cors` | Re-escribir `config/cors.php` aunque exista. |
| `--multi-tenant` | Emite `client_id` en la migración y el `$fillable`. ⚠️ El global scope filtra por **`tenant_id`**: hay que alinearlos. Ver `docs/guides/MULTI_TENANT.md`. |

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
