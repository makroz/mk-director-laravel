<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The throwaway database `mk-studio:perf` measures on: `<db>_mk_studio_perf`, on the app's own server and engine,
 * cloned from the default connection. The app's own database is never written:
 *
 *  - a name that does not end in `_mk_studio_perf` (or `_mk_studio_perf_tests`) is refused before anything runs;
 *  - `production` is refused;
 *  - from `create()` until `drop()`/`release()` the default connection is the scratch one AND the app's own
 *    connection name points at the scratch database, so code that names its connection lands there too;
 *  - `drop()` checks the name again, so it can never drop the app's database.
 *
 * CREATE/DROP DATABASE run on a separate maintenance connection: PostgreSQL's `postgres` database (a database
 * cannot be dropped while connected to it), no database on MariaDB.
 */
final class ScratchDatabase
{
    public const SUFFIX = 'mk_studio_perf';

    /** What a scratch database is called. Anything else is refused, on create and on drop. */
    public const NAME = '/^[A-Za-z0-9_]+_mk_studio_perf(_tests)?$/';

    private const MAINTENANCE = 'mk_studio_perf_maintenance';

    /** @var array{name: string, config: array<string, mixed>}|null the app's default connection, while in use */
    private ?array $app = null;

    /**
     * Creates `<db>_<suffix>` (dropping a stale one first), registers the connection `<suffix>` for it and points the
     * app at it. Returns the connection name.
     */
    public function create(string $suffix = self::SUFFIX): string
    {
        if (app()->environment('production')) {
            throw new RuntimeException('mk-studio:perf refuses to run with APP_ENV=production.');
        }
        if ($this->app !== null) {
            throw new RuntimeException('a scratch database is already in use: drop or release it first.');
        }
        $name = (string) config('database.default');
        $config = (array) config("database.connections.{$name}");
        $driver = $config['driver'] ?? null;
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("mk-studio:perf needs PostgreSQL or MariaDB; the default connection is {$driver}.");
        }
        $database = $config['database'].'_'.$suffix;
        if (! preg_match(self::NAME, $database)) {
            throw new RuntimeException("refused: the scratch database name {$database} does not end in _mk_studio_perf.");
        }

        $this->maintenance($config, function (Connection $db) use ($driver, $database): void {
            $db->statement($this->dropSql($driver, $database));
            $db->statement('create database '.$this->quote($driver, $database));
        });

        config(["database.connections.{$suffix}" => [...$config, 'url' => null, 'database' => $database]]);
        $this->app = ['name' => $name, 'config' => $config];
        // The app's own connection name points at the scratch too: a model or a migration with `$connection = '<app>'` would
        // otherwise read and write the real database behind the default's back.
        config(["database.connections.{$name}" => [...$config, 'url' => null, 'database' => $database]]);
        DB::purge($name);
        DB::setDefaultConnection($suffix);

        return $suffix;
    }

    /**
     * `migrate --database=<connection> --force`: the app's whole schema, data migrations included. Not `migrate:fresh`:
     * `create()` just made the database empty, so it would add nothing, and an app that calls
     * `DB::prohibitDestructiveCommands()` (RETO does, outside its test database) refuses it for every connection.
     */
    public function migrate(string $connection): void
    {
        $this->assertScratch($connection);
        $code = Artisan::call('migrate', ['--database' => $connection, '--force' => true]);
        if ($code !== 0) {
            throw new RuntimeException("migrate failed on the scratch database:\n".trim(Artisan::output()));
        }
    }

    /** Points the app back at its own database and drops the scratch one. */
    public function drop(string $connection): void
    {
        $database = $this->assertScratch($connection);
        $config = $this->app['config'] ?? (array) config("database.connections.{$connection}");
        $this->release($connection);
        $driver = (string) $config['driver'];
        $this->maintenance($config, fn (Connection $db) => $db->statement($this->dropSql($driver, $database)));
    }

    /** Points the app back at its own database and keeps the scratch one (`--keep`). */
    public function release(string $connection): void
    {
        DB::purge($connection);
        if ($this->app === null) {
            return;
        }
        config(["database.connections.{$this->app['name']}" => $this->app['config']]);
        DB::purge($this->app['name']);
        DB::setDefaultConnection($this->app['name']);
        $this->app = null;
    }

    /** The scratch database's name, or a refusal when the connection does not name one. */
    private function assertScratch(string $connection): string
    {
        $database = config("database.connections.{$connection}.database");
        if (! is_string($database) || ! preg_match(self::NAME, $database)) {
            throw new RuntimeException("refused: the connection {$connection} is not a scratch database.");
        }

        return $database;
    }

    /** @param  array<string, mixed>  $config */
    private function maintenance(array $config, callable $run): void
    {
        $database = $config['driver'] === 'pgsql' ? 'postgres' : null;
        config(['database.connections.'.self::MAINTENANCE => [...$config, 'url' => null, 'database' => $database]]);
        try {
            $run(DB::connection(self::MAINTENANCE));
        } finally {
            DB::purge(self::MAINTENANCE);
        }
    }

    private function dropSql(string $driver, string $database): string
    {
        // `with (force)` (PostgreSQL 13+) ends the sessions still open on it: a drop must not depend on a leak elsewhere.
        return 'drop database if exists '.$this->quote($driver, $database).($driver === 'pgsql' ? ' with (force)' : '');
    }

    /** The name already matched NAME (letters, digits, `_`): quoting is for case, not for injection. */
    private function quote(string $driver, string $database): string
    {
        return $driver === 'pgsql' ? "\"{$database}\"" : "`{$database}`";
    }
}
