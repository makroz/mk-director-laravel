<?php

declare(strict_types=1);

use App\Modules\PerfShop\Http\Controllers\ItemController;
use Illuminate\Console\Application;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Providers\ConsoleSupportServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mk\Director\Auth\Models\AuthUser;
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
    $code = Artisan::call('mk-studio:perf', ['--module' => 'PerfShop', '--runs' => 3, ...$options], $out = new BufferedOutput);

    return [$code, $out->fetch()];
}

beforeEach(function () {
    if (perfServer() === null) {
        $this->markTestSkipped('needs a server: MK_PERF_TEST_DRIVER=pgsql|mariadb, MK_PERF_TEST_HOST, _PORT, _USERNAME, _PASSWORD.');
    }
    foreach (['', '_mk_studio_perf', '_perf'] as $suffix) {
        perfDropTestDatabase(PERF_APP_DB.$suffix);
    }
    perfPdo()->exec('create database '.PERF_APP_DB);

    $root = __DIR__.'/../../Fixtures/Perf';
    spl_autoload_register(static function (string $class) use ($root): void {
        $file = $root.'/Modules/'.str_replace('\\', '/', substr($class, strlen('App\\Modules\\'))).'.php';
        if (str_starts_with($class, 'App\\Modules\\PerfShop\\') && is_file($file)) {
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
    $this->tearDownHttpApp();
    Application::forgetBootstrappers();
    foreach (['', '_mk_studio_perf', '_perf'] as $suffix) {
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
    // The run happened on a database with the volume in it, and it is gone.
    expect($run['schema'])->toBe('mk-perf/v1')
        ->and($run['tables'])->toBe([[
            'name' => 'perf_items',
            'rows' => 1000,
            'indexes' => $run['tables'][0]['indexes'],
        ]])
        ->and($run['queries'])->toHaveCount(1)
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
        ->and(json_decode($output, true)['tables'][0]['rows'])->toBe(1000);
});
