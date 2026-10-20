<?php

declare(strict_types=1);

use App\Modules\PerfGraph\Services\OwnerCount;
use App\Modules\PerfScreens\Http\Controllers\ProductController;
use App\Modules\PerfShop\Database\Factories\ItemFactory;
use App\Modules\PerfShop\Http\Controllers\ItemController;
use App\Modules\PerfShop\Models\Item;
use App\Modules\PerfShop\Services\ItemReport;
use App\Modules\PerfSlope\Http\Controllers\BatchedController;
use App\Modules\PerfSlope\Http\Controllers\PerRowController;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Providers\ConsoleSupportServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\MkServiceProvider;
use Mk\Director\Perf\CaptureListener;
use Mk\Director\Perf\Plans\MariaDbPlanReader;
use Mk\Director\Perf\Plans\PlanReader;
use Mk\Director\Perf\Plans\PostgresPlanReader;
use Mk\Director\Perf\QueryRecorder;
use Mk\Director\Perf\ScratchDatabase;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk-studio:perf` on a REAL server (PostgreSQL or MariaDB): what it creates and drops is a database on that
 * server, so every guard is asserted there, through a PDO of its own, never through the code under test or its
 * exit code alone. The "app" is `mk_director_perf_app`, a database this file creates and drops.
 *
 * Skipped unless `MK_PERF_TEST_DRIVER` (pgsql|mariadb) and `MK_PERF_TEST_HOST`, `_PORT`, `_USERNAME`,
 * `_PASSWORD` name a server whose user may CREATE DATABASE.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class PerfTestAdmin extends AuthUser
{
    protected $table = 'perf_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }

    /** The package's pivots use `user_id`. */
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/** `perf_owners` has no module: a parent the items point at, and a polymorphic target of the notes (PerfGraph). */
final class PerfOwner extends Model
{
    protected $table = 'perf_owners';
}

const PERF_APP_DB = 'mk_director_perf_app';

/** @return array<string, string>|null */
function perfServer(): ?array
{
    $driver = getenv('MK_PERF_TEST_DRIVER');
    if (! in_array($driver, ['pgsql', 'mariadb'], true)) {
        return null;
    }

    return [
        'driver' => $driver,
        'host' => getenv('MK_PERF_TEST_HOST') ?: '127.0.0.1',
        'port' => getenv('MK_PERF_TEST_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
        'username' => (string) getenv('MK_PERF_TEST_USERNAME'),
        'password' => (string) getenv('MK_PERF_TEST_PASSWORD'),
        'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
        'prefix' => '',
    ];
}

/** A PDO of the test's own: the assertions never go through the connection the command rewires. */
function perfPdo(?string $database = null): PDO
{
    $s = perfServer();
    $dsn = $s['driver'] === 'pgsql'
        ? "pgsql:host={$s['host']};port={$s['port']};dbname=".($database ?? 'postgres')
        : "mysql:host={$s['host']};port={$s['port']}".($database ? ";dbname={$database}" : '');

    return new PDO($dsn, $s['username'], $s['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function perfDatabaseExists(string $name): bool
{
    $sql = perfServer()['driver'] === 'pgsql'
        ? 'select 1 from pg_database where datname = ?'
        : 'select 1 from information_schema.schemata where schema_name = ?';
    $q = perfPdo()->prepare($sql);
    $q->execute([$name]);

    return $q->fetchColumn() !== false;
}

/** Drops only this file's databases. */
function perfDropTestDatabase(string $name): void
{
    if (! str_starts_with($name, PERF_APP_DB)) {
        throw new LogicException("not a test database: {$name}");
    }
    perfPdo()->exec(perfServer()['driver'] === 'pgsql' ? "drop database if exists \"{$name}\" with (force)" : "drop database if exists `{$name}`");
}

/** @return array<string, int> every table of the app database => its rows */
function perfRowCounts(): array
{
    $pdo = perfPdo(PERF_APP_DB);
    $tables = perfServer()['driver'] === 'pgsql'
        ? $pdo->query("select tablename from pg_tables where schemaname = 'public' order by 1")->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query('select table_name from information_schema.tables where table_schema = database() order by 1')->fetchAll(PDO::FETCH_COLUMN);
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = (int) $pdo->query("select count(*) from {$t}")->fetchColumn();
    }

    return $out;
}

/** @return array{0: int, 1: string} exit code and output of `mk-studio:perf` */
function perfRun(array $options = []): array
{
    // Its own buffer: the nested `migrate:fresh` replaces what `Artisan::output()` returns.
    $code = Artisan::call('mk-studio:perf', ['--module' => 'PerfShop', '--runs' => 3, '--volume' => 'small', ...$options], $out = new BufferedOutput);

    return [$code, $out->fetch()];
}

beforeEach(function () {
    if (perfServer() === null) {
        $this->markTestSkipped('needs a server: MK_PERF_TEST_DRIVER=pgsql|mariadb, MK_PERF_TEST_HOST, _PORT, _USERNAME, _PASSWORD.');
    }
    foreach (['', '_mk_studio_perf', '_mk_studio_perf_tests', '_perf'] as $suffix) {
        perfDropTestDatabase(PERF_APP_DB.$suffix);
    }
    perfPdo()->exec('create database '.PERF_APP_DB);

    $root = __DIR__.'/../../Fixtures/Perf';
    spl_autoload_register(static function (string $class) use ($root): void {
        $file = $root.'/Modules/'.str_replace('\\', '/', substr($class, strlen('App\\Modules\\'))).'.php';
        if (str_starts_with($class, 'App\\Modules\\') && is_file($file)) {
            require_once $file;
        }
    });
    $app = $this->bootHttpApp(PerfTestAdmin::class, [
        'tenant' => ['enabled' => false],
        'paths' => ['modules' => $root.'/Modules'],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
    ]);
    config(['database.connections.app' => [...perfServer(), 'database' => PERF_APP_DB], 'database.default' => 'app']);

    // `migrate:fresh` is an Artisan command: a console kernel and the migrator, without the bootstrappers (no .env here).
    $app->register(ConsoleSupportServiceProvider::class);
    $app->singleton(ConsoleKernelContract::class, fn ($app) => new class($app, $app['events']) extends ConsoleKernel
    {
        protected $bootstrappers = [];
    });
    $app['migrator']->path($root.'/migrations');
    Artisan::call('migrate', ['--database' => 'app', '--force' => true]);
    foreach (range(1, 3) as $i) {
        $owner = DB::table('perf_owners')->insertGetId(['name' => "owner {$i}"]);
        DB::table('perf_items')->insert(['owner_id' => $owner, 'name' => "item {$i}", 'status' => 1]);
    }

    Route::middleware(['api', 'mk.auth:admin', 'mk.ability:admin.perf_shop.viewAny'])
        ->get('api/admin/perf-shop/items', [ItemController::class, 'index']);
});

afterEach(function () {
    if (perfServer() === null) {
        return;
    }
    Relation::morphMap([], false);
    $this->tearDownHttpApp();
    Application::forgetBootstrappers();
    foreach (['', '_mk_studio_perf', '_mk_studio_perf_tests', '_perf'] as $suffix) {
        perfDropTestDatabase(PERF_APP_DB.$suffix);
    }
});

/** The refusal's message, or null when `$create` went through. The database is asserted before the message. */
function perfRefusal(callable $create): ?string
{
    try {
        $create();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return null;
}

test('scratch_db_refuses_a_name_without_the_suffix', function () {
    $refusal = perfRefusal(fn () => (new ScratchDatabase)->create('perf'));

    expect(perfDatabaseExists(PERF_APP_DB.'_perf'))->toBeFalse()
        ->and(config('database.default'))->toBe('app')
        ->and($refusal)->toContain('does not end in _mk_studio_perf');
});

test('scratch_db_refuses_production', function () {
    $this->httpApp['env'] = 'production';

    $refusal = perfRefusal(fn () => (new ScratchDatabase)->create());
    expect(perfDatabaseExists(PERF_APP_DB.'_mk_studio_perf'))->toBeFalse()
        ->and($refusal)->toContain('production');

    [$code, $output] = perfRun();
    expect($code)->toBe(1)
        ->and($output)->toContain('production');
});

test('scratch_db_is_dropped_after_the_run', function () {
    [$code, $output] = perfRun();
    expect($code)->toBe(0, $output);

    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    // The run happened on a database with the volume in it (the items and the owners they point at), and it is gone.
    expect($run['schema'])->toBe('mk-perf/v1')
        ->and(array_column($run['tables'], 'rows', 'name'))->toBe(['perf_owners' => 100, 'perf_items' => 1000])
        ->and(array_column(array_column($run['queries'], 'origin'), 'screen'))->toBe(['list', 'count'])
        ->and($run['queries'][0]['status'])->toBe(200)
        ->and($run['queries'][0]['queryCount'])->toBeGreaterThan(0)
        ->and(perfDatabaseExists(PERF_APP_DB.'_mk_studio_perf'))->toBeFalse()
        ->and(config('database.default'))->toBe('app')
        ->and(config('database.connections.app.database'))->toBe(PERF_APP_DB);
});

test('kept_with_keep', function () {
    [$code, $output] = perfRun(['--keep' => true]);
    expect($code)->toBe(0, $output)
        ->and(perfDatabaseExists(PERF_APP_DB.'_mk_studio_perf'))->toBeTrue()
        ->and(config('database.connections.app.database'))->toBe(PERF_APP_DB);
});

test('the_run_never_touches_the_app_database', function () {
    $before = perfRowCounts();
    expect($before['perf_items'])->toBe(3);

    [$code, $output] = perfRun();

    // The effect on the database first: a run that wrote the app's tables and then failed must be red HERE.
    expect(perfRowCounts())->toBe($before);
    // 1000 items, an admin, its ability and its token were written: all of it on the scratch database.
    expect($code)->toBe(0, $output)
        ->and(array_column(json_decode($output, true)['tables'], 'rows', 'name')['perf_items'])->toBe(1000);
});

/** @return list<array<string, mixed>> rows of a query on the kept scratch database */
function perfScratch(string $sql): array
{
    return perfPdo(PERF_APP_DB.'_mk_studio_perf')->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

test('volume_respects_fk_order_and_fills_morph_columns_with_real_ids', function () {
    Relation::morphMap(['owner' => PerfOwner::class, 'item' => Item::class]);

    [$code, $output] = perfRun(['--module' => 'PerfGraph', '--keep' => true]);
    expect($code)->toBe(0, $output);
    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $rows = array_column($run['tables'], 'rows', 'name');
    $at = array_flip(array_keys($rows));

    // The module's tables, their parents two levels up, and both targets of the pair; parents written first.
    expect($rows)->toBe([
        'perf_regions' => 10, 'perf_teams' => 100, 'perf_owners' => 100, 'perf_items' => 100, 'perf_notes' => 1000, 'perf_tags' => 1000,
    ])
        ->and($at['perf_regions'])->toBeLessThan($at['perf_teams'])
        ->and($at['perf_teams'])->toBeLessThan($at['perf_notes'])
        ->and($at['perf_owners'])->toBeLessThan($at['perf_items'])
        ->and($at['perf_items'])->toBeLessThan($at['perf_notes']);
    // Every pair names a real row of the table its alias names, and both aliases are used.
    $pairs = perfScratch("select n.author_type as type, count(*) as n, sum(case when o.id is null and i.id is null then 1 else 0 end) as orphans
        from perf_notes n
        left join perf_owners o on n.author_type = 'owner' and o.id = n.author_id
        left join perf_items i on n.author_type = 'item' and i.id = n.author_id
        group by n.author_type order by n.author_type");
    expect(array_column($pairs, 'type'))->toBe(['item', 'owner'])
        ->and(array_sum(array_column($pairs, 'orphans')))->toEqual(0);
    // Skewed: the busiest team holds far more notes than an even share (1000 / 100 = 10).
    expect((int) perfScratch('select max(c) as m from (select count(*) as c from perf_notes group by team_id) x')[0]['m'])->toBeGreaterThan(50);
});

test('volume_honors_check_constraints', function () {
    Relation::morphMap(['owner' => PerfOwner::class, 'item' => Item::class]);

    [$code, $output] = perfRun(['--module' => 'PerfGraph', '--keep' => true]);
    expect($code)->toBe(0, $output);

    // The format CHECK on a unique code, and exactly one owner per tag: every row written honors both.
    $tags = perfScratch('select code, item_id, owner_id from perf_tags');
    $codes = array_column($tags, 'code');
    expect($tags)->toHaveCount(1000)
        ->and(array_filter($codes, static fn (string $c): bool => preg_match('/^[A-Z]{3}-[0-9]{2}$/', $c) !== 1))->toBe([])
        ->and(array_unique($codes))->toHaveCount(1000)
        ->and(array_filter($tags, static fn (array $t): bool => ($t['item_id'] === null) === ($t['owner_id'] === null)))->toBe([])
        ->and(array_filter($tags, static fn (array $t): bool => $t['item_id'] !== null))->not->toBe([])
        ->and(array_filter($tags, static fn (array $t): bool => $t['owner_id'] !== null))->not->toBe([])
        ->and(implode("\n", json_decode($output, true)['notes']))->not->toContain('perf_tags');
});

test('screens_cover_list_each_filter_search_sort_count_show', function () {
    Route::middleware(['api', 'mk.auth:admin'])->group(function (): void {
        Route::get('api/admin/perf-screens/products', [ProductController::class, 'index']);
        Route::get('api/admin/perf-screens/products/{product}', [ProductController::class, 'show']);
    });

    [$code, $output] = perfRun(['--module' => 'PerfScreens']);
    expect($code)->toBe(0, $output);
    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $q = array_column($run['queries'], null, 'id');
    $screens = array_map(static fn (array $q): string => $q['origin']['screen'].(isset($q['origin']['param']) ? ':'.$q['origin']['param'] : ''), $run['queries']);
    $search = $run['queries'][3];
    $show = $run['queries'][7];

    // The exact list: two filters, the search, the two filters it can sort by, the paginator's total, the detail.
    expect($screens)->toBe([
        'list', 'filter:status', 'filter:owner_id', "search:{$search['origin']['param']}", 'sort:status', 'sort:owner_id', 'count', "show:{$show['origin']['param']}",
    ])
        ->and($search['origin']['param'])->toStartWith('name')
        ->and($show['uri'])->toBe("api/admin/perf-screens/products/{$show['origin']['param']}")
        ->and(array_unique(array_column($run['queries'], 'status')))->toBe([200])
        ->and($q)->toHaveCount(8);
    // Each screen's main query is the one it names: the count is the paginator's, the detail reads one row by its key.
    expect($run['queries'][6]['sql'])->toMatch('/^select count\\(\\*\\) as .?aggregate.? from .?perf_items/')
        ->and($show['sql'])->toMatch('/where .?perf_items.?\\..?id.? = \\?/')
        ->and($show['queriesAt'])->toBeNull()
        ->and($run['queries'][6]['queriesAt'])->toBeNull()
        ->and($run['queries'][1]['queriesAt'])->toHaveKeys(['perPage1', 'perPage8', 'perPage20']);
    // Every plan was read; the index no screen reads is listed, the primary key never is.
    expect(array_filter(array_column($run['queries'], 'plan'), static fn (array $p): bool => $p['raw'] === null))->toBe([])
        ->and($run['unusedIndexes'])->toContain(['table' => 'perf_items', 'index' => 'perf_items_created_at_updated_at_name_index'])
        ->and(array_column($run['unusedIndexes'], 'index'))->not->toContain('primary', 'PRIMARY', 'perf_items_pkey');
});

test('slope_flags_an_n_plus_one', function () {
    Route::middleware(['api', 'mk.auth:admin'])->group(function (): void {
        Route::get('api/admin/perf-slope/per-row', [PerRowController::class, 'index']);
        Route::get('api/admin/perf-slope/batched', [BatchedController::class, 'index']);
    });

    [$code, $output] = perfRun(['--module' => 'PerfSlope']);
    expect($code)->toBe(0, $output);
    $lists = array_column(array_filter(
        json_decode($output, true, flags: JSON_THROW_ON_ERROR)['queries'],
        static fn (array $q): bool => $q['origin']['screen'] === 'list',
    ), null, 'uri');
    $perRow = $lists['api/admin/perf-slope/per-row'];
    $batched = $lists['api/admin/perf-slope/batched'];

    // One query more per row: 7 more from 1 to 8 rows, 12 more from 8 to 20. Its twin stays flat.
    expect($perRow['queriesAt']['perPage8'] - $perRow['queriesAt']['perPage1'])->toBe(7)
        ->and($perRow['queriesAt']['perPage20'] - $perRow['queriesAt']['perPage8'])->toBe(12)
        ->and($perRow['duplicates'])->toBeGreaterThan(0)
        ->and($batched['queriesAt']['perPage1'])->toBe($batched['queriesAt']['perPage8'])
        ->and($batched['queriesAt']['perPage8'])->toBe($batched['queriesAt']['perPage20'])
        ->and($batched['duplicates'])->toBe(0);
});

/** 5,000 items on the app database, with fresh statistics: a plan on an empty table measures nothing. */
function perfPlanReader(): PlanReader
{
    $owners = DB::table('perf_owners')->pluck('id')->all();
    foreach (array_chunk(range(1, 5000), 500) as $chunk) {
        DB::table('perf_items')->insert(array_map(static fn (int $i): array => [
            'owner_id' => $owners[$i % count($owners)], 'name' => "volume {$i}", 'status' => $i % 3 + 1,
        ], $chunk));
    }
    if (perfServer()['driver'] === 'pgsql') {
        DB::statement('analyze perf_items');

        return new PostgresPlanReader;
    }
    DB::select('analyze table perf_items');

    return new MariaDbPlanReader;
}

test('plan_reader_marks_a_full_scan', function () {
    $plan = perfPlanReader()->read('app', 'select * from perf_items where status = ? and owner_id > ?', [2, 0]);

    expect($plan['fullScan'])->toBeTrue()
        ->and($plan['rowsRead'])->toBeGreaterThanOrEqual(5000)
        ->and($plan['raw'])->not->toBeNull();
});

test('plan_reader_marks_an_index', function () {
    $plan = perfPlanReader()->read('app', 'select * from perf_items where name = ?', ['volume 77']);

    expect($plan['fullScan'])->toBeFalse()
        ->and($plan['index'])->toBe('perf_items_name_index')
        ->and($plan['indexes'])->toBe(['perf_items.perf_items_name_index'])
        ->and($plan['rowsRead'])->toBeLessThan(10);
});

test('plan_reader_refuses_a_write', function () {
    $reader = perfPlanReader();

    expect(fn () => $reader->read('app', 'delete from perf_items', []))->toThrow(InvalidArgumentException::class)
        ->and(DB::table('perf_items')->count())->toBe(5003);
});

const PERF_TESTS_DB = PERF_APP_DB.'_mk_studio_perf_tests';

/** The line of a fixture file that carries `// capture: <marker>`. */
function perfLine(string $file, string $marker): int
{
    $lines = file(__DIR__.'/../../Fixtures/Perf/Modules/'.$file);
    foreach ($lines as $i => $line) {
        if (str_contains($line, "// capture: {$marker}")) {
            return $i + 1;
        }
    }
    throw new LogicException("no marker {$marker} in {$file}");
}

