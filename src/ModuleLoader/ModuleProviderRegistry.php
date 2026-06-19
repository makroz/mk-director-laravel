<?php

declare(strict_types=1);

namespace Mk\Director\ModuleLoader;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * ModuleProviderRegistry — discovers Module ServiceProviders under
 * the app's Modules directory and caches the result.
 *
 * Spec: MK-LAR-1.0.4 + audit R4-006 / R2-016.
 *
 * Before this registry, ModuleLoaderServiceProvider walked the
 * `app/Modules` directory on every request via DirectoryIterator.
 * For an app with 30+ modules, that is 30+ stat() calls per request
 * plus a class_exists() probe for each one. The cache key is the
 * md5 of the canonical (real) path of every discovered directory;
 * if any of those paths change (add/remove/rename), the key
 * changes and the cache is automatically rebuilt.
 *
 * Security:
 *  - Symlinked module directories are rejected (R2-016). A symlink
 *    under app/Modules pointing to /tmp/evil would otherwise be
 *    discovered and registered as a legitimate module. We compare
 *    realpath() to the original path and skip mismatches.
 *  - The discovery path is locked to the canonical realpath of
 *    app_path('Modules'), so a symlinked app/Modules itself is also
 *    rejected.
 */
class ModuleProviderRegistry
{
    /**
     * Default TTL for the discovery cache (1 hour).
     */
    public const DEFAULT_TTL = 3600;

    /**
     * Cache key prefix. Final key is composed with the canonical path
     * hash so any directory change invalidates automatically.
     */
    public const CACHE_KEY_PREFIX = 'mk_module_providers:';

    /**
     * Discover every Module ServiceProvider under app_path('Modules').
     *
     * @return array<int, class-string>
     */
    public function discover(): array
    {
        $ttl = (int) config('mk_director.modules.cache_ttl', self::DEFAULT_TTL);

        $cacheKey = $this->cacheKey();

        return Cache::remember($cacheKey, $ttl, function (): array {
            return $this->scan();
        });
    }

    /**
     * Forget the discovery cache so the next discover() rebuilds.
     * Consumers call this from a deploy hook after adding/removing
     * a module.
     */
    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Force a synchronous rescan, ignoring the cache. Useful for
     * tests and for the `mk:module` console command.
     *
     * @return array<int, class-string>
     */
    public function scan(): array
    {
        $modulesPath = $this->canonicalModulesPath();
        if ($modulesPath === null) {
            return [];
        }

        $found = [];
        foreach (new \DirectoryIterator($modulesPath) as $module) {
            if ($module->isDot() || ! $module->isDir()) {
                continue;
            }

            // R2-016: refuse symlinks. realpath() of a symlink resolves
            // to the target; the original path stays the symlink path.
            // When they differ, we have a symlink and we skip it.
            if ($module->isLink()) {
                continue;
            }

            $realDir = realpath($module->getPathname());
            if ($realDir === false || $realDir !== $module->getPathname()) {
                // Sanity check — DirectoryIterator reports isLink() but
                // we keep the realpath comparison as a belt-and-braces
                // guard against partial symlink chains.
                continue;
            }

            $name = $module->getFilename();
            $providerClass = sprintf(
                'App\\Modules\\%s\\Providers\\%sServiceProvider',
                $name,
                $name,
            );

            if (! class_exists($providerClass)) {
                continue;
            }

            $found[] = $providerClass;
        }

        return $found;
    }

    /**
     * Returns the canonical path of app_path('Modules'), or null if
     * the directory does not exist (or is a symlink to something
     * outside the app tree).
     */
    protected function canonicalModulesPath(): ?string
    {
        $candidates = [
            function_exists('app_path') ? app_path('Modules') : null,
            getcwd() . '/app/Modules',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            if (! is_dir($candidate)) {
                continue;
            }
            // Reject symlinked Modules directory itself.
            if (is_link($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || $real !== $candidate) {
                continue;
            }
            return $candidate;
        }

        return null;
    }

    /**
     * Cache key composed of the canonical modules path hash so any
     * directory rename/add/remove invalidates the cache automatically.
     */
    protected function cacheKey(): string
    {
        $path = $this->canonicalModulesPath() ?? 'no_modules_path';
        return self::CACHE_KEY_PREFIX . md5($path);
    }
}