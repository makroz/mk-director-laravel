<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Perf\PerfAdmin;
use Mk\Director\Perf\QueryRecorder;
use Mk\Director\Perf\ScratchDatabase;
use Mk\Director\Perf\ScreenRunner;
use Mk\Director\Perf\VolumeSeeder;
use Mk\Director\Spec\Introspector;
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
 *   3. fill the module's relation graph at the volume asked ({@see VolumeSeeder});
 *   4. create an admin with `'*'` and its token ({@see PerfAdmin});
 *   5. request each list screen through the HTTP kernel, a warm-up and `--runs` times ({@see ScreenRunner});
 *   6. drop the scratch database (kept with `--keep`) and print the run.
 *
 * The app's database is never written. This version measures the list screens only: plans, the per_page
 * slope, unused indexes and hand-written code are not measured yet, and their fields say so (`notes`).
 */
final class PerfCommand extends Command
{
    protected $signature = 'mk-studio:perf
        {--module= : The module to measure (as its folder: School)}
        {--volume=medium : small, medium or large (rows of the module\'s leaf tables: 1,000, 20,000 or 200,000)}
        {--runs=50 : Measured requests per screen, after one warm-up}
        {--keep : Keep the scratch database after the run}
        {--pretty : Indented JSON}';

    protected $description = 'Measures a module\'s screens on a scratch copy of the schema and prints the run as JSON for MK Studio.';

    public function handle(ScratchDatabase $db, VolumeSeeder $seed, ScreenRunner $screens, QueryRecorder $rec): int
    {
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

        $started = hrtime(true);
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');
        try {
            // Before the scratch database: the pairs' targets are read from the app's rows.
            $intro = (new Introspector([config('mk_director.paths.modules', app_path('Modules'))]))->collect();
            $connection = $db->create();
            try {
                $db->migrate($connection);
                $run = $this->measure($connection, $module, $volume, $runs, $intro, $seed, $screens, $rec);
            } finally {
                $this->option('keep') ? $db->release($connection) : $db->drop($connection);
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
        ];
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
        $this->output->writeln(json_encode($run, $flags | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0)), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> the run's measured part */
    private function measure(string $connection, string $module, string $volume, int $runs, array $intro, VolumeSeeder $seed, ScreenRunner $screens, QueryRecorder $rec): array
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
        $token = (new PerfAdmin)->make($seed, $connection);
        $queries = [];
        foreach ($screens->screens($module, $token, $intro) as $screen) {
            $m = $screens->measure($screen, $runs, $rec);
            $queries[] = [
                'id' => substr(sha1("screen:{$screen['record']}:{$screen['screen']}:{$m['sql']}"), 0, 12),
                'origin' => ['kind' => 'screen', 'record' => $screen['record'], 'screen' => $screen['screen']],
                'uri' => $screen['uri'],
                'status' => $m['status'],
                'sql' => $m['sql'],
                'medianMs' => $m['medianMs'],
                'p95Ms' => $m['p95Ms'],
                'queryCount' => $m['queryCount'],
                'queriesAt' => null,
                'duplicates' => $m['duplicates'],
                'plan' => ['fullScan' => false, 'index' => null, 'rowsRead' => 0, 'sort' => false, 'temp' => false, 'raw' => null],
            ];
        }

        // Shown as is in the studio's «Rendimiento» tab: in Spanish, like the rest of its copy.
        $notes = ['Esta versión mide sólo los listados: el plan de cada consulta, el N+1 (queriesAt) y los índices sin uso todavía no se miden.'];
        foreach ($seed->skipped() as $table => $why) {
            $notes[] = "La tabla {$table} no se pudo llenar: {$why}";
        }
        foreach ($seed->short() as $table => $s) {
            $notes[] = "La tabla {$table} quedó con {$s['written']} de {$s['wanted']} filas: {$s['reason']}";
        }
        foreach ($screens->skipped() as $why) {
            $notes[] = "Pantalla sin medir: {$why}";
        }
        foreach ($queries as $q) {
            if ($q['status'] >= 300) {
                $notes[] = "{$q['origin']['record']}: GET /{$q['uri']} respondió {$q['status']}: su tiempo no es el de un listado.";
            }
        }

        return [
            'engine' => $engine,
            'engineVersion' => $version,
            'tables' => array_map(fn (string $t): array => $this->tableInfo($connection, $t), array_keys($filled)),
            'queries' => $queries,
            'unusedIndexes' => [],
            'notes' => $notes,
        ];
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