/**
 * What MK Studio's runner does before `--capture`, in process: `--prepare-tests`, the "tests" on that database with the
 * capture on (the module's code, another module's code, a write and a query of the test itself), and the app pointed
 * back at its own database. Returns the capture file. `$tests` replaces those "tests".
 */
function perfCapture(?callable $tests = null): string
{
    [$code, $output] = perfRun(['--prepare-tests' => true]);
    expect($code)->toBe(0, $output)
        ->and(json_decode($output, true))->toBe(['database' => PERF_TESTS_DB]);
    config(['database.connections.app.database' => PERF_TESTS_DB]);
    DB::purge('app');
    Artisan::call('migrate', ['--database' => 'app', '--force' => true]);
    foreach (range(1, 3) as $i) {
        $owner = DB::table('perf_owners')->insertGetId(['name' => "owner {$i}"]);
        DB::table('perf_items')->insert(['owner_id' => $owner, 'name' => "item {$i}", 'status' => 1]);
    }

    $file = tempnam(sys_get_temp_dir(), 'mk-capture-');
    unlink($file);
    CaptureListener::register($file, 'PerfShop');
    ($tests ?? static function (): void {
        $report = new ItemReport;
        $report->byStatus(1);
        $report->ownerNames();
        $report->rename(1, 'renamed');
        $report->total();
        (new OwnerCount)->total();
        DB::table('perf_items')->where('name', 'item 2')->first();
    })();

    Event::forget(QueryExecuted::class);
    config(['database.connections.app.database' => PERF_APP_DB]);
    DB::purge('app');

    return $file;
}

