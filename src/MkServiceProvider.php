<?php

namespace Mk\Director;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MkServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/mk_director.php', 'mk_director');

        // MK-Director Plugin Manager
        $this->app->singleton(\Mk\Director\Managers\PluginManager::class, function ($app) {
            return new \Mk\Director\Managers\PluginManager();
        });

        // Auth subsystem (Mk\Director\Auth\AuthServiceProvider)
        $this->app->register(\Mk\Director\Auth\AuthServiceProvider::class);

        // Tenancy subsystem — opt-in. The TenantContext is a
        // singleton so the same instance is shared by the
        // middleware (writer) and the trait (reader).
        $this->app->singleton(\Mk\Director\Tenancy\TenantContext::class);
    }

    public function boot()
    {
        // Load the package's Auth migrations so the abilities / roles /
        // admins tables are available to every project.
        $this->loadMigrationsFrom(__DIR__ . '/Auth/Database/Migrations');

        $this->registerTenantMiddleware();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/mk_director.php' => config_path('mk_director.php'),
            ], 'mk-config');

            $this->commands([
                \Mk\Director\Console\Commands\MkCheckCommand::class,
                \Mk\Director\Console\Commands\MakeModuleCommand::class,
                \Mk\Director\Console\Commands\MakeServiceCommand::class,
                \Mk\Director\Console\Commands\MakeDTOCommand::class,
                \Mk\Director\Console\Commands\GenerateDocsCommand::class,
                \Mk\Director\Console\Commands\LintBoundariesCommand::class,
            ]);
        }

        $this->registerGlobalCacheListener();
        $this->registerOpenApiRoutes();
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
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->pushMiddlewareToGroup('api', \Mk\Director\Tenancy\TenantResolver::class);
    }

    /**
     * Registra los endpoints opcionales de OpenAPI / Swagger
     */
    protected function registerOpenApiRoutes()
    {
        // En un entorno de producción B2B se leería desde la config. Por defecto expuestos bajo /mk/
        \Illuminate\Support\Facades\Route::group(['prefix' => 'mk'], function () {
            \Illuminate\Support\Facades\Route::get('openapi.json', [\Mk\Director\Controllers\OpenApiController::class, 'spec'])->name('mk.openapi.spec');
            \Illuminate\Support\Facades\Route::get('docs', [\Mk\Director\Controllers\OpenApiController::class, 'docs'])->name('mk.openapi.docs');
        });
    }

    /**
     * Register a global DB listener to automatically flush cache tags on write.
     * This is the "Magic Cache" feature of MK-Director.
     */
    protected function registerGlobalCacheListener()
    {
        if (!config('mk_director.features.auto_cache', false)) {
            return;
        }

        DB::listen(function ($query) {
            // Avoid infinite loops if we are querying the cache table itself (if stored in DB)
            if (str_contains($query->sql, 'cache')) {
                return;
            }

            // Detect write operations (INSERT, UPDATE, DELETE)
            if (preg_match('/(update|delete|insert into)\s+`?(\w+)`?/i', $query->sql, $matches)) {
                $table = $matches[2];
                Cache::tags([$table . '_all'])->flush();
                
                if (config('mk_director.debug', false)) {
                    Log::info("MK-Director: Cache flushed for table [{$table}] due to write operation.");
                }
            }
        });
    }
}
