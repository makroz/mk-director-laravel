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
- **Magic CRUD (SmartController)**: ABM declarativo extendiendo `Mk\Director\Controllers\SmartController` y configurando `$mkConfig`. Los plugins (`MkAuditLoggerPlugin`, `MkMultiTenantPlugin`) hookan automáticamente. El scaffolder `mk:module` lo genera por default.
- **RBAC scaffolder (`mk:module --with-rbac`)**: genera un trío RBAC completo (User + Role + Ability + 2 pivots con FK + 3 Policies + RbacService + ServiceProvider con Gate bindings) en un solo comando. Por-módulo, scope-aislado. Ver [DEVELOPER_GUIDE.md § 3.5](DEVELOPER_GUIDE.md#-35-scaffolding-modules-with-rbac---with-rbac).
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
| `php artisan mk:make:auth-user {Scope}` | Scaffolding de scope de autenticación con `AuthUser`, `AuthController`, tokens Sanctum. |
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