/** @return list<array<string, mixed>> */
function perfCaptured(string $file): array
{
    return array_map(static fn (string $l): array => json_decode($l, true, flags: JSON_THROW_ON_ERROR), file($file, FILE_IGNORE_NEW_LINES));
}

test('capture_records_the_module_frame_and_marks_outside_code', function () {
    $lines = perfCaptured(perfCapture());
    $at = static fn (array $l): string => basename($l['file']).':'.$l['line'];
    $report = 'ItemReport.php:';

    // Every SELECT some app code ran, at the line that ran it; not the write, not the test's own query.
    expect(array_map($at, $lines))->toBe([
        $report.perfLine('PerfShop/Services/ItemReport.php', 'byStatus'),
        $report.(perfLine('PerfShop/Services/ItemReport.php', 'perOwner') - 1),
        ...array_fill(0, 3, $report.perfLine('PerfShop/Services/ItemReport.php', 'perOwner')),
        $report.perfLine('PerfShop/Services/ItemReport.php', 'total'),
        'OwnerCount.php:'.perfLine('PerfGraph/Services/OwnerCount.php', 'outside'),
    ]);
    expect($lines[0]['file'])->toEndWith('Fixtures/Perf/Modules/PerfShop/Services/ItemReport.php')
        ->and($lines[0]['bindings'])->toBe([1])
        ->and($lines[0]['sql'])->toContain('perf_items')
        ->and(array_unique(array_column($lines, 'database')))->toBe([PERF_TESTS_DB])
        ->and(array_unique(array_column($lines, 'connection')))->toBe(['app'])
        ->and(array_column($lines, 'outside'))->toBe([false, false, false, false, false, false, true])
        ->and(implode("\n", array_column($lines, 'sql')))->not->toContain('update');
    // One group: one test, no request in between.
    expect(array_unique(array_column($lines, 'group')))->toHaveCount(1);
});

