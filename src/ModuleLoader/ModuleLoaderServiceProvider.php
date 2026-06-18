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
        // Bind the registry so consumers (and tests) can swap it.
        $this->app->singleton(ModuleProviderRegistry::class);
    }

    public function boot(): void
    {
        // R4-006 / R2-016: use the registry instead of walking the
        // modules directory on every boot. The registry caches the
        // discovery for 1 hour by default and rejects symlinks.
        /** @var ModuleProviderRegistry $registry */
        $registry = $this->app->make(ModuleProviderRegistry::class);

        foreach ($registry->discover() as $providerClass) {
            $this->app->register($providerClass);
        }
    }
}
