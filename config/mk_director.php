<?php

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
        'auto_cache'       => env('MK_AUTO_CACHE', false),
        'dynamic_joins'    => env('MK_DYNAMIC_JOINS', true),
        'file_webp'        => env('MK_FILE_WEBP', true),
        'ai_analysis'      => env('MK_AI_ANALYSIS', false),
        
        // ListManager HTTP Toggle Settings
        'dynamic_includes' => env('MK_DYNAMIC_INCLUDES', true),
        'filters'          => env('MK_FILTERS', true),
        'sorting'          => env('MK_SORTING', true),
        'search'           => env('MK_SEARCH', true),
        'remember_state'   => env('MK_REMEMBER_STATE', false),
        'pagination_type'  => env('MK_PAGINATION_TYPE', 'length_aware'), // Options: length_aware, cursor
    ],

    /*
    |--------------------------------------------------------------------------
    | Auth Scope (Experimental)
    |--------------------------------------------------------------------------
    | Define the user interface or class for authorization checks.
    */
    'auth' => [
        'user_model' => \App\Models\User::class,
        'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE', 'App\\Modules\\Admin\\Models\\Admin'),
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
        'store'       => env('MK_CACHE_STORE', null), // Configured in cache.php
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
        'enabled'     => env('MK_TENANT_ENABLED', false),
        'resolver'    => env('MK_TENANT_RESOLVER', 'header'),
        'header_name' => env('MK_TENANT_HEADER', 'X-Tenant-ID'),
        'model'       => env('MK_TENANT_MODEL', null), // e.g. App\Models\Tenant
        'strict'      => env('MK_TENANT_STRICT', true),
    ],
];
