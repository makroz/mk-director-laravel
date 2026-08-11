<?php

declare(strict_types=1);

namespace Mk\Director;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Mk\Director\Auth\AuthServiceProvider;
use Mk\Director\Console\Commands\AuthCreateSuperAdminCommand;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Console\Commands\FixSanctumUuidsCommand;
use Mk\Director\Console\Commands\GenerateDocsCommand;
use Mk\Director\Console\Commands\LintBoundariesCommand;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Console\Commands\MakeDTOCommand;
use Mk\Director\Console\Commands\MakeModuleCommand;
use Mk\Director\Console\Commands\MakeServiceCommand;
use Mk\Director\Console\Commands\MkCheckCommand;
use Mk\Director\Console\Commands\MkMigrateIsActiveToStatusCommand;
use Mk\Director\Console\Commands\MkMigrateStatusToIntCommand;
use Mk\Director\Console\Commands\MkSkillDeployCommand;
use Mk\Director\Console\Commands\MkSkillListCommand;
use Mk\Director\Console\Commands\MkUpdateCommand;
use Mk\Director\Console\Commands\PruneAbilitiesCommand;
use Mk\Director\Console\Commands\SecurityLintCommand;
use Mk\Director\Controllers\OpenApiController;
use Mk\Director\Embeds\MkEmbedService;
use Mk\Director\Managers\CacheManager;
use Mk\Director\Managers\PluginManager;
use Mk\Director\ModuleLoader\ModuleLoaderServiceProvider;
use Mk\Director\Plugins\FileStoragePlugin;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantResolver;
use Mk\Director\Utils\MkDebugConfig;
use Mk\Director\Utils\MkRequestAwareStorageUrl;
// 🔴 ESTE IMPORT FALTABA Y HABÍA DOS `catch (Throwable $e)` MUERTOS.
// Sin él, dentro del namespace `Mk\Director` el nombre pelado resuelve a
// `Mk\Director\Throwable` —una clase que no existe— así que el catch no
// matchea NUNCA. No es un error de sintaxis ni de tipos: PHP lo acepta, el
// linter lo acepta, y el bloque simplemente no corre. El de
// `registerAutoDiscoverAbilities()` decía proteger el boot de un
// auto-discover fallido y lo dejaba pasar entero.
// El que sí funcionaba (línea ~195) usa `\Throwable` con la barra.
use Throwable;

class MkServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mk_director.php', 'mk_director');

        // R-PKG-045 D2: auto-register FileStoragePlugin by default unless the
        // consumer explicitly opts out via `features.file_storage_plugin = false`.
        // MUST run BEFORE the PluginManager singleton binding (which reads
        // `config('mk_director.plugins')` in its boot() — see R-PKG-046 F9-B07).
        $this->registerPlugins();

        // MK-Director Plugin Manager — R-PKG-046 F9-B07 lazy singleton.
        //
        // Pre-fix, `new PluginManager` en el closure disparaba
        // `loadPluginsFromConfig()` desde el constructor, que resolvía
        // `FileStoragePlugin` via container. Como `FileStoragePlugin::__construct(PluginManager)`
        // requiere `PluginManager`, container entraba en dependency circular
        // y la app bricked.
        //
        // Post-fix: el closure solo construye el PluginManager vacío.
        // El método público `boot()` carga los plugins. Disparado desde
        // `MkServiceProvider::boot()` DESPUÉS de que el singleton YA está
        // en el container — así cuando boot() resuelve FileStoragePlugin,
        // PluginManager ya existe y puede inyectarse sin loop.
        $this->app->singleton(PluginManager::class, function ($app) {
            return new PluginManager;
        });

        // Auth subsystem (Mk\Director\Auth\AuthServiceProvider)
        $this->app->register(AuthServiceProvider::class);

        // R-PKG-052 T8 — auto-register ModuleLoader para que el consumer
        // NO tenga que pinear `ModuleLoaderServiceProvider` manualmente
        // en `bootstrap/providers.php`. Defense-in-depth: si un dev
        // genera un módulo con `mk:make:auth-user` y olvida pinear
        // el provider en bootstrap/, igual se registra (via glob con
        // cache 1h TTL + symlink rejection + canonical path check —
        // ver ModuleProviderRegistry).
        //
        // Pre-fix, RETO tenía que pinear manualmente cada
        // `App\Modules\<X>\Providers\<X>ServiceProvider::class` en
        // bootstrap/providers.php. Riesgo de olvidar (módulo sin
        // registrar → 503 en runtime, debug doloroso).
        //
        // Post-fix: MkServiceProvider lo registra automáticamente.
        // El consumer puede seguir pineando manual si quiere (override
        // explícito del auto-discovery, e.g. para excluir módulos
        // específicos via glob custom).
        $this->app->register(ModuleLoaderServiceProvider::class);

        // Tenancy subsystem — opt-in. The TenantContext is a
        // singleton so the same instance is shared by the
        // middleware (writer) and the trait (reader).
        $this->app->singleton(TenantContext::class);

        // MkEmbedService — reconocimiento de URLs de YouTube/TikTok/Instagram.
        //
        // Singleton porque no tiene estado propio y sí un caché que conviene
        // compartir dentro del request. El caché se resuelve acá y no adentro
        // del servicio para que un consumer sin `cache` configurado (o un test)
        // pueda construirlo a mano sin caché y siga funcionando: el servicio lo
        // toma como nullable a propósito.
        $this->app->singleton(MkEmbedService::class, function ($app) {
            return new MkEmbedService(
                cache: $app->bound('cache') ? $app->make('cache')->store() : null,
                timeout: (int) config('mk_director.embeds.timeout', 3),
                cacheTtl: (int) config('mk_director.embeds.cache_ttl', 86400),
            );
        });
    }

    /**
     * R-PKG-045 D2 — Auto-register FileStoragePlugin by default.
     *
     * Behavior:
     *   - Default (feature flag true OR unset): FileStoragePlugin is appended
     *     to `config('mk_director.plugins')` so PluginManager loads it.
     *   - Opt-out: `'features.file_storage_plugin' => false` → FileStoragePlugin
     *     is NOT registered. Escape hatch para consumers que quieren controlar
     *     manualmente qué plugins se cargan (e.g. RETO pre-R-PKG-045 que tenía
     *     `plugins => []` pineado esperando "no plugins").
     *   - Dedup: si FileStoragePlugin ya está en `plugins` → skip (no duplicar).
     *
     * BC analysis (FEEDBACK8):
     *   - `'plugins' => []` + feature flag true → FileStoragePlugin se carga
     *     (NEW behavior). Riesgo documentado en CHANGELOG; consumer puede
     *     opt-out via flag.
     *   - `'plugins' => [CustomPlugin::class]` → FileStoragePlugin se suma
     *     (BC-safe).
     *   - `'features.file_storage_plugin' => false` → FileStoragePlugin NO
     *     se carga (escape hatch).
     *
     * MUST run BEFORE the PluginManager singleton binding — el ctor de
     * PluginManager::loadPluginsFromConfig() lee `config('mk_director.plugins')`
     * en su primera instanciación.
     *
     * @see https://github.com/makroz/mk-director-laravel/blob/main/CHANGELOG.md (R-PKG-045)
     */
    protected function registerPlugins(): void
    {
        $pluginClasses = config('mk_director.plugins', []);

        // D2: auto-register unless feature flag is explicitly disabled.
        if (config('mk_director.features.file_storage_plugin', true)
            && ! in_array(FileStoragePlugin::class, $pluginClasses, true)
        ) {
            $pluginClasses[] = FileStoragePlugin::class;
        }

        config(['mk_director.plugins' => $pluginClasses]);
    }

    public function boot()
    {
        // Load the package's Auth migrations so the abilities / roles /
        // admins tables are available to every project.
        $this->loadMigrationsFrom(__DIR__.'/Auth/Database/Migrations');

        // Migraciones de dominio del paquete (no-Auth): `mk_media`, etc.
        // Dir separado a propósito — Auth/Database/Migrations es el store de
        // RBAC y meter tablas de dominio ahí borra esa frontera.
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        // R-PKG-046 F9-B07 — Lazy plugin boot.
        //
        // El singleton PluginManager está registrado en `register()` pero su
        // constructor es lazy (no carga plugins). Llamar `boot()` ahora,
        // después del `loadMigrationsFrom` (para que DB esté lista) y
        // después de `mergeConfigFrom` (hecho en register()), pero ANTES
        // de cualquier resolución del container que pueda depender de
        // plugins (e.g. CRUDSmart trait en controllers).
        //
        // `$this->app->make(PluginManager::class)` resuelve el singleton (ejecuta
        // el closure `new PluginManager` que solo pinear `plugins = collect()`).
        // Luego `->boot()` carga config + llama `app(FileStoragePlugin::class)`.
        // Container resuelve FileStoragePlugin buscando PluginManager → encuentra
        // el singleton YA CONSTRUIDO. Inyecta OK. No loop.
        $this->app->make(PluginManager::class)->boot();

        $this->applyRequestAwareStorageUrl();

        $this->registerTenantMiddleware();

        // R2-005: Flush the TenantContext at the end of every request
        // so long-lived workers (Octane / Swoole) do not leak tenant
        // state into the next request. The terminating callback fires
        // after the response is sent; failures here are swallowed so
        // a buggy TenantContext cannot break the response pipeline.
        $this->app->terminating(function () {
            try {
                if ($this->app->resolved(TenantContext::class)) {
                    $this->app->make(TenantContext::class)->flush();
                }
            } catch (Throwable) {
                // ignore — never let a flush failure break the response
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mk_director.php' => config_path('mk_director.php'),
            ], 'mk-config');

            $this->commands([
                MkCheckCommand::class,
                MakeModuleCommand::class,
                MakeServiceCommand::class,
                MakeDTOCommand::class,
                MakeAuthUserCommand::class,
                GenerateDocsCommand::class,
                LintBoundariesCommand::class,
                SecurityLintCommand::class,
                MkUpdateCommand::class,
                MkSkillListCommand::class,
                MkSkillDeployCommand::class,
                AuthCreateSuperAdminCommand::class,
                DiscoverAbilitiesCommand::class,
                PruneAbilitiesCommand::class,
                // R-PKG-015 BUG-NEW-09: helper command para parche de Sanctum UUIDs.
                FixSanctumUuidsCommand::class,
                // R-PKG-047 D4: helper command para migrar `is_active` boolean
                // pre-D4 al `status` enum string-backed (4 estados canónicos).
                MkMigrateIsActiveToStatusCommand::class,
                MkMigrateStatusToIntCommand::class,
            ]);
        }

        $this->registerGlobalCacheListener();
        $this->registerOpenApiRoutes();
        $this->registerAutoDiscoverAbilities();
    }

    /**
     * R-PKG-007 (D4): si `mk_director.features.auto_discover_abilities = true`,
     * corre `mk:discover-abilities --force --json` después del boot cuando
     * estamos en consola (sandbox/dev). Idempotente (UPSERT).
     *
     * En CI/prod: dejar el flag en `false` (default). El `mk:discover-abilities`
     * se corre manualmente o como parte del deploy script.
     *
     * BUG-NEW-auto-discover-serve fix (2026-06-28, RETO fase 12 feedback):
     * 2 problemas pineados a v1.7.0 que bricked dev servers con el flag activo:
     *
     *   1. `runningInConsole()` retorna `true` cuando corrés
     *      `php artisan serve` (Laravel CLI server cuenta como "console context"),
     *      así que el check de línea ~120 dejaba pasar. Auto-discover corría
     *      en el boot del HTTP server, lo que no es lo deseado.
     *
     *   2. La llamada pineada a `$this->app->call()` con la FQCN del comando
     *      como primer argumento está MAL — Laravel trata el primer arg como
     *      `callable` y FQCN no es callable, así que se invocaba como
     *      `DiscoverAbilitiesCommand()` function call, fallando con
     *      `Call to undefined function DiscoverAbilitiesCommand()`. Bricked
     *      cualquier dev con `MK_AUTO_DISCOVER_ABILITIES=true` en `.env`.
     *
     * Fix:
     *   - Skip cuando `$_SERVER['argv']` incluye `serve` / `octane:start` /
     *     `horizon` / `queue:work|listen` / `schedule:work|run` (todos contextos
     *     "long-running" que no deberían triggear auto-discover en boot).
     *   - Usar `Artisan::call('mk:discover-abilities', [...])` en vez de
     *     `$this->app->call(Class, params)`. `Artisan::call` dispatcha via
     *     el kernel Artisan, que bindea correctamente la instancia del
     *     comando + argumentos.
     */
    /**
     * Anota algo durante el boot sin poder romperlo.
     *
     * 🔴 UN RESCATE NO PUEDE AGREGAR UN FALLO NUEVO. Los avisos de acá salen
     * justo cuando el entorno está a medio armar —sin base, sin cache, a veces
     * sin logger— y ahí `Log::debug()` no es inocente: sin el binding `log`
     * tira `BindingResolutionException: Target class [log] does not exist` y se
     * lleva puesto el boot que veníamos a salvar. Lo cazó el test de este
     * mismo rescate, que sin esto fallaba EN LA LÍNEA DEL LOG y no en la que
     * estaba probando.
     */
    protected function avisoDeBoot(string $mensaje): void
    {
        try {
            if ($this->app->bound('log')) {
                Log::debug($mensaje);
            }
        } catch (Throwable) {
            // Sin logger no hay a dónde escribir, y no hay nada peor que hacer
            // que volarle el arranque a alguien por no poder avisarle.
        }
    }

    protected function registerAutoDiscoverAbilities(): void
    {
        if (! config('mk_director.features.auto_discover_abilities', false)) {
            return;
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        // BUG-NEW-auto-discover-serve fix: skip "long-running" CLI contexts.
        // `php artisan serve` arranca el HTTP server (no es un comando one-shot
        // que deba triggear auto-discover). `octane:start`, `horizon`,
        // `queue:work/listen`, `schedule:work/run` son todos similares: un
        // proceso se queda corriendo después del boot, y auto-discover
        // corría una vez al inicio, lo que:
        //   (a) no tiene sentido semántico (discover es para sandbox/dev
        //       de un comando one-shot, no para servers);
        //   (b) bricked el server con el bug de `$this->app->call(Class, ...)`
        //       que se veia como "Call to undefined function".
        $skipArgs = [
            'serve',
            'octane:start',
            'octane:reload',
            'horizon',
            'horizon:supervisor',
            'queue:work',
            'queue:listen',
            'schedule:work',
            'schedule:run',
        ];
        if (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
            foreach ($_SERVER['argv'] as $arg) {
                if (in_array($arg, $skipArgs, true)) {
                    return;
                }
            }
        }

        // Don't auto-run when the artisan command IS mk:discover-abilities
        // (avoid infinite recursion when developers run it interactively).
        if (isset($_SERVER['argv'][1]) && str_starts_with((string) $_SERVER['argv'][1], 'mk:discover-abilities')) {
            return;
        }

        // HALLAZGO-NEW-FASE14-01 fix (v1.8.1+): skip if the abilities table
        // doesn't exist yet. Common in RefreshDatabase testing — package
        // migrations from `loadMigrationsFrom` run AFTER the ServiceProvider
        // boots, so the table is not available when auto-discover fires.
        //
        // Without this guard, the discover-abilities command throws
        // `RuntimeException: Ninguna tabla de abilities existe...` and the
        // boot aborts (taking down the test runner).
        //
        // Production boot paths (`php artisan serve`, `octane:start`,
        // `queue:work`, etc.) run migrations BEFORE booting the framework,
        // so this guard is a no-op (Schema::hasTable returns true) in
        // production. The cost is one schema introspection per boot —
        // negligible.
        //
        // Spec: HALLAZGO-NEW-FASE14-01, feedback RETO fase 14 (2026-06-29).
        // 🔴 EL GUARD TAMBIÉN NECESITA SU PROPIO GUARD.
        //
        // Arriba dice que la introspección cuesta "one schema introspection per
        // boot — negligible". Es cierto cuando HAY base. Cuando no la hay,
        // `Schema::hasTable()` no devuelve false: TIRA. Y este boot lo dispara
        // `package:discover`, o sea `composer install`, así que instalar
        // dependencias exigía una base viva. En un clone limpio el install moría
        // con "Database file at path [...] does not exist" — un mensaje que no
        // nombra ni las abilities ni el auto-discover.
        //
        // "No pude preguntar" y "la tabla no está" llevan al mismo lado: no hay
        // nada que descubrir todavía, se sigue de largo. Auto-discover es una
        // comodidad de desarrollo, nunca un requisito para arrancar.
        $abilitiesTable = config('mk_director.auth.tables.abilities', 'abilities');

        try {
            $existe = Schema::hasTable($abilitiesTable);
        } catch (Throwable $e) {
            $this->avisoDeBoot("MK-Director: skip auto-discover-abilities — sin conexión para consultar [{$abilitiesTable}]: ".$e->getMessage());

            return;
        }

        if (! $existe) {
            $this->avisoDeBoot("MK-Director: skip auto-discover-abilities — table [{$abilitiesTable}] not migrated yet.");

            return;
        }

        try {
            // BUG-NEW-auto-discover-serve fix: use Artisan::call() instead of
            // $this->app->call(Class, params). The latter treats the FQCN as
            // a callable, which fails with "Call to undefined function"
            // (the FQCN is not a function). Artisan::call dispatches via the
            // Artisan kernel and properly binds the command instance +
            // arguments.
            $exitCode = Artisan::call('mk:discover-abilities', [
                '--force' => true,
                '--json' => true,
            ]);
            if ($exitCode !== 0) {
                Log::warning("MK-Director: auto-discover-abilities exited with code {$exitCode}.");
            }
        } catch (Throwable $e) {
            Log::warning('MK-Director: auto-discover-abilities failed: '.$e->getMessage());
        }
    }

    /**
     * Register the TenantResolver middleware on the `api` group.
     *
     * The middleware itself checks `mk_director.tenant.enabled`
     * and short-circuits to a pass-through when the feature is
     * disabled. We always register it (instead of conditionally
     * in the provider) so that flipping the config at runtime —
     * e.g. inside a test — picks up the new state without
     * requiring the framework to re-boot.
     */
    /**
     * En DESARROLLO, arma la url del disk `public` con el host de la request
     * entrante en vez de con `APP_URL`.
     *
     * `config/filesystems.php` deriva esa url de `APP_URL`, que es UN solo
     * valor — pero en dev la url correcta depende de quién pregunta: el
     * navegador local llega por `127.0.0.1` y el celular por la IP de LAN.
     * Con un valor fijo, uno de los dos siempre recibe URLs que no resuelve, y
     * como la IP la reparte DHCP se rompe sola al cambiar el lease.
     *
     * 🔴 Sólo aplica en entorno local. Derivar URLs del header `Host` es host
     * header injection en producción. Ver {@see MkRequestAwareStorageUrl}.
     */
    protected function applyRequestAwareStorageUrl(): void
    {
        $configured = config('mk_director.storage_url.follow_request_host');

        if (! MkRequestAwareStorageUrl::shouldApply(
            $this->app->environment('local'),
            $this->app->runningInConsole(),
            $configured === null ? null : (bool) $configured,
        )) {
            return;
        }

        // Se resuelve por callback y no acá: en `boot()` todavía puede no
        // haber una request, y además así cada request usa SU propio host
        // (importa con Octane/Swoole, donde el proceso sobrevive entre
        // requests y un valor calculado una sola vez quedaría pegado).
        $this->app->booted(function (): void {
            $request = $this->app->bound('request') ? $this->app['request'] : null;

            if ($request === null || ! method_exists($request, 'getSchemeAndHttpHost')) {
                return;
            }

            config([
                'filesystems.disks.public.url' => MkRequestAwareStorageUrl::buildUrl(
                    $request->getSchemeAndHttpHost()
                ),
            ]);
        });
    }

    protected function registerTenantMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', TenantResolver::class);
    }

    /**
     * Registra los endpoints opcionales de OpenAPI / Swagger.
     *
     * F1.4 (LAR-04 HIGH): las rutas están GATEADAS por
     * `config('mk_director.openapi.enabled', false)` (default OFF — opt-in).
     * Pre-fix, las rutas se registraban incondicionalmente para todos los
     * consumers — fugas de schema de la API a usuarios anónimos en prod.
     *
     * Acepta `config('mk_director.openapi.middleware', [])` como array de
     * middleware que se aplican al `Route::group` (típicamente `mk.auth:admin`
     * para forzar auth, o `auth.basic` para HTTP Basic en escenarios B2B).
     *
     * Para activar:
     *   - Pinear en .env: `MK_OPENAPI_ENABLED=true`
     *   - O en `config/mk_director.php` publicado:
     *       `'openapi' => ['enabled' => true, 'middleware' => ['mk.auth:admin']]`
     */
    protected function registerOpenApiRoutes()
    {
        if (! (bool) config('mk_director.openapi.enabled', false)) {
            return;
        }

        // F1.4: middleware opcional pineado por config. El consumer decide
        // si quiere las rutas públicas (default vacío) o gateadas con
        // mk.auth:{scope} / auth.basic / etc.
        $middleware = (array) config('mk_director.openapi.middleware', []);

        Route::group([
            'prefix' => 'mk',
            'middleware' => $middleware,
        ], function () {
            Route::get('openapi.json', [OpenApiController::class, 'spec'])->name('mk.openapi.spec');
            Route::get('docs', [OpenApiController::class, 'docs'])->name('mk.openapi.docs');
        });
    }

    /**
     * Register a global DB listener to automatically flush cache tags on write.
     * This is the "Magic Cache" feature of MK-Director.
     *
     * R4-004 / R2-007 hardening (1.2.2): the listener now (a) skips system
     * tables (migrations, cache, sessions, queue, etc.) so cron-driven
     * writes and self-references don't trigger cache stampedes, and (b) only
     * acts on write operations (INSERT / UPDATE / DELETE) — the previous
     * `str_contains($query->sql, 'cache')` heuristic only excluded the
     * `cache` table and matched reads too, so the listener never fired
     * reliably.
     *
     * LAR-06 (2026-07-03 audit): the write-verb regex was broadened to
     * require SQL-specific tokens AFTER each verb:
     *   - `delete` MUST be followed by `from` (no more matching `delete()`
     *     PHP function calls in the trace).
     *   - `insert` / `replace` MUST be followed by `into`.
     *   - `update`, `truncate` stay as verbs-on-their-own (UPDATE table /
     *     TRUNCATE table are unambiguous).
     *   - `upsert` is no longer a top-level verb: the SQL emitted by
     *     `Eloquent::upsert()` is `INSERT ... ON DUPLICATE KEY UPDATE`,
     *     which is covered by the `insert\s+into` branch.
     *   - REPLACE / TRUNCATE are supported (REPLACE INTO ... / TRUNCATE TABLE ...).
     *
     * LAR-07 (2026-07-03 audit): the system-tables check was hardened from
     * `str_contains($query->sql, $table)` to a token-aware match. The
     * previous substring match would silently skip a query like
     * `INSERT INTO users (cache_token) VALUES ('x')` (because the SQL
     * contained the substring `cache`), and would also skip consumer tables
     * like `cache_stats` or `password_reset_tokens` (substring overlap).
     * The new check requires the table name to appear as an SQL identifier
     * AFTER the FROM/INTO/UPDATE keyword (or wrapped in backticks).
     *
     * Limitation: the regex below matches SQL DML verbs; raw stored-procedure
     * calls or migrations loaded as SQL are not detected. Documented so
     * callers can `CacheManager::flush([$table])` manually if needed.
     */
    protected function registerGlobalCacheListener()
    {
        if (! config('mk_director.features.auto_cache', false)) {
            return;
        }

        $systemTables = [
            'migrations',
            'cache',
            'cache_locks',
            'sessions',
            'password_resets',
            'password_reset_tokens',
            'jobs',
            'job_batches',
            'failed_jobs',
            'telescope_entries',
            'telescope_monitoring',
        ];

        DB::listen(function ($query) use ($systemTables) {
            // 1. Skip system tables (cron writes, self-references).
            //
            // LAR-07 fix: replace str_contains with a SQL-aware match. The
            // table name MUST appear as an SQL identifier after the
            // FROM/INTO/UPDATE keyword (or wrapped in backticks). This
            // prevents:
            //  - false-positive skips (queries mentioning a system-table
            //    name in a column or value, like `INSERT INTO users (cache_token)`)
            //  - false-positive flushes on consumer tables named like system
            //    tables (e.g. `cache_stats`, `password_reset_tokens_attempts`)
            foreach ($systemTables as $table) {
                $pattern = '/(?:FROM|INTO|UPDATE)\s+`?'.preg_quote($table, '/').'`?\b/i';
                if (preg_match($pattern, $query->sql) === 1) {
                    return;
                }
            }

            // 2. Only act on writes (INSERT INTO / UPDATE / DELETE FROM /
            //    REPLACE INTO / TRUNCATE). The previous regex missed
            //    `delete()` PHP function calls (false positive: any source
            //    mentioning `delete(` triggered the listener). The new
            //    regex requires the SQL-specific token AFTER each verb.
            //
            // Group 1: write verb + qualifier
            // Group 6: table name (the capture group is computed below)
            $writePattern = '/(?:update|delete\s+from|insert\s+into|replace\s+into|truncate)\s+`?(\w+)`?/i';

            if (preg_match($writePattern, $query->sql, $matches)) {
                $table = $matches[1] ?? null;
                if ($table === null || $table === '') {
                    return;  // TRUNCATE without a table name — skip.
                }
                CacheManager::flush([$table.'_all']);

                if (MkDebugConfig::enabled()) {
                    Log::info("MK-Director: Cache flushed for table [{$table}] due to write operation.");
                }
            }
        });
    }
}