test('capture_skips_setup_frames_catalog_queries_and_writes', function () {
    $cte = 'with x as (select id from perf_items) update perf_items set status = 2 where id in (select id from x)';
    $lines = perfCaptured(perfCapture(static function () use ($cte): void {
        $migration = require __DIR__.'/../../Fixtures/Perf/Modules/PerfShop/Database/Migrations/2026_10_20_000001_backfill_items.php';
        $migration->up();
        $migration->viaService();
        (new ItemFactory)->definition();
        (new ItemReport)->columns();
        (new ItemReport)->announce($cte);
        (new ItemReport)->announce('select id from perf_items where id = 1 for update');
        (new ItemReport)->announce('select pg_advisory_xact_lock(hashtext(?))');
        (new ItemReport)->announce('select GET_LOCK(?, 5)');
    }));

    // The migration's and the factory's own reads are setup; the service a migration calls is the module's code. The
    // catalog lookup and the data-modifying CTE are not written; a locking read is.
    expect(array_map(static fn (array $l): string => basename($l['file']).':'.$l['line'], $lines))->toBe([
        'ItemReport.php:'.perfLine('PerfShop/Services/ItemReport.php', 'byStatus'),
        'ItemReport.php:'.perfLine('PerfShop/Services/ItemReport.php', 'announce'),
    ])
        ->and($lines[1]['sql'])->toEndWith('for update')
        ->and(QueryRecorder::isSelect($cte))->toBeFalse()
        ->and(QueryRecorder::isSelect('select "update", \'delete\' from perf_items'))->toBeTrue()
        ->and(QueryRecorder::isEngineQuery('select * from information_schema.columns where table_name = ?'))->toBeTrue()
        ->and(QueryRecorder::isEngineQuery('select pg_try_advisory_lock(?)'))->toBeTrue()
        ->and(QueryRecorder::isEngineQuery('select release_lock(?)'))->toBeTrue()
        ->and(QueryRecorder::isEngineQuery('select * from perf_items'))->toBeFalse()
        ->and(QueryRecorder::withoutLock('select * from "t" where "id" = ? for no key update of "t" skip locked'))->toBe('select * from "t" where "id" = ?')
        ->and(QueryRecorder::withoutLock('select * from `t` lock in share mode'))->toBe('select * from `t`')
        ->and(QueryRecorder::withoutLock('select * from t'))->toBe('select * from t');
});

