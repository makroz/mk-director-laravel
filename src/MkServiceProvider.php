<?php

declare(strict_types=1);

namespace Mk\Director;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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
use Mk\Director\Console\Commands\MkSkillDeployCommand;
use Mk\Director\Console\Commands\MkSkillListCommand;
use Mk\Director\Console\Commands\MkUpdateCommand;
use Mk\Director\Console\Commands\SecurityLintCommand;
use Mk\Director\Controllers\OpenApiController;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantResolver;

class MkServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mk_director.php', 'mk_director');

        // MK-Director Plugin Manager
        $this->app->singleton(PluginManager::class, function ($app) {
            return new PluginManager;
        });

        // Auth subsystem (Mk\Director\Auth\AuthServiceProvider)
        $this->app->register(AuthServiceProvider::class);

        // Tenancy subsystem — opt-in. The TenantContext is a
        // singleton so the same instance is shared by the
        // middleware (writer) and the trait (reader).
        $this->app->singleton(TenantContext::class);
    }

    public function boot()
    {
        // Load the package's Auth migrations so the abilities / roles /
        // admins tables are available to every project.
        $this->loadMigrationsFrom(__DIR__.'/Auth/Database/Migrations');

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
            } catch (\Throwable) {
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
                // R-PKG-015 BUG-NEW-09: helper command para parche de Sanctum UUIDs.
                FixSanctumUuidsCommand::class,
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
        $abilitiesTable = config('mk_director.auth.tables.abilities', 'abilities');
        if (! Schema::hasTable($abilitiesTable)) {
            Log::debug("MK-Director: skip auto-discover-abilities — table [{$abilitiesTable}] not migrated yet.");

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
    protected function registerTenantMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', TenantResolver::class);
    }

    /**
     * Registra los endpoints opcionales de OpenAPI / Swagger
     */
    protected function registerOpenApiRoutes()
    {
        // En un entorno de producción B2B se leería desde la config. Por defecto expuestos bajo /mk/
        Route::group(['prefix' => 'mk'], function () {
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
     * Limitation: the regex below matches `update|delete|insert into`. It
     * does NOT match `REPLACE`, `TRUNCATE`, raw stored-procedure calls, or
     * Eloquent `upsert()` (which uses `INSERT ... ON DUPLICATE KEY UPDATE`).
     * Those mutations will not invalidate the cache. Documented so callers
     * can `Cache::tags([$table . '_all'])->flush()` manually if needed.
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
            foreach ($systemTables as $table) {
                if (str_contains($query->sql, $table)) {
                    return;
                }
            }

            // 2. Only act on writes (INSERT / UPDATE / DELETE / REPLACE / TRUNCATE / upsert).
            //
            // R-PKG-024 (rc13): regex broadened to cover `REPLACE`, `TRUNCATE`,
            // and `upsert()` (Eloquent upsert generates `INSERT ... ON DUPLICATE
            // KEY UPDATE` on MySQL/MariaDB — covered by `insert\s+into`).
            // The previous regex missed these mutations, leaving stale cache
            // after `TRUNCATE TABLE` or `Eloquent::upsert()`.
            //
            // Group 1: write verb (update|delete|insert[ into]|replace[ into]|upsert|truncate)
            // Group 5: table name
            if (preg_match('/(update|delete|insert(\s+into)?|replace(\s+into)?|upsert|truncate)\s+`?(\w+)`?/i', $query->sql, $matches)) {
                $table = $matches[5] ?? null;
                if ($table === null) {
                    return;  // TRUNCATE without a table name — skip.
                }
                Cache::tags([$table.'_all'])->flush();

                if (config('mk_director.debug', false)) {
                    Log::info("MK-Director: Cache flushed for table [{$table}] due to write operation.");
                }
            }
        });
    }
}
