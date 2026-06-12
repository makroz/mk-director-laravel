<?php

declare(strict_types=1);

namespace Mk\Director\Services;

/**
 * Extracts routes + handlers from MME modules.
 *
 * Walks `app/Modules/{Name}/Http/Routes/api.php` in the consumer app and
 * returns each registered route so the OpenApiGeneratorService can
 * document every module's API surface, not just SmartController CRUD.
 */
final class ModuleRouteExtractor
{
    /**
     * @return list<array{
     *     module: string,
     *     file: string,
     *     method: string,
     *     uri: string,
     *     handler: string,
     *     middleware: list<string>,
     * }>
     */
    public function extract(): array
    {
        $modulesPath = app_path('Modules');
        if (! is_dir($modulesPath)) {
            return [];
        }

        $out = [];
        foreach (new \DirectoryIterator($modulesPath) as $module) {
            if ($module->isDot() || ! $module->isDir()) {
                continue;
            }
            $moduleName = $module->getFilename();
            $routesFile = $module->getPathname() . '/Http/Routes/api.php';
            if (! is_file($routesFile)) {
                continue;
            }

            $out = array_merge($out, $this->extractFromFile($moduleName, $routesFile));
        }

        return $out;
    }

    /**
     * Parse a single api.php file. Uses regex because we want a
     * zero-config parser — `php artisan route:list` would require the
     * app to be booted, which is heavier than this scan.
     *
     * @return list<array{module: string, file: string, method: string, uri: string, handler: string, middleware: list<string>}>
     */
    private function extractFromFile(string $module, string $file): array
    {
        $src = (string) file_get_contents($file);
        $out = [];

        // Normalize line endings and strip comments so regexes don't match commented-out routes.
        $src = preg_replace('/\r\n?/', "\n", $src);
        $src = preg_replace('/\/\*.*?\*\//s', '', $src) ?? $src;
        $src = preg_replace('/\/\/.*/', '', $src) ?? $src;

        // Route::get('path', [Handler::class, 'action'])->middleware([...])->name('...');
        // Route::prefix('foo')->group(function () { ... });
        // Allow the FQCN part to include backslashes (App\Modules\Admin\Http\Controllers\AuthController).
        $pattern = "/Route::(get|post|put|patch|delete|options|head)\\(\\s*['\"]([^'\"]+)['\"]\\s*,\\s*\\[?\\s*((?:\\\\?[A-Za-z_][\\w\\\\]*)+)::class\\s*,\\s*['\"]([^'\"]+)['\"]/i";
        if (preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $method = strtoupper((string) $m[1]);
                $uri = (string) $m[2];
                $controller = ltrim((string) $m[3], '\\');
                $action = (string) $m[4];
                $out[] = [
                    'module' => $module,
                    'file' => $file,
                    'method' => $method,
                    'uri' => $uri,
                    'handler' => $controller . '@' . $action,
                    'middleware' => [],
                ];
            }
        }

        return $out;
    }
}
