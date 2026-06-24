<?php

declare(strict_types=1);

/**
 * MK-Director Configuration
 *
 * Configuración centralizada para el paquete mk-laravel.
 * Publicar con: php artisan vendor:publish --tag=mk-config
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Debug Mode
    |--------------------------------------------------------------------------
    |
    | Habilita el modo debug para ver queries y tiempos de ejecución.
    |
    */
    'debug' => env('MK_DIRECTOR_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | List & Pagination Settings
    |--------------------------------------------------------------------------
    */
    'list' => [
        'default_per_page' => env('MK_LIST_DEFAULT_PER_PAGE', 15),
        'max_per_page' => env('MK_LIST_MAX_PER_PAGE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Settings
    |--------------------------------------------------------------------------
    */
    'search' => [
        'min_chars' => env('MK_SEARCH_MIN_CHARS', 3),
        'mode' => env('MK_SEARCH_MODE', 'like'), // like, exact, fulltext
    ],

    /*
    |--------------------------------------------------------------------------
    | Pluggable Features
    |--------------------------------------------------------------------------
    | Enable or disable core ecosystem features.
    */
    'features' => [
        'auto_cache' => env('MK_AUTO_CACHE', false),
        'dynamic_joins' => env('MK_DYNAMIC_JOINS', true),
        'file_webp' => env('MK_FILE_WEBP', true),
        'ai_analysis' => env('MK_AI_ANALYSIS', false),

        // ListManager HTTP Toggle Settings
        'dynamic_includes' => env('MK_DYNAMIC_INCLUDES', true),
        'filters' => env('MK_FILTERS', true),
        'sorting' => env('MK_SORTING', true),
        'search' => env('MK_SEARCH', true),
        'remember_state' => env('MK_REMEMBER_STATE', false),
        'pagination_type' => env('MK_PAGINATION_TYPE', 'length_aware'), // Options: length_aware, cursor
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Scope
    |--------------------------------------------------------------------------
    |
    | Config del scope de autenticación del consumer. Estos valores son
    | **opcionales** — quedan en null por default para que cada consumer
    | (que respeta DDD) defina su propio modelo de usuario y su propio
    | default_user_type en su `config/mk_director.php` publicado.
    |
    | El paquete NO hardcodea:
    |   - `\App\Models\User` (modelo default de Laravel, que rompe MME
    |     porque los modelos viven en `App\Modules\<Scope>\Models`).
    |   - un módulo específico del paquete (rompe DDD — el paquete no
    |     debe conocer los modelos concretos del consumer).
    |
    | `user_model`       — modelo Eloquent concreto que el consumer usa
    |                       como user autenticable (ej:
    |                       `App\Modules\Admin\Models\Admin`). Override
    |                       por entorno vía `MK_AUTH_USER_MODEL`.
    |
    | `default_user_type` — clase que mk-director usa cuando un endpoint
    |                       no especifica un type concreto. Override por
    |                       entorno vía `MK_AUTH_DEFAULT_USER_TYPE`. Si
    |                       queda null, el consumer debe setearlo
    |                       explícitamente en su config publicado.
    |
    | Ambos son null por default. Razón: mk-director es multi-tenant por
    | scope (Admin, Member, Customer, etc.) y el modelo concreto depende
    | de la decisión de arquitectura del consumer, no del paquete.
    |
    | Generá un scope concreto con: `php artisan mk:make:auth-user {Scope}`.
    */
    'auth' => [
        'user_model' => env('MK_AUTH_USER_MODEL'),
        'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Progressive Codes (HasProgressiveCode Trait)
    |--------------------------------------------------------------------------
    | Settings for generating sequential custom codes.
    */
    'progressive_codes' => [
        'table' => env('MK_PROGRESSIVE_CODES_TABLE', 'mk_progressive_codes'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Strategy
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'default_ttl' => env('MK_CACHE_TTL', 3600),
        'store' => env('MK_CACHE_STORE', null), // Configured in cache.php
    ],

    /*
    |--------------------------------------------------------------------------
    | Plugins / Extensions
    |--------------------------------------------------------------------------
    | Array of plugin classes that extend mk-laravel behavior.
    */
    'plugins' => [
        // \App\MkPlugins\AuditPlugin::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenant (opt-in)
    |--------------------------------------------------------------------------
    |
    | When `tenant.enabled` is true:
    |   - The `TenantResolver` middleware is appended to the `api` group.
    |   - Models using the `HasTenantScope` trait get a global scope
    |     that filters by the tenant id in the current request.
    |
    | When `tenant.enabled` is false (default), the trait and the
    | middleware are both no-ops — opt-in per ADR-003. Enable
    | explicitly in your project's `config/mk_director.php` after
    | publishing.
    |
    | Resolver strategies:
    |   - `header`    : read from a request header (default X-Tenant-ID).
    |   - `path`      : first URI segment, resolved to a tenant id
    |                   via the configured `tenant.model` by slug.
    |   - `subdomain` : leftmost subdomain, same lookup by slug.
    |
    | `strict` (default true): reject the request with 400 if the
    | tenant cannot be resolved. Set to false for public endpoints
    | that should run without a tenant.
    */
    'tenant' => [
        'enabled' => env('MK_TENANT_ENABLED', false),
        'resolver' => env('MK_TENANT_RESOLVER', 'header'),
        'header_name' => env('MK_TENANT_HEADER', 'X-Tenant-ID'),
        'model' => env('MK_TENANT_MODEL', null), // e.g. App\Models\Tenant
        'strict' => env('MK_TENANT_STRICT', true),
    ],
];
