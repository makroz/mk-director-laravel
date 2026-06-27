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
- **RBAC scaffolder (`mk:module --with-rbac`)**: genera un trío RBAC completo (User + Role + Ability + 2 pivots con FK + 3 Policies + RbacService + ServiceProvider con Gate bindings) en un solo comando. Por-módulo, scope-aislado. Ver [DEVELOPER_GUIDE.md § 3.5](DEVELOPER_GUIDE.md#-35-scaffolding-modules-with-rbac---with-rbac).
- **Auth-user scaffolder (`mk:make:auth-user`)**: scope de autenticación autocontenido con `AuthUser`, `AuthController`, tokens Sanctum. Soporta `--login-field=<campo>`, `--with-auth-rbac`, `--profile-fields=<csv>` (con tipos custom y constraint `unique` vía prefijo `!`), `--verify-email`, y `--with-crud` (CRUD completo + RBAC triada + DDD artifacts en una corrida). Ver [DEVELOPER_GUIDE.md § 3.6-3.13](DEVELOPER_GUIDE.md).
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
| `php artisan mk:make:auth-user {Scope} --with-crud` | Genera CRUD completo del scope + RBAC triada: `AdminController` + `RoleController` + `AbilityController` (SmartController) + 4 FormRequests + 3 JsonResources + 2 DTOs readonly + Repository + Interface + Service + Factory DDD + Seeder con 4 roles predefinidos. ServiceProvider extendido con binding. **Nuevo en v1.6.0-rc4** (MEJORA-02) — **hardened en v1.6.0-rc5** (R-PKG-015 BUG-NEW-05/06 fixes: import statements en routes, FK overrides en el modelo). |
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

- PHP 8.4+
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
