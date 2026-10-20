<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JsonException;
use RuntimeException;

/**
 * The capture of hand-written code for `mk-studio:perf --capture`: while a module's tests run, every SELECT the app's
 * code runs is appended to a JSONL file, one line per query:
 *
 *     {"database", "connection", "sql", "bindings", "file", "line", "outside", "group"}
 *
 *  - `file`/`line`: the innermost stack frame in the measured module (`<modules>/<Module>/…`), the line that called
 *    the query; with none, the innermost frame of the app's own code (`app/` or another module) and `outside: true`.
 *    A query no app code ran (the test itself, the framework) is not written: it is not the module's. Neither is a
 *    frame of test setup: migrations, factories and seeders (`Database/Migrations|Factories|Seeders/` of any module,
 *    `database/` at the root), which `RefreshDatabase` and the tests run before the code under test;
 *  - `group`: one per app instance (a test) and per HTTP request handled, so a shape repeated from one line inside one
 *    group is a query per row;
 *  - `database`: where it ran. The runner refuses a capture with any database but its `*_mk_studio_perf_tests`.
 *
 * Only reads are written ({@see QueryRecorder::isSelect()}: a `with … update` is a write), never a lookup of the
 * engine's catalog ({@see QueryRecorder::isEngineQuery()}: a lock function is not a read of the data either), and only their bindings: the tests' own data.
 *
 * Registered by `MkServiceProvider` only when `MK_PERF_CAPTURE` names the file (set by MK Studio's runner, never in
 * `.env`), and it refuses to start unless the default connection is a `*_mk_studio_perf_tests` database: the
 * project's own testing database or the app's are never the ones the capture runs on.
 */
final class CaptureListener
{
    /** The file to append to. */
    public const FILE_ENV = 'MK_PERF_CAPTURE';

    /** The module measured (its folder: School). */
    public const MODULE_ENV = 'MK_PERF_MODULE';

    /** Test setup: frames here are skipped, as if they were the framework's. */
    private const SETUP = '#/database/(migrations|factories|seeders)/#i';

    public static function register(string $file, string $module): void
    {
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");
        if (! preg_match(ScratchDatabase::TESTS_NAME, $database)) {
            throw new RuntimeException("mk-studio capture refused: the tests would run on {$database}, not on a *_mk_studio_perf_tests database.");
        }
        $modules = self::dir((string) config('mk_director.paths.modules', app_path('Modules')));
        $own = "{$modules}{$module}/";
        $app = self::dir(app_path());
        $base = self::dir(base_path());
        $state = (object) ['group' => bin2hex(random_bytes(4))];
        Event::listen(RequestHandled::class, static function () use ($state): void {
            $state->group = bin2hex(random_bytes(4));
        });

        DB::listen(static function (QueryExecuted $q) use ($file, $own, $modules, $app, $base, $state): void {
            if (! QueryRecorder::isSelect($q->sql) || QueryRecorder::isEngineQuery($q->sql)) {
                return;
            }
            $module = $outside = null;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $f) {
                $path = $f['file'] ?? '';
                if (preg_match(self::SETUP, $path) === 1) {
                    continue;
                }
                if (str_starts_with($path, $own)) {
                    $module = $f;
                    break;
                }
                if ($outside === null && (str_starts_with($path, $app) || str_starts_with($path, $modules))) {
                    $outside = $f;
                }
            }
            $frame = $module ?? $outside;
            if ($frame === null) {
                return;
            }
            try {
                $line = json_encode([
                    'database' => $q->connection->getDatabaseName(),
                    'connection' => $q->connectionName,
                    'sql' => $q->sql,
                    'bindings' => QueryRecorder::bindable($q->connection, $q->bindings),
                    'file' => str_starts_with($frame['file'], $base) ? substr($frame['file'], strlen($base)) : $frame['file'],
                    'line' => $frame['line'] ?? 0,
                    'outside' => $module === null,
                    'group' => $state->group,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                return; // a binary binding: not a shape the replay could send back
            }
            file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
        });
    }

    /** The folder as the stack names it (symlinks resolved), with a trailing slash. */
    private static function dir(string $path): string
    {
        return rtrim(realpath($path) ?: $path, '/').'/';
    }
}
