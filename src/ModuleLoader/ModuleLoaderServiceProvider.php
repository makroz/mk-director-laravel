<?php

declare(strict_types=1);

namespace Mk\Director\ModuleLoader;

use Illuminate\Support\ServiceProvider;

/**
 * Auto-registers every ServiceProvider found under the app's Modules directory.
 *
 * Implements the MME rule (R-MK-001): modules own their wiring. A module
 * just needs to drop a ServiceProvider class in its Providers folder
 * and the rest of the app picks it up.
 *
 * The base bootstrap/providers.php only needs to register this one
 * provider instead of every individual module's provider.
 */
class ModuleLoaderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Nothing to register explicitly — the providers we discover
        // are bound by the container when they're resolved.
    }

    public function boot(): void
    {
        $modulesPath = app_path('Modules');

        if (! is_dir($modulesPath)) {
            return;
        }

        foreach (new \DirectoryIterator($modulesPath) as $module) {
            if ($module->isDot() || ! $module->isDir()) {
                continue;
            }

            $providerClass = sprintf(
                'App\\Modules\\%s\\Providers\\%sServiceProvider',
                $module->getFilename(),
                $module->getFilename(),
            );

            if (! class_exists($providerClass)) {
                // No provider in this module — log and continue.
                if ($this->app->runningInConsole()) {
                    $this->app->make('log')->warning(
                        sprintf('ModuleLoader: no provider for module [%s], skipping.', $module->getFilename())
                    );
                }
                continue;
            }

            // Laravel resolves providers via the bootstrap config; for a
            // runtime-discovered module we register the provider on the
            // app, which kicks off register() + boot().
            $this->app->register($providerClass);
        }
    }
}