test('replay_strips_the_lock_and_binds_booleans_as_booleans', function () {
    $file = perfCapture(static function (): void {
        (new ItemReport)->active();
        (new ItemReport)->lockOne();
    });
    $lines = perfCaptured($file);
    expect($lines[0]['bindings'])->toBe([true]);

    [$code, $output] = perfRun(['--capture' => $file]);

    expect($code)->toBe(0, $output);
    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $byLine = [];
    foreach ($run['queries'] as $q) {
        if ($q['origin']['kind'] === 'code') {
            $byLine[$q['origin']['line']] = $q;
        }
    }
    $active = $byLine[perfLine('PerfShop/Services/ItemReport.php', 'active')];
    $lock = $byLine[perfLine('PerfShop/Services/ItemReport.php', 'lock')];

    // Both replayed and planned on the volume: the bool bound as one, the lock left out and said so.
    expect(implode("\n", $run['notes']))->not->toContain('no se pudo repetir')
        ->and($active['plan']['raw'])->not->toBeNull()
        ->and($active)->not->toHaveKey('unlocked')
        ->and($lock['sql'])->toEndWith('for update')
        ->and($lock['unlocked'])->toBeTrue()
        ->and($lock['plan']['raw'])->not->toBeNull();
});

test('capture_is_off_without_the_env_var', function () {
    // beforeEach booted the app without MK_PERF_CAPTURE: nothing listens to the queries.
    expect($this->httpApp['events']->hasListeners(QueryExecuted::class))->toBeFalse();

    // With it, the provider starts the capture, whose guard refuses this database.
    putenv(CaptureListener::FILE_ENV.'='.sys_get_temp_dir().'/mk-capture-on');
    putenv(CaptureListener::MODULE_ENV.'=PerfShop');
    try {
        expect(fn () => (new MkServiceProvider($this->httpApp))->boot())->toThrow(RuntimeException::class, 'capture refused');
    } finally {
        putenv(CaptureListener::FILE_ENV);
        putenv(CaptureListener::MODULE_ENV);
    }
});

