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

        // R-PKG-007: auto-run `mk:discover-abilities` on every boot.
        // Solo usar en sandbox/dev. Idempotente (UPSERT), pero agrega overhead.
        'auto_discover_abilities' => env('MK_AUTO_DISCOVER_ABILITIES', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Module Discovery Paths
    |--------------------------------------------------------------------------
    |
    | Path(s) donde viven los módulos del consumer. Usado por:
    |   - `mk:module` scaffolder para localizar `app/Modules/`.
    |   - `mk:discover-abilities` (R-PKG-007) para escanear controllers
    |     y providers cuando el provider NO implementa `discoverAbilities()`.
    |
    | Default: `app_path('Modules')` — match la convención generada por
    | `mk:module {Name}`. Override per-project via `.env`:
    |   `MK_MODULES_PATH=custom/Modules`
    | o vía `config/mk_director.php` publicado.
    */
    'paths' => [
        'modules' => env('MK_MODULES_PATH', app_path('Modules')),
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

        // R-PKG-009: campo de login default para scopes nuevos generados con
        // `mk:make:auth-user {Scope}`. Default BC: `email`. Otros casos
        // comunes: `ci` (Bolivia), `phone`, `username`, `documento`.
        //
        // NOTA: Este config es el DEFAULT para el scaffolder. Cada subclase
        // concreta (Admin, Member) puede override `$loginField` en su propio
        // modelo (via `--login-field=<campo>` al ejecutar mk:make:auth-user).
        // El config se usa en `MkAuthenticate` para resolver `auth_identifier`
        // cuando el token no trae ability explícita.
        'login_field' => env('MK_LOGIN_FIELD', 'email'),

        // R-PKG-010: ability checks opcionales en endpoints privados del
        // AuthController generado con `mk:make:auth-user --with-auth-rbac`.
        //
        // Default BC: `null` en TODAS las abilities → no se hace check
        // (idem v1.4.0 / v1.5.0-rc3 sin --with-auth-rbac).
        //
        // Configurar via env o publicando config:
        //   MK_AUTH_ABILITY_ME=auth.me.read
        //   MK_AUTH_ABILITY_LOGOUT=auth.logout
        //
        // El consumer puede usar cualquier naming convention. Convención
        // recomendada: `{scope}.{endpoint}.{action}` (ej: `admin.me.read`).
        //
        // El ability check usa `Mk\Director\Auth\Services\AbilityResolver`
        // (existente, con cache por user + Sanctum short-circuit).
        'abilities' => [
            'me' => env('MK_AUTH_ABILITY_ME'),
            'logout' => env('MK_AUTH_ABILITY_LOGOUT'),
        ],

        // R-PKG-010: rate limits por endpoint público del AuthController.
        // Aplican via middleware `throttle:{limit},{minutes}` solo cuando
        // el scope se genera con `--with-auth-rbac`.
        //
        // Default seguro:
        //   login   = 5 attempts / minuto (anti brute-force)
        //   forgot  = 3 attempts / minuto (anti enumeration)
        //   reset   = 3 attempts / minuto (anti abuse)
        //
        // El consumer puede customizar via env o config publicada.
        // Rate limit agresivo puede bloquear usuarios reales — tunable.
        'rate_limits' => [
            'login' => env('MK_AUTH_RATE_LIMIT_LOGIN', '5,1'),
            'forgot' => env('MK_AUTH_RATE_LIMIT_FORGOT', '3,1'),
            'reset' => env('MK_AUTH_RATE_LIMIT_RESET', '3,1'),
        ],
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

        // R-PKG-024 (rc13): gate for `CacheManager::flush()` fallback path.
        // When the cache driver does NOT support tags (e.g. file/database
        // cache in dev), the only way to invalidate is `$cache->clear()`
        // which wipes the ENTIRE application cache (not just this module's
        // keys). This is a "nuke" — opt-in via this flag.
        //
        // Default `false` (safe). Set to `true` ONLY in dev environments
        // that use file/database cache AND understand the nuke risk.
        // Production MUST use a cache store that supports tags (Redis,
        // Memcached) — see `cache.store` config above.
        'allow_full_clear' => env('MK_CACHE_ALLOW_FULL_CLEAR', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response Envelope
    |--------------------------------------------------------------------------
    |
    | R-PKG-023 (rc12): opt-in flag for the top-level `__extraData` shape
    | that matches the @makroz/core `MkResponse<T>` contract. When the
    | flag is `false` (rc12 default), controllers emit the legacy nested
    | shape (`data.data` + `data.__extraData`). When the flag is `true`,
    | controllers emit the canonical top-level shape (`data` + top-level
    | `__extraData`).
    |
    | Migration plan:
    |   - rc12: default `false`. Consumers opt-in per-environment.
    |   - GA:   default `true`. Legacy path is removed.
    |
    | Toggle via env: `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA=true`.
    |
    | @see https://github.com/makroz/mk-director-laravel/blob/main/CHANGELOG.md
    |      for the full migration guide from rc11 → rc12.
    */
    'response' => [
        'top_level_extra_data' => env('MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    |
    | R-PKG-024 (rc13): gate for the optional `EXPLAIN` query analysis in
    | `BaseController::getDebugData()`. When this flag is `false` (default),
    | slow-query candidates are logged via `Log::debug()` for offline
    | analysis — no `EXPLAIN` is executed against the database. When the
    | flag is `true`, the SQL is logged as a `warning` so a developer can
    | run `EXPLAIN` manually in a safe environment.
    |
    | The previous behavior (rc12 and earlier) interpolated the query
    | directly into `DB::select("EXPLAIN " . $query)`, which is a SQL
    | injection vector if the query contains user-controlled values.
    | This flag prevents the unsafe path by default. See CHANGELOG rc13.
    */
    'debug' => [
        'enabled' => env('MK_DIRECTOR_DEBUG', false),
        'explain_enabled' => env('MK_DIRECTOR_DEBUG_EXPLAIN_ENABLED', false),
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
