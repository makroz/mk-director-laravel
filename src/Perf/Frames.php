<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Pipeline\Pipeline;

/**
 * Which line of code ran a query, from its stack (`debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)`, innermost first):
 * one rule for the tests' capture ({@see CaptureListener}) and for the screens ({@see ScreenRunner}).
 *
 * A frame is skipped when it is not the code that asked for the query:
 *  - vendor: anything outside the app's own folders (`app/` and the modules folder);
 *  - test setup: migrations, factories and seeders (`Database/Migrations|Factories|Seeders/` of any module,
 *    `database/` at the root), which `RefreshDatabase` and the tests run before the code under test;
 *  - a pass-through: a middleware handing the request on (`$next($request)`, a call into the pipeline), which sits on
 *    the stack of every query its request runs.
 *
 * `file` is relative to the project's base path (a file outside it stays as the stack names it); `module` is the
 * folder under the modules path (`app/Modules/<Module>/…`), null for the rest of the app.
 */
final class Frames
{
    /** Test setup: frames here are skipped, as if they were the framework's. */
    private const SETUP = '#/database/(migrations|factories|seeders)/#i';

    /** Where a package frame is reported from: the path the package has in any project's vendor folder. */
    public const PACKAGE_PATH = 'vendor/makroz/director-laravel/';

    /**
     * The innermost frame of the app's own code; with `$module`, the innermost frame in that module wins over the
     * rest of the app (the capture measures one module: a frame outside it is `outside`).
     *
     * @param  list<array<string, mixed>>  $trace
     * @return array{file: string, line: int, module: ?string}|null
     */
    public static function firstAppFrame(array $trace, ?string $module = null): ?array
    {
        $modules = self::dir((string) config('mk_director.paths.modules', app_path('Modules')));
        $app = self::dir(app_path());
        $first = null;
        foreach ($trace as $f) {
            $path = (string) ($f['file'] ?? '');
            if ($path === '' || preg_match(self::SETUP, $path) === 1 || self::passesThrough($f)) {
                continue;
            }
            $in = str_starts_with($path, $modules) ? (strstr(substr($path, strlen($modules)), '/', true) ?: null) : null;
            if ($in === null && ! str_starts_with($path, $app)) {
                continue;
            }
            if ($module === null || $in === $module) {
                return self::frame($f, $in);
            }
            $first ??= self::frame($f, $in);
        }

        return $first;
    }

    /**
     * The innermost frame of this package outside its measuring harness (`Perf/`, the console commands), as
     * `vendor/makroz/director-laravel/…`: never the folder it is installed or linked from on this machine.
     *
     * @param  list<array<string, mixed>>  $trace
     * @return array{file: string, line: int, module: null}|null
     */
    public static function packageFrame(array $trace): ?array
    {
        $root = self::dir(dirname(__DIR__, 2));
        foreach ($trace as $f) {
            $path = (string) ($f['file'] ?? '');
            if (! str_starts_with($path, $root)) {
                continue;
            }
            $file = substr($path, strlen($root));
            // `vendor/`: the package's own dependencies, when it runs from its checkout (its tests).
            if (! preg_match('#^(src/Perf/|src/Console/|vendor/)#', $file)) {
                return ['file' => self::PACKAGE_PATH.$file, 'line' => (int) ($f['line'] ?? 0), 'module' => null];
            }
        }

        return null;
    }

    /** A middleware's `$next($request)`: the frame called into the pipeline, it did not ask for the query. */
    private static function passesThrough(array $f): bool
    {
        $class = (string) ($f['class'] ?? '');

        return $class !== '' && str_contains((string) ($f['function'] ?? ''), '{closure') && is_a($class, Pipeline::class, true);
    }

    /** @return array{file: string, line: int, module: ?string} */
    private static function frame(array $f, ?string $module): array
    {
        $base = self::dir(base_path());
        $file = (string) $f['file'];

        return [
            'file' => str_starts_with($file, $base) ? substr($file, strlen($base)) : $file,
            'line' => (int) ($f['line'] ?? 0),
            'module' => $module,
        ];
    }

    /** The folder as the stack names it (symlinks resolved), with a trailing slash. */
    private static function dir(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/').'/';
    }
}
