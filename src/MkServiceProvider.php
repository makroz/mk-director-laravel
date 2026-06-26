<?php

declare(strict_types=1);

namespace Mk\Director;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
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
     */
    protected function registerAutoDiscoverAbilities(): void
    {
        if (! config('mk_director.features.auto_discover_abilities', false)) {
            return;
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        // Don't auto-run when the artisan command IS mk:discover-abilities
        // (avoid infinite recursion when developers run it interactively).
        if (isset($_SERVER['argv'][1]) && str_starts_with((string) $_SERVER['argv'][1], 'mk:discover-abilities')) {
            return;
        }

        try {
            $exitCode = $this->app->call(DiscoverAbilitiesCommand::class, [
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

            // 2. Only act on writes (INSERT / UPDATE / DELETE).
            if (preg_match('/(update|delete|insert\s+into)\s+`?(\w+)`?/i', $query->sql, $matches)) {
                $table = $matches[2];
                Cache::tags([$table.'_all'])->flush();

                if (config('mk_director.debug', false)) {
                    Log::info("MK-Director: Cache flushed for table [{$table}] due to write operation.");
                }
            }
        });
    }
}
