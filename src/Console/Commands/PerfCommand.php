<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mk\Director\Perf\PerfAdmin;
use Mk\Director\Perf\Plans\MariaDbPlanReader;
use Mk\Director\Perf\Plans\PostgresPlanReader;
use Mk\Director\Perf\QueryRecorder;
use Mk\Director\Perf\ScratchDatabase;
use Mk\Director\Perf\ScreenRunner;
use Mk\Director\Perf\VolumeSeeder;
use Mk\Director\Spec\Introspector;
use OutOfBoundsException;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * `mk-studio:perf` — measures one module on a throwaway copy of the schema and prints one `mk-perf/v1` run as
 * JSON on stdout, for MK Studio's «Rendimiento» tab.
 *
 *   1. read the app ({@see Introspector}): its models, foreign keys and polymorphic pairs, on its own database (read
 *      only: the pairs' targets are the aliases its rows store);
 *   2. create `<db>_mk_studio_perf` (empty) on the app's own server and migrate it ({@see ScratchDatabase});
 *   3. fill the module's relation graph at the volume asked ({@see VolumeSeeder}) and refresh the planner's statistics;
 *   4. create an admin with `'*'` and its token ({@see PerfAdmin});
 *   5. request every screen of each record (list, each filter, search, each sort, count, show) through the HTTP
 *      kernel, a warm-up and `--runs` times, and the list-like ones at per_page 1/8/20 ({@see ScreenRunner});
 *   6. read the plan of each distinct SELECT the screens ran, once ({@see PlanReader});
 *   7. with `--capture`, replay each SELECT shape the module's tests ran that no screen ran ({@see CaptureListener}):
 *      its median and plan on the volume, with the tests' own bindings (`replayed`: a binding may name a row the
 *      volume does not have, so the plan holds and the time may read low). A shape that fails there is a note;
 *   8. list the module's indexes no plan used;
 *   9. drop the scratch database and the tests' one (both kept with `--keep`) and print the run, with the versions
 *      that ran it (PHP, Laravel, this package).
 *
 * `--prepare-tests` only creates `<db>_mk_studio_perf_tests` (empty, dropping a stale one) and prints its name: MK
 * Studio runs the module's tests there with the capture on, then this command with `--capture`, which drops it.
 *
 * The app's database is never written.
 *
 * @see \Mk\Director\Perf\Plans\PlanReader
 */
