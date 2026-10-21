<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JsonException;
use RuntimeException;

/**
 * The capture of hand-written code for `mk-studio:perf --capture`: while a module's tests run, every SELECT the app's
 * code runs is appended to a JSONL file, one line per query:
 *
 *     {"database", "connection", "sql", "bindings", "file", "line", "outside", "group", "test", "path"}
 *
 *  - `file`/`line`: the innermost stack frame in the measured module (`<modules>/<Module>/…`), the line that called
 *    the query; with none, the innermost frame of the app's own code (`app/` or another module) and `outside: true`.
 *    A query no app code ran (the test itself, the framework) is not written: it is not the module's. Test setup
 *    (migrations, factories, seeders) and a middleware handing the request on are not app code ({@see Frames});
 *  - `group`: one per app instance (a test), and a new one at each HTTP request handled, artisan command and queue job
 *    started or finished: a command run three times is three groups, not one loop;
 *  - `test`: the test's own line that called into the app (`tests/…:<line>`, {@see Frames::testFrame()}), null when no
 *    test file is on the stack. With `group` it is the unit a repeat is counted in: two service calls from two lines of
 *    one test are two units, a loop inside the app is one;
 *  - `path`: the call site, every app frame on the stack ({@see Frames::appPath()}): one helper reached from two lines
 *    is two sites, the same loop body asking again is one;
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

    public static function register(string $file, string $module): void
    {
        $default = (string) config('database.default');
        $database = (string) config("database.connections.{$default}.database");
        if (! preg_match(ScratchDatabase::TESTS_NAME, $database)) {
            throw new RuntimeException("mk-studio capture refused: the tests would run on {$database}, not on a *_mk_studio_perf_tests database.");
        }
        $state = (object) ['group' => bin2hex(random_bytes(4))];
        Event::listen([RequestHandled::class, CommandStarting::class, CommandFinished::class, JobProcessing::class, JobProcessed::class], static function () use ($state): void {
            $state->group = bin2hex(random_bytes(4));
        });

        DB::listen(static function (QueryExecuted $q) use ($file, $module, $state): void {
            if (! QueryRecorder::isSelect($q->sql) || QueryRecorder::isEngineQuery($q->sql)) {
                return;
            }
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $frame = Frames::firstAppFrame($trace, $module);
            if ($frame === null) {
                return;
            }
            try {
                $line = json_encode([
                    'database' => $q->connection->getDatabaseName(),
                    'connection' => $q->connectionName,
                    'sql' => $q->sql,
                    'bindings' => QueryRecorder::bindable($q->connection, $q->bindings),
                    'file' => $frame['file'],
                    'line' => $frame['line'],
                    'outside' => $frame['module'] !== $module,
                    'group' => $state->group,
                    'test' => Frames::testFrame($trace),
                    'path' => Frames::appPath($trace),
                    'once' => Frames::enteredOnce($trace),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (JsonException) {
                return; // a binary binding: not a shape the replay could send back
            }
            file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
        });
    }
}
