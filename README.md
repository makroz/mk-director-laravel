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
- **Auth-user scaffolder (`mk:make:auth-user`)**: scope de autenticación autocontenido con `AuthUser`, `AuthController` y tokens Sanctum. **CRUD, RBAC y el enum `Status` vienen ON por default** (opt-out con `--no-crud` / `--no-rbac` / `--no-status`; los viejos `--with-*` fueron eliminados en R-PKG-047 D2). Soporta además `--login-field=<campo>`, `--profile-fields=<csv>` (tipos custom, `unique` vía prefijo `!`, `:file` para auto-wire de storage), `--verify-email`, `--two-factor[=optional|required]` (segundo factor por TOTP, apagado por defecto), `--managed-by=<Scope>` y `--kind=manager|consumer`. La tabla completa está más abajo; el arranque paso a paso, en `docs/guides/ARRANQUE.md` del monorepo.
- **Login con Google y Apple por ID token (opt-in por ruta)**: `BaseAuthController::socialLogin()` verifica el ID token que el front obtuvo con Google Identity Services / Sign in with Apple (RS256 contra el JWKS del proveedor, cacheado según su `max-age`; emisor, audiencia, vigencia y el nonce hasheado de Apple), lo vincula al usuario del scope por `(provider, sub)` en `mk_social_identities` y emite la **misma sesión** que el login con contraseña, segundo factor incluido. Los client ids se piden en cada login a `SocialProviderConfigResolver` (default: `mk_director.auth.social.providers`; el consumer puede bindear uno que lea su base). El alta la decide el consumer (`createSocialUser()`), y el vínculo por email está **apagado** por defecto (`socialAutoLinkByEmail()`). Ningún scope expone la ruta hasta que el consumer la agrega. Ver [DEVELOPER_GUIDE.md § 3.22](DEVELOPER_GUIDE.md).
- **Sesión larga por scope, con detección de reutilización**: cada scope declara la vida de su refresh y si rota (`refreshTtlSeconds()`, `rotatesRefreshTokens()`; `null` = la config global). Con rotación, un refresh ya usado que vuelve cierra la sesión entera (`401 ERR_REFRESH_REUSED`), y cambiar la clave conserva la sesión de quien la cambia. Ver [DEVELOPER_GUIDE.md § 3.23](DEVELOPER_GUIDE.md).
- **FEEDBACK10 (RETO corrida 10 — Service hooks, auth routes, RBAC, `--kind`)**: 7 hallazgos 🔴 cerrados. `CRUDSmart::getService()` ahora resuelve Services auto-resolvibles vía `app()->make()` (F10-B03, hooks del Service dejan de estar muertos); hooks scaffoldeados `afterCreate/afterUpdate/afterDelete` cierran con `return null` (F10-B04); rutas auth alineadas a los métodos reales de `BaseAuthController` (`forgotPassword`/`resetPassword`, + `logoutAll`/`changePassword` expuestos) (F10-B06); `RoleResource` scaffoldeado ya no expone `description` (columna inexistente en `roles`) (F10-B07); `FileStoragePlugin` scaffold wiring fix — `plugins` (lista de clases) vs `plugins_config` (config) como keys separadas (F10-B02). Ver [DEVELOPER_GUIDE.md § 3.17](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
- **Email-OTP password change + `PATCH me` ampliado (SDD `2026-07-15-profile-edit-password-otp`)**: cambio de contraseña vía **PIN de un solo uso** enviado por email (sin `current_password`) — endpoints `POST password/code/request` + `POST password/code/confirm`, motor `EmailOtpService` + tabla genérica `verification_codes` (`purpose` reusable para 2FA/email-verify; PIN **bcrypt-hasheado**, single-use, expiry, attempt-lock, throttle keyeado por `(scope, purpose, identifier)`). `PATCH me` ahora también edita `email` (unique ignorando self) + `avatar` (vía `FileStoragePlugin`). Email **desacoplado por evento** (`auth.password_change_code.requested` con el PIN en plano → el consumer cablea Mailable + listener; desbloquea de paso el `auth.password_reset.requested` muerto). Incluye fix del `BadMethodCallException` en `AuthUser::setAuthPassword()` que rompía `changePassword`/`resetPassword`, y del scaffolder que no emitía `getAvatarUrlAttribute` cuando el CRUD estaba apagado. Ver [DEVELOPER_GUIDE.md § 3.18](DEVELOPER_GUIDE.md) + `CHANGELOG.md`.
  - **"Olvidé mi contraseña" por PIN (reset OTP, NO autenticado)**: variante PIN del clásico `password/forgot`/`password/reset` (ambos coexisten, aditivo/BC) para el usuario deslogueado — endpoints **públicos** `POST password/reset/code/request` + `POST password/reset/code/confirm`, mismo `EmailOtpService`/`verification_codes` con `purpose='password_reset'`. **Anti-enumeration**: el request SIEMPRE devuelve `200` genérico (aunque la cuenta no exista o el throttle-cuenta dispare — sin `429`); el confirm colapsa email desconocido y código equivocado al MISMO `422` genérico. En éxito revoca **todos** los tokens Sanctum (reset = logout global) y emite `auth.password_reset_code.requested` (PIN en plano → Mailable+listener del consumer) / `auth.password_reset.success`. Ver [DEVELOPER_GUIDE.md § 3.18.8](DEVELOPER_GUIDE.md).
- **Single-level envelope response shape (R-PKG-024 v1.7.0 GA — OBLIGATORIO)**: `BaseController::sendResponse()` SIEMPRE emite `{success, message, data, __extraData, debugMsg}` con `data` como array directo de items (NO paginator nested) y `__extraData` como **sibling top-level de `data`**. Forma canónica que matchea `@makroz/core` `MkResponse<T>` y que consume `@makroz/web` `useMkList` + `@makroz/mobile` `useMkInfiniteList`. **PROHIBIDO `data.data` en cualquier endpoint, scaffolder, stub o DTO**. El flag opt-in `mk_director.response.top_level_extra_data` (rc12) está **ELIMINADO** post-v1.7.0. Auditoría con `php artisan mk:status --response-shape` (reporte `error` post-GA, no warning). Para paginadores, `BaseController::extractPaginationMetadata()` emite snake_case keys (`current_page`, `last_page`, `per_page`, `total`, `has_more_pages` para LengthAwarePaginator; `per_page`, `next_cursor`, `prev_cursor` para CursorPaginator) en `__extraData.pagination` agrupado (R-PKG-032 v1.8.0 — ver bullet siguiente).
- **El sobre en las rutas escritas a mano, con salida para terceros (`force_envelope` + `envelope_except`)**: `mk_director.response.force_envelope` (env `MK_FORCE_ENVELOPE`, default `false`) engancha `MkApiEnvelope` como middleware global y envuelve en `{success, message, data}` toda `JsonResponse` de `mk_director.response.envelope_paths` (default `['api/*']`) que no traiga ya `success`. Una ruta con contrato de un **tercero** —un webhook de pagos o de mensajería que lee sus claves en la raíz del JSON— se deja afuera con `mk_director.response.envelope_except` (patrones de `Request::is()`, default `[]`, **gana** sobre `envelope_paths`): p. ej. `'envelope_except' => ['api/webhooks/*']`. Sin eso el tercero recibe `200 {"success": true, "data": {...}}` y no encuentra lo que busca (hallazgo 78). El alias `mk.envelope` sigue sirviendo para envolver sólo una parte de la API.
- **Pagination envelope grouping (R-PKG-032 v1.8.0 MAJOR — OBLIGATORIO)**: post-v1.8.0, las 5 (LengthAwarePaginator) / 3 (CursorPaginator) snake_case keys de paginación se agrupan bajo `__extraData.pagination` (NO flat al top-level). Custom keys del consumer (`audit_checked`, `request_id`, etc.) siguen planas en `__extraData` — el grouping es SOLO para el contrato del paquete. **BC break clean** sin flag opt-in (siguiendo política R-PKG-024). Consumers en v1.7.x que lean `response.__extraData.last_page` flat deben migrar a `response.__extraData.pagination.last_page` (1 línea). Ver `docs/UPGRADE_1.7_1.8.md` para migration guide completo.
- **Auto-discover abilities seguro con `php artisan serve` (R-PKG-031 BUG-NEW-auto-discover-serve v1.7.1+)**: `MK_AUTO_DISCOVER_ABILITIES=true` ya NO bricked dev servers con HTTP 500 `Call to undefined function DiscoverAbilitiesCommand()`. El boot hook ahora skipa cuando `$_SERVER['argv']` incluye long-running CLI contexts (`serve`, `octane:start`, `horizon`, `queue:work|listen`, `schedule:work|run`) + usa `Artisan::call('mk:discover-abilities', [...])` en lugar del malformed `$this->app->call(Class, params)`. Se puede pinear el flag con seguridad en sandbox/dev.
- **RETO fase 14 feedback fixes (v1.8.1-rc0 — acumula al lote RELEASE_AT_END)**: 6 hallazgos pineados como fixes aditivos/BC-safe. (a) **`Schema::hasTable()` guard pre-`Artisan::call()` en auto-discover** (HALLAZGO-NEW-FASE14-01) — testing con `RefreshDatabase` ya NO rompe con `RuntimeException: Ninguna tabla de abilities existe`. (b) **`\Auth::forgetGuards()` post-logout** (HALLAZGO-NEW-FASE14-03) — testing Pest/PHPUnit ya NO cachea el user en el guard entre requests del mismo test. (c) Tabla `__extraData` opt-in para paginators en `DEVELOPER_GUIDE.md §1.4` (HALLAZGO-NEW-FASE14-02). (d) REST conventions DELETE 200+body en `§1.5` (HALLAZGO-NEW-FASE14-04). (e) Models per-scope vs globales en `§1.6` (HALLAZGO-NEW-FASE14-05). (f) `canMk()` vs `can()` vs `hasAbility()` en `§3.8.3` (HALLAZGO-NEW-FASE14-06). Sin cross-stack changes — `@makroz/core/web/mobile` NO requieren update.
- **S8 Fase 7 scaffolder hardening (v2.0.1-rc0 — RETO fase 6 clean rebuild feedback, HALLAZGO-NEW-FASE18-A/B/C — BC break documentado en C)**: 3 hallazgos pineados como fixes (A, B) + 1 BC break (C). (A) **`forgot()` llaves extras en `AuthController.stub`** — 5 líneas huérfanas + llave extra eliminadas; `php artisan route:list` ya NO crashea con `ParseError: syntax error, unexpected variable "$token"` en línea ~370. (B) **`me/permissions` route regression guard** — 7 tests source-parsing pineados para asegurar que el scaffolder pineá la route DENTRO del bloque `mk.auth:{scope}` (canónico desde R-PKG-043 FASE19-02). (C) **`mk.ability` per-route en CRUD** (BC break, R-G-033 autoriza) — las 21 rutas CRUD scaffoldeadas pinean `mk.ability:{scope}.{resource}.{action}` PER-ROUTE (no group-level). Defense-in-depth contra privilege escalation: editor con abilities reducidas ya NO puede ejecutar acciones sin ability check. Ver `DEVELOPER_GUIDE.md §3.15.1-3.15.3` + `CHANGELOG.md` § v2.0.1-rc0.
- **Refresh + reset + forgot implementación completa** (v1.6.0-rc4): `RefreshTokenParser` (Sanctum v4 `id|plaintext`), `TokenIssuer::rotateRefreshToken()` con defense-in-depth contra escalación de scope, persistencia en `{scope}_password_reset_tokens`.
- **Motor de reportes (PDF · XLSX · CSV)**: un módulo declara sus columnas en un `ExportConfig` y su listado se exporta en los tres formatos. `GET /api/{modulo}?_export=xlsx` responde **202** con un `uuid`; el archivo lo arma un job y el front hace polling. Incluye historial de descargas, barra de progreso real, reportes CUSTOM (consulta propia, devuelve bytes) y el limpiador `mk:reports-clean`. 🔴 **Siempre async, sin modo sync**: un export síncrono se muere por `max_execution_time` o por memoria, y las dos formas son invisibles hasta que el listado crece — medido con 198.004 filas (~422 MB de JSON), el request ni llegaba a responder. 🔴 **El margen inferior del PDF lo mide mPDF**, no una constante: se recalcula en cada página con el alto real del pie, así que un pie personalizado por proyecto no queda pisado por el contenido. Un `ExportConfig` mínimo son **tres** métodos (`use ExportConfigDefaults`). Guía: [`docs/guides/EXPORT.md`](../../docs/guides/EXPORT.md) del monorepo.
- **Storage por roles y subidas directas (R2, S3, MinIO)**: el paquete guarda en el rol `public` o `private` y la app elige el disk de cada uno; `MkStorage` arma todos los links (firmados en el privado). El cliente sube al bucket en partes firmadas y el servidor revisa lo guardado (bytes, firma, megapíxeles) antes de que `attachUpload()` lo adjunte. Ver § Storage más abajo.
- **Notificaciones push (FCM u OneSignal)**: `MkPush::to($user)->send(new PushMessage(...))` a una persona, a un grupo o a un tema (`MkPush::subscribe/unsubscribe/topic`). El servicio se elige con `MK_PUSH_DRIVER` (`fcm`, `onesignal`, `log` o `null`, el default) y el resto del proyecto no se entera. Rutas de registro opt-in (`mk_director.push.register_routes`), envío encolado `afterCommit`, poda de los teléfonos que el servicio rechaza y de los que no se ven (`mk:push:prune`). La app lo monta con `MkPushProvider` de `@makroz/mobile`. Guía: [`docs/guides/push.md`](docs/guides/push.md) — push en 30 minutos, con las trampas medidas.
- **Escrituras atómicas en `CRUDSmart`**: `store()`, `update()` y `destroy()` envuelven la escritura y sus hooks `after*` en una transacción. Antes no lo hacían —mientras `storeMany()` sí—, así que el bulk era atómico y el single no: un `afterCreate` que tirara dejaba la fila escrita y devolvía 500.
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
(`loadMigrationsFrom`). En un Laravel limpio, `php artisan migrate` las corre
todas sin ningún `vendor:publish` previo. Publicarlas te deja copias que después
divergen del paquete.

## Comandos Artisan

Los comandos principales que registra el paquete (la lista entera: `php artisan list mk`).

| Comando | Qué hace |
|---|---|
| `mk:make:auth-user {Scope}` | Scaffoldea un scope de auth completo: modelo (`extends AuthUser`), migración, `AuthController`, rutas, ServiceProvider auto-registrado, **CRUD + RBAC + enum `Status`** y Policies default-deny. **30 archivos** en un Laravel limpio. |
| `mk:module {Name}` | Módulo CRUD estándar (Controller, Model, Service, Repository, DTO, Contracts). |
| `mk:module {Name} --with-rbac --middleware=<lista>` | Módulo con trío RBAC propio (User + Role + Ability + 2 pivots + 3 Policies + RbacService + ServiceProvider con Gate bindings). 🔴 `--middleware` (separado por coma, ej. `api,auth:admin`) es obligatorio: envuelve las 18 rutas y tiene que autenticar al usuario del módulo en el guard por defecto. ⚠️ Su modelo define `hasAbility()` — **no** `canMk()`, que es el del pack `mk:make:auth-user`. |
| `mk:discover-abilities [--module=*] [--force] [--dry-run] [--json]` | Puebla `abilities` desde el `discoverAbilities()` del provider (**fuente única si existe**), o si no desde atributos `#[Ability]` + docblocks `@mk-ability`. UPSERT idempotente. |
| `mk:prune-abilities` | Saca de la tabla las abilities que el código ya no declara. Complemento del anterior. |
| `mk:auth:create-super-admin [--scope=admin] [--tenant=] [--without-tenant] [--no-roles]` | El primer usuario de **cualquier scope** (`auth_scope`, rol `super-admin`, ability `*`). El modelo sale de `config/auth.php` (guard `{scope}` → provider → model); default `admin`. Corre el `{Scope}RolesSeeder` si existe. `--no-roles` crea sólo el usuario (scopes sin RBAC). Idempotente también con `tenant.fail_closed`. Interactivo, o con `--email`/`--{loginField}`, `--name`, `--password`. 🔴 **Si la tabla del scope tiene columna de tenant, `--tenant=<id\|slug>` es obligatorio**: un usuario sin tenant entra con cualquier `X-Tenant-ID`. `--without-tenant` es el opt-in explícito, y avisa del riesgo. Ver `DEVELOPER_GUIDE.md` § el tenant del primer usuario. |
| `mk:fix:sanctum-uuids [--dry-run]` | Parchea la migración de Sanctum a `uuidMorphs()`. `mk:make:auth-user` ya lo invoca solo. |
| `mk:status` | Diagnóstico de los controllers MK y su configuración. |
| `mk:reports-clean [--dry-run]` | Borra los reportes vencidos **y sus archivos**. Programalo con `Schedule::command('mk:reports-clean')->hourly()`. ⚠️ Sin él el disco crece para siempre con archivos que ya nadie puede pedir. |
| `mk:push:prune [--days=N] [--dry-run]` | Borra los teléfonos de push que no se vieron en N días (`MK_PUSH_PRUNE_AFTER_DAYS`, default 60; 0 la apaga). Programalo con `Schedule::command('mk:push:prune')->daily()`. ⚠️ OneSignal no siempre avisa de una dirección muerta: sin él quedan para siempre. |
| `mk:uploads:prune [--hours=N] [--dry-run]` | Limpia las subidas directas que nadie va a usar: aborta las vencidas sin terminar y borra el objeto de las terminadas que nadie adjuntó en N horas (default 24). Programalo una vez por día: ver «Storage». |
| `mk:help:sync [--dry-run]` | Indexa los manuales en Markdown de `resources/help` en `mk_help_articles`, todo o nada, y sube sus imágenes. Corrélo en cada deploy, después de `migrate`: ver «Ayuda y manuales». |
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
| `--plural=<snake_case>` | Plural del scope: tabla, `$table`, provider de `config/auth.php`, migración, rutas CRUD y abilities (`{scope}.{plural}.*`). Default `Str::plural()`, que es el inflector **inglés**: `Operador` → `operadors`. Ej: `mk:make:auth-user Operador --plural=operadores`. |
| `--profile-fields=<csv>` | Columnas extra del scope. Sintaxis `key[:type]` con 8 tipos (`string` default, `text`, `int`, `decimal`, `bool`, `date`, `datetime`, `json`); prefijo `!` = `unique`; sufijo `:file` = auto-wire de `FileStoragePlugin`. |
| `--profile-fields-required=<csv>` | Pasa esos fields de `nullable` a `required` en la validación. |
| `--kind=manager\|consumer` | `manager` (default) = scope completo. `consumer` = self-profile-only, administrado por otro scope. **Requiere `--managed-by`.** ⚠️ El consumer **pierde los 4 endpoints de OTP**. |
| `--managed-by=<Manager>` | Publica `/api/{manager}/{scopePlural}` gateado con `mk.auth:{manager}`. ⚠️ **No valida que el manager exista**: con un nombre inventado genera 21 rutas que responden `500 Auth guard [x] is not defined`. El manager va **primero**. |
| `--verify-email` | `email_verified_at` + `/email/verify/{id}/{hash}` (URL firmada) + `/email/resend`. Sólo con `--login-field=email`. Con `--with-register`, además despacha la verificación al registrar; sin él, el primer email sale por `/email/resend`. |
| `--two-factor[=optional\|required]` | **Opt-in.** Segundo factor por TOTP (app de autenticación): 4 columnas + sus casts, 6 endpoints con throttle propio y el override `twoFactorPolicy()`. Sin valor = `optional`; `required` hace que un usuario sin enrolar **no reciba sesión** en el login, sólo el enrolamiento. Omitido = `off`, o sea el login de siempre. Para un scope ya generado, la receta está en `DEVELOPER_GUIDE.md` § 3.20.7. |
| `--with-permissions-endpoint` | `GET /api/{scope}/auth/me/permissions` con el desglose de abilities. |
| `--no-crud` / `--no-rbac` / `--no-status` | Los opt-out de los tres defaults. `--no-rbac` **no** quita los throttles: toda ruta pública generada lleva uno. |
| `--skip-auth-wire` | No editar `config/auth.php` (sólo imprimir los snippets). |
| `--skip-policies` | No generar las Policies default-deny. |
| `--setup-sanctum` / `--migrate` / `--seed` / `--discover` | Pasos post-scaffold. Sanctum ya se auto-invoca. |
| `--force-cors` | Re-escribir `config/cors.php` aunque exista. |
| `--with-register` | **Opt-in.** Genera `POST /api/{scope}/auth/register` + `register()`, con throttle `rate_limits.register` (prefijo `{scope}-register`). 🔴 Sin CRUD es un alta **pública**: cualquiera se crea una cuenta. Con CRUD queda gateado por `mk.auth` + ability `create`. Falla combinado con `--multi-tenant`. |
| `--multi-tenant` | Emite `client_id` en la migración y el `$fillable`. Rechaza `--with-register` (un alta sin tenant crearía el usuario sin tenant). ⚠️ El global scope filtra por **`tenant_id`**: hay que alinearlos. Ver `docs/guides/MULTI_TENANT.md`. |

> **Toda ruta pública** que emite el scaffolder (login, refresh, forgot, reset,
> los del PIN, `email/verify`, `register`) lleva `throttle:` con prefijo propio
> (`throttle:5,1,{scope}-login`), también con `--no-rbac`. Sin prefijo,
> `ThrottleRequests` usa la misma clave (IP) para todos: login, forgot, reset y
> los del PIN —de todos los scopes— compartirían un solo contador. Los scopes
> generados antes de este cambio siguen sin prefijo: agregalo a mano en su
> `Http/Routes/api.php`. Con `--status` (el default) la migración agrega además
> un CHECK `{tabla}_status_check` en pgsql/mysql/mariadb.
>
> Los comentarios del código generado describen lo que hace ese código: sin ids
> de tickets internos ni historia del paquete, y sin docblocks sueltos. Los
> `@property` de los profile fields van al docblock de la clase del modelo.

## Configuración

Habilita features en `config/mk_director.php`:

```php
'features' => [
    'auto_cache' => true,
    'dynamic_joins' => true,
    'mme_enforcement' => true,
],
```

## Storage: archivos en R2, S3 o MinIO

El paquete no nombra proveedores. Guarda por **rol** —`public` (avatares,
galerías, todo lo que se sirve con un link fijo) y `private` (lo que sólo se ve
con permiso)— y la app decide qué disk de `config/filesystems.php` cumple cada
uno. Un disk `s3` cubre Cloudflare R2, Amazon S3 y MinIO sólo con variables de
entorno.

Sin configurar nada, los roles son `public` y `local`: lo de siempre, en el
disco del servidor.

### 1. Los buckets (uno público y uno privado por entorno)

En R2 el acceso público es del **bucket entero** (un subdominio `r2.dev` o un
dominio propio), no de cada objeto. Por eso cada entorno lleva dos:

| Bucket | Acceso | Para |
|---|---|---|
| `<app>-public-<entorno>` | dirección pública: `r2.dev` en desarrollo (tiene límite de tráfico), un dominio propio en producción | el rol `public` |
| `<app>-private-<entorno>` | ninguna: todo link sale firmado y vence | el rol `private` |

A **los dos** se les configura:

- **CORS** para los orígenes de los fronts web (una app nativa no lo necesita):
  métodos `PUT`, `GET` y `HEAD`, y `ExposeHeaders: ["ETag"]`. Sin `ETag`
  expuesto el navegador sube las partes pero no puede leer su ETag, y el
  `complete` falla.

  ```json
  [{
    "AllowedOrigins": ["https://admin.example.com"],
    "AllowedMethods": ["PUT", "GET", "HEAD"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }]
  ```
- **Una regla de ciclo de vida que aborta los multipart incompletos a los 7
  días.** Es la red de seguridad de lo que `mk:uploads:prune` no alcanzó: una
  parte subida y nunca unida ocupa espacio y no se ve en el listado del bucket.

### 2. Los disks y los roles

`league/flysystem-aws-s3-v3` (`^3.25`) hace falta para cualquier disk `s3`:
`composer require league/flysystem-aws-s3-v3`.

```php
// config/filesystems.php — los nombres de las variables son de la app
'r2_public' => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => env('R2_REGION', 'auto'),       // R2: `auto`
    'bucket' => env('R2_PUBLIC_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),           // https://<account>.r2.cloudflarestorage.com
    'url' => env('R2_PUBLIC_URL'),              // la dirección pública del bucket: arma los links
    'use_path_style_endpoint' => true,          // MinIO lo necesita, R2 lo acepta
    'throw' => true,
],
'r2_private' => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => env('R2_REGION', 'auto'),
    'bucket' => env('R2_PRIVATE_BUCKET'),
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    'visibility' => 'private',
    'throw' => true,
],
```

```dotenv
MK_STORAGE_PUBLIC_DISK=r2_public    # mk_director.storage.public_disk  (default `public`)
MK_STORAGE_PRIVATE_DISK=r2_private  # mk_director.storage.private_disk (default `local`)
MK_STORAGE_SIGNED_URL_TTL=7200      # segundos que vale un link a un archivo privado
```

### 3. `MkStorage`: el único que arma un link

`Mk\Director\Storage\MkStorage` (por el contenedor:
`app(MkStorage::class)`):

| Método | Qué hace |
|---|---|
| `diskName('public' \| 'private')` | El nombre del disk que cumple el rol. |
| `disk('public' \| 'private')` | Ese disk (`Filesystem`), para `put`, `get`, `delete`. |
| `url($disk, $path, ?$ttlSeconds)` | El link de un archivo guardado: en un disk público, `url()`; en uno privado (el del rol, o cualquiera con `visibility: private`), un `temporaryUrl()` firmado que vence a los `signed_url_ttl` segundos. Un path null da null; uno que ya es una URL absoluta se devuelve tal cual. |
| `isPrivate($disk)` | Si ese disk cuenta como privado. |
| `profile($name)` | El perfil de subida (abajo). Un nombre que no existe explota. |

🔴 **Nunca `Storage::disk(...)->url()` suelto.** Sobre un bucket privado
devuelve un link que no abre, y el error recién se ve como una imagen rota.
`MkMedia::url`, `FileStoragePlugin` (`auto_url`) y los accessors `*_url` que
genera `mk:make:auth-user` ya pasan por `MkStorage`.

Lo que llega por la API (`FileStoragePlugin`, `HasMkMedia::attachUploadedFile()`)
se guarda en el disk del rol `public` salvo que se pase otro.

### 4. Perfiles de subida

Un perfil dice qué entra en una clase de archivo, en
`mk_director.storage.profiles`:

```php
'image' => [
    'role' => 'public',                                   // o 'private'
    'mimes' => ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'],
                'image/webp' => ['webp'], 'image/gif' => ['gif']],  // MIME => extensiones; la 1ª es la que se guarda
    'max_bytes' => 10 * 1024 * 1024,
    'max_megapixels' => 25,                               // sólo en perfiles de imágenes
    'prefix' => 'media/images',                           // carpeta de la key
],
```

El paquete trae `avatar` (2 MB), `image` (10 MB, 25 MP), `video` (mp4, mov,
webm; 50 MB) y `document` (PDF, 20 MB), todos en el rol `public`.
`ValidatesMkMedia` lee sus topes y tipos de `image`, `video` y `document`, así
que un formulario `media[]` y una subida directa aceptan lo mismo.

**Un perfil propio de la app** se agrega con `config()` en el `boot()` de un
provider de la app, después de que el paquete mezcló su config. Así se hace en
un módulo con archivos grandes y privados:

```php
public function boot(): void
{
    config([
        'mk_director.storage.profiles.course_video' => [
            'role' => 'private',
            'mimes' => ['video/mp4' => ['mp4', 'm4v'], 'video/quicktime' => ['mov'], 'video/webm' => ['webm']],
            'max_bytes' => 4 * 1024 * 1024 * 1024,
            'prefix' => 'courses',
        ],
    ]);
}
```

⚠️ Cada MIME que un perfil tenga que aceptar en una subida directa tiene que ser
uno que el servidor reconozca por sus bytes (`MkFileInspector::sniff()`):
`image/jpeg`, `image/png`, `image/webp`, `image/gif`, `application/pdf`,
`video/mp4`, `video/quicktime`, `video/webm`, `audio/mpeg`, `audio/aac`,
`audio/wav`, `audio/ogg` y `audio/mp4`. Un alias (`audio/x-wav`) puede estar
para el arranque, pero el `complete` compara contra el MIME que dan los bytes:
el perfil tiene que listar también ese.

🔴 **Una app con `config/mk_director.php` publicado tiene que llevar el bloque
`storage` entero.** Laravel mezcla la config del paquete sólo en el primer
nivel: el `storage` publicado reemplaza al del paquete, y sin `profiles`
(`image`, `video`, `document`) `ValidatesMkMedia` explota nombrando el perfil
que falta. Copiá el bloque de `config/mk_director.php` del paquete, o no
publiques y configurá con `config()` como arriba.

### 5. Subidas directas: el archivo va del cliente al bucket

El navegador o el teléfono sube el archivo en partes a links firmados; la API
sólo abre, cierra y registra, y nunca ve los bytes. Sólo con un disk `s3` en el
rol del perfil.

**Las rutas son opt-in, un grupo por scope.** En el `register()` de un provider
de la app (el paquete las monta en su `boot()`):

```php
config([
    'mk_director.storage.routes.register' => true,   // o MK_UPLOADS_REGISTER_ROUTES=true
    'mk_director.storage.routes.scopes' => [
        'admin' => ['prefix' => 'api/admin', 'middleware' => ['api', 'mk.auth:admin']],
    ],
]);
```

| Ruta | Body → respuesta |
|---|---|
| `POST api/admin/uploads` | `{profile, filename, bytes, mime}` → 201 `{upload_id, part_size, part_urls, expires_at}` |
| `POST api/admin/uploads/{upload}/complete` | `{etags: [...]}` (en el orden de las partes) → `{upload_id, mime, bytes, width, height}` |
| `DELETE api/admin/uploads/{upload}` | cancela → 204 |

Nombres `mk.uploads.{scope}.start|complete|abort`. El dueño de la subida es el
usuario de la sesión; sin usuario, 401 aunque el grupo no tenga `mk.auth`.

**El recorrido:**

1. **start** valida tipo, extensión y tamaño declarados contra el perfil
   **antes** de tocar el storage (422 `ERR_UPLOAD_TYPE` / `ERR_UPLOAD_TOO_LARGE`),
   arma la key en el servidor (`{prefix}/{Y}/{m}/{uuid}.{ext}`: del nombre del
   cliente no se copia nada), abre el multipart y firma un link por parte
   (`part_size`, 8 MiB). Los links valen `upload_ttl` (6 h).
2. El cliente hace `PUT` de cada parte a su link y junta los ETags. En el front
   esto lo hace `mkDirectUpload` de `@makroz/core`, o directamente
   `<MkFileUpload direct={{ profile: 'image' }}>` de `@makroz/web` /
   `@makroz/mobile`, que además puede achicar la foto antes (`optimize`).
3. **complete** une las partes y **revisa lo guardado**, porque lo declarado no
   vale nada: los bytes reales (HEAD) contra `max_bytes`
   (`ERR_UPLOAD_TOO_LARGE`), la firma de los primeros bytes contra los MIME del
   perfil (`ERR_UPLOAD_TYPE`) y, en un perfil de imágenes, ancho × alto de la
   cabecera contra `max_megapixels` (`ERR_UPLOAD_DIMENSIONS`). Lo que no pasa se
   **borra** del bucket y responde 422 con `__extraData.code`. La fila guarda lo
   medido.
4. El formulario manda los `upload_ids[]` junto con el resto de los campos, y
   el servicio los adjunta:

```php
// FormRequest con ValidatesMkMedia
public function rules(): array
{
    return [/* ... */] + $this->mkMediaRules() + $this->mkUploadIdsRules();
}

// Service — el modelo usa HasMkMedia
foreach ($request->validated('upload_ids', []) as $uploadId) {
    $post->attachUpload($request->user(), (string) $uploadId, 'gallery', profiles: ['image', 'video']);
}
```

`mkUploadIdsRules()` valida `upload_ids` (array de uuids distintos, tope
`mkMediaMaxFiles()`). La pertenencia la garantiza `attachUpload()`: busca la
subida por id **y** dueño (`$owner` sale de la sesión, nunca del body), sólo
Completed y sólo de los `profiles` que acepta esa colección; crea la fila de
`mk_media` y la marca Attached en una transacción con la subida bloqueada. Una
subida ajena, sin terminar, ya usada o de otro perfil da **404** y no crea nada:
cada subida se usa una sola vez.

Para un flujo propio (una ruta de arranque que diga para qué es el archivo,
p. ej. la lección de un curso), el servicio
`Mk\Director\Storage\DirectUpload\MkDirectUpload` expone
`start($owner, $profile, $filename, $bytes, $mime, ?$keyPrefix)`,
`complete($owner, $id, $etags)`, `abort($owner, $id)` y
`claim($owner, $id, $profiles)`.

### 6. `mk:uploads:prune`, una vez por día

```php
// routes/console.php
Schedule::command('mk:uploads:prune')->daily()->withoutOverlapping()->onOneServer();
```

Deja Aborted, y limpia del bucket, lo que nadie va a usar: las subidas Started
vencidas (aborta el multipart), las que un `complete()` dejó a medias
(Completing, vencidas y sin tocar hace una hora) y las Completed que nadie
adjuntó en `mk_director.storage.prune_unattached_after_hours` horas (24;
`--hours=N` la pisa). Las Attached no se tocan. `--dry-run` cuenta sin tocar
nada. `onOneServer()` necesita un cache compartido (`redis`, `database`).

### 7. Otro proveedor (Cloudinary, …)

Leer y servir archivos de otro proveedor es **un disk más** de Laravel (un
adaptador de Flysystem) asignado a un rol: `MkStorage` y todo lo que pasa por la
API funcionan sin cambios.

La **subida directa** es otra cosa: hoy sólo existe `S3DirectUploadDriver`, y
`MkDirectUpload` lo elige por el `driver` del disk (`s3`); otro driver explota
nombrando el disk. Un proveedor nuevo necesita implementar
`Mk\Director\Storage\DirectUpload\MkDirectUploadDriver` (`start`,
`presignParts`, `complete`, `abort`, `size`, `head`) y que `MkDirectUpload` lo
elija para su driver de disk —un cambio del paquete—. Ese contrato es el de un
multipart con links `PUT` y ETags; un proveedor que firma parámetros en vez de
URLs (Cloudinary) además necesita su propio `putPart` en el front.

## Ayuda y manuales

Los manuales de una app son archivos Markdown en el repo de la API. `mk:help:sync`
los indexa en una tabla (`mk_help_articles`) y sube sus imágenes al storage; la API
los sirve por scope y los fronts los muestran con las piezas `MkHelp*` de
`@makroz/web` (el centro de ayuda, el "?" de cada pantalla, la vista para imprimir,
los enlaces compartidos) y de `@makroz/mobile`.

🔴 **Los permisos NUNCA esconden un artículo.** El sistema ya frena a quien no
tiene un permiso, y el manual sirve para aprender una tarea que te pueden dar
mañana. Lo único que separa manuales es la **audiencia**.

### 1. El contenido: `resources/help/`

```
resources/help/
├── screens.yaml                   # las pantallas de los fronts
├── README.md                      # opcional: para quien escribe; no se indexa
├── admin/                         # audiencia = la carpeta de arriba
│   ├── index.md                   # la portada del manual (slug `admin`)
│   └── kyc/
│       ├── index.md               # la sección: título y orden (slug `admin/kyc`)
│       ├── aprobar.md             # slug `admin/kyc/aprobar`
│       └── img/kyc-menu.webp
├── member/
└── business/                      # lo que ven las dos: cómo funciona el negocio
```

- Las audiencias son `admin`, `member` y `business`, y la carpeta de arriba
  **es** la audiencia. El slug es la ruta sin `.md`; un `index.md` toma el de su
  carpeta. Cada carpeta necesita su `index.md`: sin él la sync falla.
- El front matter:

```markdown
---
title: Aprobar una verificación de identidad
audience: admin            # admin | member | business; IGUAL a la carpeta de arriba
order: 20                  # entre sus hermanos; default 0 (empate: por slug)
ability: admin.kyc.approve # opcional e INFORMATIVO: «Necesitás el permiso …»
screens: [admin.kyc.index, admin.kyc.show]
related: [admin/retiros/pagar, business/glosario]
---
El cuerpo, en Markdown. ![Menú KYC](img/kyc-menu.webp)
```

- **Imágenes**: un archivo del manual con la ruta **relativa a la carpeta del
  `.md`**, como la resuelve cualquier editor de Markdown (`admin/kyc/aprobar.md` →
  `admin/kyc/img/kyc-menu.webp`). Sólo png, jpg, jpeg y webp, dentro de
  `resources/help`. Una URL externa, una ruta absoluta o un archivo que falta hacen
  fallar la sync.
- **Enlaces a otro artículo**: con su **slug**, igual que en `related`:
  `[Pagar un retiro](admin/retiros/pagar)`, con `#ancla` opcional. Todo enlace sin
  esquema se lee así y uno que no lleva a un artículo hace fallar la sync (`../pagar`,
  `/manual/x`, un slug mal escrito). El front lo convierte en la URL de su manual.
  Afuera sólo abren `https:`, `mailto:` y `tel:`.
- **`screens.yaml`**: `clave: descripción`, una por pantalla de los fronts
  (`admin.kyc.index: Lista de verificaciones`). La clave es la que el front le pasa
  al "?" (`<MkHelpButton screen="admin.kyc.index">`). Un artículo que lista una
  pantalla que no está ahí hace fallar la sync, y las que ningún artículo lista
  salen en el reporte como «sin artículo».

### 2. Las rutas: opt-in, un grupo por scope

Cada scope dice qué audiencias ve. En el `register()` de un provider de la app,
en código y no en `.env` (el prefijo, el middleware y las audiencias son un
contrato con los fronts):

```php
private function configureHelp(): void
{
    config([
        'mk_director.help.routes.register' => true,   // o MK_HELP_REGISTER_ROUTES=true
        'mk_director.help.routes.scopes' => [
            'admin' => [
                'prefix' => 'api/admin',
                'middleware' => ['api', 'mk.auth:admin'],
                'audiences' => ['admin', 'business'],
                'share' => true,                       // monta los enlaces compartidos
            ],
            'member' => [
                'prefix' => 'api/member',
                'middleware' => ['api', 'mk.auth:member'],
                'audiences' => ['member', 'business'],
                'share' => false,
            ],
        ],
        'mk_director.help.share.ability' => 'admin.help.share',
    ]);
}
```

| Ruta (bajo el prefijo del scope) | Respuesta `data` |
|---|---|
| `GET help/tree` | `[{slug, title, children}]`, por `order` entre hermanos |
| `GET help/articles/{slug}` (el slug lleva `/`) | el artículo, o 404 |
| `GET help/screens/{screen}` | el artículo que lista esa pantalla (si varios, el primero en el orden del árbol), o 404: «esta pantalla no tiene ayuda» |
| `GET help/search?q=` | `[{slug, title, snippet}]`, hasta 20; `q` recortado de 2 a 100 caracteres, si no 422 |
| `GET help/manual/{audience}` | el manual entero en el orden del árbol, cada artículo con `depth` (la vista para imprimir); 404 si la audiencia no es del scope |
| `GET/POST help/share-links`, `DELETE help/share-links/{id}` | sólo en un scope con `share: true` (abajo) |

Nombres `mk.help.{scope}.*`; responden con el sobre `{success, message, data}`. Un
artículo es `{slug, title, audience, ability, abilityLabel, body, images, related,
updatedAt}`: `images` son las únicas que el front pinta (`{url, width, height}`),
`related` sale con su título, en el orden del árbol y sólo los que el scope ve.
`abilityLabel` es la `description` de esa ability en la tabla `abilities`, para que
la nota diga el permiso con palabras (null si no está: el front muestra la clave).
**Es informativo: ningún permiso filtra nada.** Sin usuario en la sesión es 401,
aunque el grupo no tenga `mk.auth`; un scope sin `audiences` no ve nada.

### 3. `mk:help:sync`, en cada deploy

```bash
php artisan migrate
php artisan mk:help:sync            # o --dry-run: valida y cuenta sin escribir
```

🔴 **Todo o nada.** Con un front matter inválido, una carpeta sin `index.md`, dos
archivos con el mismo slug, un `related` o un enlace que no existen, una imagen
que falta o no se pudo subir, o una pantalla que no está en `screens.yaml`, lista
los errores, **no escribe nada** y sale con 1: un manual a medio indexar se ve
completo y miente. Con todo válido imprime cuántos creó, actualizó, borró y dejó
sin cambios, cuántas imágenes subió y qué pantallas no tienen artículo.

- Una transacción crea o actualiza por slug lo que cambió (por el hash del archivo
  y de lo que se guarda) y borra los slugs que ya no están en disco.
- Las imágenes se suben **antes** de la transacción, por contenido, al rol
  **público** del storage (`<image_prefix>/<sha1>.<ext>`, `image_prefix` = `help`):
  una que ya está no se vuelve a subir, y el cuerpo guardado apunta a su URL pública
  con su ancho y alto.
- `--dry-run` corre la validación entera y cuenta, sin escribir en la base ni subir
  nada al storage. Sirve para revisar el contenido en un PR.

### 4. Enlaces compartidos (temporales y públicos)

Un scope con `share: true` suma `GET/POST help/share-links` y
`DELETE help/share-links/{id}`, sólo para quien tiene
`canMk(mk_director.help.share.ability)` (null = nadie: 403). Cada uno lista y
revoca **sus** enlaces.

- `POST {target, hours}`: `target` es `slug:<slug>` (un artículo) o
  `manual:<audience>` (un manual entero) de lo que **ese scope** ve; `hours` va de
  1 a `share.max_hours` (720). Responde 201 con la `url`: la **única** vez que sale
  el token (`Str::random(48)`); la tabla `mk_help_share_links` guarda su sha256.
- La `url` es `share.public_url` + `/` + token. `public_url` sale de
  **`MK_HELP_SHARE_URL`** (la página pública del front, p. ej.
  `https://admin.example.com/compartido`), leída por la config del paquete para que
  sobreviva a `config:cache`. Sin ella, crear un enlace falla.
- La página pública lee, **sin sesión**, `GET api/help/shared/{token}`
  (`{target, tree, article}`: para `slug:` el artículo; para `manual:` el árbol y su
  portada) y `GET api/help/shared/{token}/articles/{slug}` (sólo un artículo dentro
  de lo compartido; si no, 404). Prefijo y throttle en `help.routes.public`
  (`api/help`, `throttle:60,1`); se montan si algún scope comparte. Vencido,
  revocado o inventado es **410**, sin decir cuál. Cada visita suma `uses`.
- Lo compartido se ve entero, como en el panel.

### 5. La búsqueda

- **Postgres**: una columna generada `search` (`tsvector` `spanish`, título con peso
  A y texto con peso B) con índice GIN, contra `websearch_to_tsquery('spanish', …)`,
  ordenada por `ts_rank`. Indexa `body_text` —el cuerpo en texto plano que arma la
  sync, sin Markdown ni URLs— y cada texto **dos veces**, tal cual y sin tildes
  (`translate()`), así que `contrasena` encuentra `contraseña` y `retiros` encuentra
  `retiro`. El fragmento es `ts_headline` con `<mark>` en el acierto y todo lo demás
  escapado. Límite conocido: una búsqueda sin tilde encuentra el artículo pero no
  marca la palabra.
- **Los demás drivers**: `LIKE` sin distinguir mayúsculas en el título y el texto,
  ordenado por título, sin stemming ni tildes; el fragmento es una ventana alrededor
  del primer acierto. Existe para que el paquete se pruebe en sqlite: no está
  afinado para producción en MySQL.

### 6. La config

```php
'help' => [
    'content_path' => resource_path('help'),
    'articles_table' => env('MK_HELP_ARTICLES_TABLE', 'mk_help_articles'),
    'share_links_table' => env('MK_HELP_SHARE_LINKS_TABLE', 'mk_help_share_links'),
    'image_prefix' => 'help',
    'routes' => [
        'register' => env('MK_HELP_REGISTER_ROUTES', false),
        'scopes' => [],
        'public' => ['prefix' => 'api/help', 'middleware' => ['api', 'throttle:60,1']],
    ],
    'share' => [
        'ability' => null,                         // p. ej. 'admin.help.share'
        'max_hours' => 720,
        'public_url' => env('MK_HELP_SHARE_URL'),
    ],
],
```

Las migraciones (`mk_help_articles` y `mk_help_share_links`) se cargan solas con
`php artisan migrate`. La ability de compartir es de la app (su nombre sale de
`share.ability`): la app la crea y la siembra en sus roles. 🔴 Una app con `config/mk_director.php` publicado lleva el bloque
`help` entero, por lo mismo que `storage`.

## Tests: `QueryBudget`, el N+1 por la pendiente

`Mk\Director\Testing\QueryBudget::assertFlat($screen, $seedRows, rows: [8, 20])` corre una pantalla con 1 fila y con
cada tamaño de `rows` y falla si la cantidad de consultas crece con las filas (una consulta por fila). Calienta antes de
contar, acepta un `growth:` declarado y un `ceiling:` absoluto, y si `$screen` devuelve las filas, falla también cuando
el fixture no llega a la pantalla. Detalle: `DEVELOPER_GUIDE.md` § 3.6.8.

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