test('capture_refuses_a_database_that_is_not_the_perf_tests_one', function () {
    $file = sys_get_temp_dir().'/mk-capture-refused-'.uniqid();

    // The app's own database: the tests must never run there with the capture on.
    expect(fn () => CaptureListener::register($file, 'PerfShop'))->toThrow(RuntimeException::class, PERF_APP_DB.', not on a *_mk_studio_perf_tests');
    (new ItemReport)->byStatus(1);
    expect(file_exists($file))->toBeFalse()
        ->and($this->httpApp['events']->hasListeners(QueryExecuted::class))->toBeFalse();
});

test('replay_reports_code_queries_with_plan_and_origin', function () {
    $file = perfCapture();
    $before = perfRowCounts();

    [$code, $output] = perfRun(['--capture' => $file]);

    expect(perfRowCounts())->toBe($before);
    expect($code)->toBe(0, $output);
    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $code = array_column(array_filter($run['queries'], static fn (array $q): bool => $q['origin']['kind'] === 'code'), null, 'sql');
    $count = array_values(array_filter($run['queries'], static fn (array $q): bool => $q['origin']['kind'] === 'screen' && $q['origin']['screen'] === 'count'))[0];
    $byStatus = array_values(array_filter($code, static fn (array $q): bool => $q['origin']['line'] === perfLine('PerfShop/Services/ItemReport.php', 'byStatus')))[0];
    $perOwner = array_values(array_filter($code, static fn (array $q): bool => $q['origin']['line'] === perfLine('PerfShop/Services/ItemReport.php', 'perOwner')))[0];
    $outside = array_values(array_filter($code, static fn (array $q): bool => $q['origin']['outside']));

    // The module's full scan, timed and planned on the 1,000 items of the volume, with the tests' bindings.
    expect($byStatus['origin']['file'])->toEndWith('PerfShop/Services/ItemReport.php')
        ->and($byStatus['origin']['outside'])->toBeFalse()
        ->and($byStatus['replayed'])->toBeTrue()
        ->and($byStatus['plan']['fullScan'])->toBeTrue()
        ->and($byStatus['plan']['rowsRead'])->toBeGreaterThanOrEqual(1000)
        ->and($byStatus['plan']['raw'])->not->toBeNull()
        ->and($byStatus['medianMs'])->toBeGreaterThan(0)
        ->and($byStatus['queriesAt'])->toBeNull();
    // A query per row: three times from one line in one test.
    expect($perOwner['duplicates'])->toBe(2)
        ->and($byStatus['duplicates'])->toBe(0);
    // Another module's code: there, marked outside.
    expect($outside)->toHaveCount(1)
        ->and($outside[0]['origin']['file'])->toEndWith('PerfGraph/Services/OwnerCount.php');
    // The count the screen already measured is not measured again as code.
    expect($code)->toHaveCount(4)
        ->and(array_keys($code))->not->toContain($count['sql'])
        ->and(implode("\n", $run['notes']))->toContain('sobre datos de test')
        ->and(perfDatabaseExists(PERF_TESTS_DB))->toBeFalse();
});

test('a_shape_that_fails_on_the_volume_db_is_a_note_not_an_abort', function () {
    $file = tempnam(sys_get_temp_dir(), 'mk-capture-');
    $line = static fn (string $sql, int $at): string => json_encode([
        'database' => PERF_TESTS_DB, 'connection' => 'app', 'sql' => $sql, 'bindings' => [2],
        'file' => 'app/Modules/PerfShop/Services/Legacy.php', 'line' => $at, 'outside' => false, 'group' => 'g1',
    ])."\n";
    file_put_contents($file, $line('select id from perf_items where legacy_code = ?', 7).$line('select id from perf_items where status = ?', 9));

    [$code, $output] = perfRun(['--capture' => $file]);

    expect($code)->toBe(0, $output);
    $run = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    $code = array_values(array_filter($run['queries'], static fn (array $q): bool => $q['origin']['kind'] === 'code'));
    expect(array_column(array_column($code, 'origin'), 'line'))->toBe([9])
        ->and(implode("\n", $run['notes']))->toContain('app/Modules/PerfShop/Services/Legacy.php:7 no se pudo repetir sobre el volumen');
});