final class PerfCommand extends Command
{
    protected $signature = 'mk-studio:perf
        {--module= : The module to measure (as its folder: School)}
        {--volume=medium : small, medium or large (rows of the module\'s leaf tables: 1,000, 20,000 or 200,000)}
        {--runs=50 : Measured requests per screen, after one warm-up}
        {--capture= : The JSONL the module\'s tests wrote with MK_PERF_CAPTURE: its SELECTs are replayed on the volume}
        {--prepare-tests : Only create the empty <db>_mk_studio_perf_tests database for the tests and print its name}
        {--keep : Keep the scratch databases after the run}
        {--pretty : Indented JSON}';

    protected $description = 'Measures a module\'s screens on a scratch copy of the schema and prints the run as JSON for MK Studio.';

    /** Replays of a captured shape, after one warm-up: a median needs a few; the screens' `--runs` would be slow. */
    private const REPLAYS = 5;

    public function handle(ScratchDatabase $db, VolumeSeeder $seed, ScreenRunner $screens, QueryRecorder $rec): int
    {
        if ($this->option('prepare-tests')) {
            return $this->prepareTests($db);
        }
        $module = (string) $this->option('module');
        $volume = (string) $this->option('volume');
        $runs = (int) $this->option('runs');
        if (! preg_match('/^[A-Z][A-Za-z0-9]*$/', $module)) {
            return $this->refuse('--module=<Module> is required (the module folder, e.g. School).');
        }
        if (! isset(VolumeSeeder::ROWS[$volume])) {
            return $this->refuse("--volume={$volume}: small, medium or large.");
        }
        if ($runs < 1 || $runs > 1000) {
            return $this->refuse('--runs must be between 1 and 1000.');
        }

        $capture = $this->option('capture');
        if ($capture !== null && ! is_readable((string) $capture)) {
            return $this->refuse("--capture={$capture}: no such file.");
        }

        $started = hrtime(true);
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        try {
            try {
                $notes = [];
                $captured = $capture === null ? null : $this->captured((string) $capture, $notes);
                // Before the scratch database: the pairs' targets are read from the app's rows.
                $intro = (new Introspector([config('mk_director.paths.modules', app_path('Modules'))]))->collect();
                $connection = $db->create();
                try {
                    $db->migrate($connection);
                    $run = $this->measure($connection, $module, $volume, $runs, $intro, $seed, $screens, $rec, $captured, $notes);
                } finally {
                    $this->option('keep') ? $db->release($connection) : $db->drop($connection);
                }
            } finally {
                // The tests' database (`--prepare-tests`), whether they left a capture or not.
                if (! $this->option('keep') && ! app()->environment('production')) {
                    $db->discard(ScratchDatabase::TESTS_SUFFIX);
                }
            }
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }

        $run = [
            'schema' => 'mk-perf/v1',
            'module' => $module,
            ...$run,
            'volume' => $volume,
            'runs' => $runs,
            'branch' => $this->git('rev-parse --abbrev-ref HEAD'),
            'commit' => $this->git('rev-parse HEAD'),
            'startedAt' => $startedAt,
            'durationMs' => (int) round((hrtime(true) - $started) / 1e6),
            'versions' => self::versions(),
        ];
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $this->output->writeln(json_encode($run, $flags | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** `--prepare-tests`: the tests' empty database, with the same guards as the scratch one; prints `{"database"}`. */
    private function prepareTests(ScratchDatabase $db): int
    {
        try {
            $connection = $db->create(ScratchDatabase::TESTS_SUFFIX);
            $database = (string) config("database.connections.{$connection}.database");
            $db->release($connection);
        } catch (Throwable $e) {
            return $this->refuse($e->getMessage());
        }
        $this->output->writeln(json_encode(['database' => $database], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * The capture's SELECT shapes, one per normalized SQL and line of code that ran it: every line of the module, and,
     * for a shape no line of the module ran, the first line outside it. A line from any database but a
     * `*_mk_studio_perf_tests` one is not replayed.
     *
     * `duplicates` is how many more times the line ran the shape than its unit ran at all, at worst. A unit is one call of
     * the test into the app (`group`: a request, a command or a job; `test`: the test's line that called), and the times it
     * ran are the fewest any of its shapes ran: a test looping over a service three times runs every shape of it three
     * times, so none of them repeats, while a loop inside the app runs its body's shapes once per row. Inside a request,
     * a command or a job (`once`) the unit was entered once by construction, so every run past the first is a repeat,
     * even when every shape of it is inside the loop (rows passed in, not read by a query of the unit). The runs of one
     * shape are counted per call site (`path`, the app frames on the stack): one helper read for two settings from two
     * lines is not a repeat. A capture without `test` or `path` (an older package) counts per `group` and line.
     *
     * ponytail: bindings are not compared. A per-row read of one same value (a setting asked inside the loop) is the N+1
     * this rule exists for; code a test calls directly, whose every shape runs once per row, has no shape that tells the
     * unit entered once (a loop of the test looks the same), and reads as no repeat.
     *
     * @param  list<string>  $notes
     * @return array<string, array{shape: string, sql: string, bindings: array, file: string, line: int, outside: bool, duplicates: int}>
     */
    private function captured(string $file, array &$notes): array
    {
        $out = [];
        $units = [];
        $foreign = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $json) {
            $c = json_decode($json, true);
            if (! is_array($c) || ! is_string($c['sql'] ?? null) || ! QueryRecorder::isSelect($c['sql']) || QueryRecorder::isEngineQuery($c['sql'])) {
                continue;
            }
            if (! preg_match(ScratchDatabase::TESTS_NAME, (string) ($c['database'] ?? ''))) {
                $foreign++;

                continue;
            }
            $sql = QueryRecorder::normalize($c['sql']);
            $at = "{$sql}\0{$c['file']}:{$c['line']}";
            $out[$at] ??= [
                'shape' => $sql, 'sql' => $c['sql'], 'bindings' => (array) ($c['bindings'] ?? []), 'file' => (string) $c['file'],
                'line' => (int) $c['line'], 'outside' => (bool) $c['outside'], 'duplicates' => 0,
            ];
            $run = $at."\0".($c['path'] ?? '');
            $unit = &$units[($c['group'] ?? '').'|'.($c['test'] ?? '').'|'.(($c['once'] ?? false) ? 'once' : '')];
            $unit[$run] = ($unit[$run] ?? 0) + 1;
            unset($unit);
        }
        foreach ($units as $key => $runs) {
            $entered = str_ends_with((string) $key, '|once') ? 1 : min($runs);
            foreach ($runs as $run => $n) {
                $at = substr($run, 0, strrpos($run, "\0"));
                $out[$at]['duplicates'] = max($out[$at]['duplicates'], intdiv($n, $entered) - 1);
            }
        }
        if ($foreign > 0) {
            $notes[] = "{$foreign} consultas capturadas no corrieron en una base *_mk_studio_perf_tests: no se repitieron.";
        }
        $inside = array_column(array_filter($out, static fn (array $c): bool => ! $c['outside']), 'shape', 'shape');
        $kept = [];

        return array_filter($out, static function (array $c) use ($inside, &$kept): bool {
            if (! $c['outside']) {
                return true;
            }
            if (isset($inside[$c['shape']]) || isset($kept[$c['shape']])) {
                return false;
            }

            return $kept[$c['shape']] = true;
        });
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $captured  null: the module's tests did not run
     * @param  list<string>  $notes
     * @return array<string, mixed> the run's measured part
     */
    private function measure(string $connection, string $module, string $volume, int $runs, array $intro, VolumeSeeder $seed, ScreenRunner $screens, QueryRecorder $rec, ?array $captured, array $notes): array
    {
        [$engine, $version] = $this->engine($connection);
        $tables = [];
        $graph = ['casts' => [], 'children' => [], 'morphs' => []];
        foreach ($intro['models'] as $model) {
            $casts = [...($graph['casts'][$model['table']] ?? []), ...(array) $model['casts']];
            $graph['casts'][$model['table']] = $casts;
            if ($model['module'] !== $module) {
                continue;
            }
            $tables[$model['table']] = $casts;
            // Its children: the rows that hang on the module's records (has-many, morph-many, a pivot).
            foreach ((array) $model['relations'] as $r) {
                if (in_array($r['type'], ['HasMany', 'HasOne', 'MorphMany', 'MorphOne'], true)) {
                    $graph['children'][] = $r['table'];
                } elseif (isset($r['pivot'])) {
                    $graph['children'][] = $r['pivot'];
                }
            }
        }
        if ($tables === []) {
            throw new RuntimeException("the module {$module} has no models.");
        }
        $morphMap = (array) $intro['morphMap'];
        foreach ($intro['morphs'] as $m) {
            $targets = [];
            foreach ($m['targets'] as $alias) {
                $targets[$alias] = $morphMap[$alias]['table'] ?? null; // null: an alias the morph map does not name
            }
            $graph['morphs'][] = [...$m, 'targets' => $targets];
        }

        $filled = $seed->fill($connection, $tables, $volume, $graph);
        $this->analyze($connection, array_keys($filled));
        $token = (new PerfAdmin)->make($seed, $connection);
        // The token is unbound (no DPoP key: the screens run in-process), and a scope that requires DPoP would refuse it
        // with ERR_DPOP_UNBOUND. This run measures queries on a scratch copy, not auth: the requirement is off for it.
        config(['mk_director.auth.dpop.scopes' => []]);
        $queries = [];
        $shapes = [];
        $list = $screens->screens($module, $token, $intro);
        foreach ($list as $screen) {
            $m = $screens->measure($screen, $runs, $rec);
            $shapes += $m['shapes'];
            $origin = ['kind' => 'screen', 'record' => $screen['record'], 'screen' => $screen['screen'], ...($screen['param'] === null ? [] : ['param' => $screen['param']])];
            $queries[] = [
                'id' => substr(sha1("screen:{$screen['record']}:{$screen['screen']}:{$screen['param']}:{$screen['uri']}:{$m['sql']}"), 0, 12),
                'origin' => $origin,
                'uri' => $screen['uri'],
                'status' => $m['status'],
                'sql' => $m['sql'],
                'medianMs' => $m['medianMs'],
                'p95Ms' => $m['p95Ms'],
                'queryCount' => $m['queryCount'],
                'queriesAt' => $m['queriesAt'],
                ...($m['frames'] === null ? [] : ['frames' => $m['frames']]),
                'duplicates' => $m['duplicates'],
                'plan' => null,
            ];
        }

        // Each distinct SELECT of the run, once, on the volume: the screens' main queries and everything else they ran.
        $reader = $engine === 'pgsql' ? new PostgresPlanReader : new MariaDbPlanReader;
        $plans = [];
        foreach ($shapes as $sql => $s) {
            try {
                $plans[$sql] = $reader->read($connection, $s['raw'], $s['bindings']);
            } catch (Throwable $e) {
                $notes[] = 'Sin plan para '.Str::limit($sql, 120).': '.Str::limit(strtok($e->getMessage(), "\n") ?: $e::class, 200);
            }
        }
        // Hand-written code: the shapes the module's tests ran that no screen ran, with the tests' bindings, each shape
        // replayed once for every line that ran it.
        $timed = [];
        foreach ($captured ?? [] as $c) {
            $sql = $c['shape'];
            if (isset($shapes[$sql])) {
                continue;
            }
            // A row lock cannot run in the plan's read-only transaction, and takes nothing from the plan or the time.
            $replay = QueryRecorder::withoutLock($c['sql']);
            if (! isset($timed[$sql])) {
                try {
                    $ms = [];
                    foreach (range(0, self::REPLAYS) as $i) {
                        $t = hrtime(true);
                        DB::connection($connection)->select($replay, $c['bindings']);
                        if ($i > 0) {
                            $ms[] = (hrtime(true) - $t) / 1e6;
                        }
                    }
                    $plans[$sql] = $reader->read($connection, $replay, $c['bindings']);
                    $timed[$sql] = ScreenRunner::stats($ms);
                } catch (Throwable $e) {
                    $timed[$sql] = false;
                    $notes[] = "{$c['file']}:{$c['line']} no se pudo repetir sobre el volumen (".Str::limit($sql, 120).'): '.Str::limit(strtok($e->getMessage(), "\n") ?: $e::class, 200);
                }
            }
            if ($timed[$sql] === false) {
                continue;
            }
            [$median, $p95] = $timed[$sql];
            $queries[] = [
                'id' => substr(sha1("code:{$c['file']}:{$c['line']}:{$sql}"), 0, 12),
                'origin' => ['kind' => 'code', 'file' => $c['file'], 'line' => $c['line'], 'outside' => $c['outside']],
                'sql' => $sql,
                'medianMs' => $median,
                'p95Ms' => $p95,
                'queriesAt' => null,
                'duplicates' => $c['duplicates'],
                'replayed' => true,
                ...($replay === $c['sql'] ? [] : ['unlocked' => true]),
                'plan' => null,
            ];
        }
        foreach ($queries as &$q) {
            $plan = $plans[$q['sql']] ?? null;
            unset($plan['indexes']);
            $q['plan'] = $plan ?? ['fullScan' => false, 'index' => null, 'rowsRead' => 0, 'sort' => false, 'temp' => false, 'raw' => null];
        }
        unset($q);
        $tableInfo = array_map(fn (string $t): array => $this->tableInfo($connection, $t), array_keys($filled));

        // Shown as is in the studio's «Rendimiento» tab: in Spanish, like the rest of its copy.
        $notes[] = $captured === null
            ? 'El código escrito a mano fuera de las pantallas (servicios, jobs) no se midió: no corrieron los tests del módulo.'
            : 'Las consultas del código se repitieron sobre el volumen con los valores de sus tests: sus tiempos son «sobre datos de test».';
        foreach ($seed->skipped() as $table => $why) {
            $notes[] = "La tabla {$table} no se pudo llenar: {$why}";
        }
        foreach ($seed->short() as $table => $s) {
            $notes[] = "La tabla {$table} quedó con {$s['written']} de {$s['wanted']} filas: {$s['reason']}";
        }
        foreach ($screens->skipped() as $why) {
            $notes[] = "Pantalla sin medir: {$why}";
        }
        if ($list === [] && $screens->skipped() === []) {
            $notes[] = array_filter($intro['crud'], static fn (array $c): bool => $c['module'] === $module) === []
                ? "No se midió ninguna pantalla: ningún registro de {$module} tiene un controlador CRUD del paquete (listado o detalle). Sus consultas son las del código escrito a mano, que miden los tests del módulo."
                : "No se midió ninguna pantalla: los controladores CRUD de {$module} no tienen una ruta GET de listado (index) ni de detalle (show).";
        }
        foreach ($queries as $q) {
            if ($q['origin']['kind'] !== 'screen') {
                continue;
            }
            if ($q['status'] >= 300) {
                $notes[] = "{$q['origin']['record']}: GET /{$q['uri']} respondió {$q['status']}: su tiempo no es el de la pantalla.";
            }
            if ($q['origin']['screen'] === 'count' && $q['sql'] === '') {
                $notes[] = "{$q['origin']['record']}: el listado no hizo un count(*): su total no se midió.";
            }
        }

        return [
            'engine' => $engine,
            'engineVersion' => $version,
            'tables' => $tableInfo,
            'queries' => $queries,
            'unusedIndexes' => $this->unused($tableInfo, array_keys($tables), $plans),
            'notes' => $notes,
        ];
    }

    /**
     * Fresh statistics for the planner: rows inserted in bulk a moment ago are not in them yet, and a plan read on
     * stale statistics is not the plan the same data gets in production.
     *
     * @param  list<string>  $tables
     */
    private function analyze(string $connection, array $tables): void
    {
        $db = DB::connection($connection);
        foreach ($tables as $table) {
            $db->getDriverName() === 'pgsql'
                ? $db->statement('analyze '.$db->getQueryGrammar()->wrapTable($table))
                : $db->select('analyze table '.$db->getQueryGrammar()->wrapTable($table));
        }
    }

    /**
     * The module's own indexes that no plan of the run used. A primary key or a unique index is never listed: it
     * enforces a rule whether a query reads it or not.
     *
     * @param  list<array{name: string, indexes: list<array<string, mixed>>}>  $tableInfo
     * @param  list<string>  $moduleTables
     * @param  array<string, array{indexes: list<string>}>  $plans
     * @return list<array{table: string, index: string}>
     */
    private function unused(array $tableInfo, array $moduleTables, array $plans): array
    {
        // MariaDB's plans say `PRIMARY` where its schema says `primary`.
        $used = array_flip(array_map('strtolower', array_merge([], ...array_column($plans, 'indexes'))));
        $out = [];
        foreach ($tableInfo as $t) {
            if (! in_array($t['name'], $moduleTables, true)) {
                continue;
            }
            foreach ($t['indexes'] as $i) {
                if (! $i['unique'] && ! isset($used[strtolower("{$t['name']}.{$i['name']}")])) {
                    $out[] = ['table' => $t['name'], 'index' => $i['name']];
                }
            }
        }

        return $out;
    }

    /** @return array{0: 'pgsql'|'mariadb', 1: string} refuses MySQL and the versions the design does not support */
    private function engine(string $connection): array
    {
        $version = (string) DB::connection($connection)->scalar('select version()');
        if (DB::connection($connection)->getDriverName() === 'pgsql') {
            preg_match('/PostgreSQL (\d+)(\.\d+)?/', $version, $m);
            if ((int) ($m[1] ?? 0) < 14) {
                throw new RuntimeException("PostgreSQL 14 or newer is required ({$version}).");
            }

            return ['pgsql', ($m[1] ?? '').($m[2] ?? '')];
        }
        if (! str_contains($version, 'MariaDB') || version_compare($version, '10.6', '<')) {
            throw new RuntimeException("MariaDB 10.6 or newer is required ({$version}).");
        }

        return ['mariadb', explode('-', $version)[0]];
    }

    /** @return array{name: string, rows: int, indexes: list<array<string, mixed>>} */
    private function tableInfo(string $connection, string $table): array
    {
        $partial = DB::connection($connection)->getDriverName() === 'pgsql'
            ? array_column(DB::connection($connection)->select("select indexname, substring(indexdef from ' WHERE (.*)$') as predicate from pg_indexes where tablename = ? and indexdef like '% WHERE %'", [$table]), 'predicate', 'indexname')
            : [];

        return [
            'name' => $table,
            'rows' => (int) DB::connection($connection)->table($table)->count(),
            'indexes' => array_map(static fn (array $i): array => [
                'name' => $i['name'],
                'columns' => $i['columns'],
                'unique' => (bool) $i['unique'],
                ...(isset($partial[$i['name']]) ? ['partial' => $partial[$i['name']]] : []),
            ], Schema::connection($connection)->getIndexes($table)),
        ];
    }

    /**
     * What ran the measure, for the reports MK Studio sends to MK: `director` is this package's version as Composer
     * installed it (`dev-<branch>` from a path repository), `dev` when Composer does not know it.
     *
     * @return array{php: string, laravel: string, director: string}
     */
    public static function versions(): array
    {
        try {
            $director = InstalledVersions::getPrettyVersion('makroz/director-laravel');
        } catch (OutOfBoundsException) {
            $director = null;
        }

        return ['php' => PHP_VERSION, 'laravel' => app()->version(), 'director' => $director ?? 'dev'];
    }

    private function git(string $args): string
    {
        $out = [];
        @exec('git -C '.escapeshellarg(base_path()).' '.$args.' 2>/dev/null', $out);

        return trim($out[0] ?? '');
    }

    /** The reason on stderr (stdout carries only the JSON) and a failing exit code. */
    private function refuse(string $message): int
    {
        $out = $this->output->getOutput();
        ($out instanceof ConsoleOutputInterface ? $out->getErrorOutput() : $out)->writeln("mk-studio:perf: {$message}");

        return self::FAILURE;
    }
}
