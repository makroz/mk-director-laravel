<?php

declare(strict_types=1);

/**
 * migrate-1.1-to-1.2.php — UUID primary key migration script.
 *
 * Spec: R3-013 (audit), documented in docs/UPGRADE_1.2.md.
 *
 * Usage:
 *   php bin/migrate-1.1-to-1.2.php --dry-run
 *   php bin/migrate-1.1-to-1.2.php --connection=mysql
 *   php bin/migrate-1.1-to-1.2.php --chunk=500
 *
 * The script is idempotent: re-running on an already-migrated
 * table is a no-op. Run with --dry-run first to preview the
 * statements without committing them.
 *
 * IMPORTANT: The script performs a one-way migration. The original
 * BIGINT id column is destroyed in step 5. Always back up the
 * database before running for real.
 */

// Bootstrap: when invoked from the package root (composer install),
// vendor/autoload.php is two directories up from bin/.
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloader = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloader = $candidate;
        break;
    }
}

if ($autoloader === null) {
    fwrite(STDERR, "ERROR: could not locate vendor/autoload.php. Run 'composer install' first.\n");
    exit(1);
}

require_once $autoloader;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = parse_cli_args($argv);

if (isset($opts['help'])) {
    print_help();
    exit(0);
}

$dryRun = isset($opts['dry-run']);
$connection = $opts['connection'] ?? null;
$chunk = (int) ($opts['chunk'] ?? 1000);

fwrite(STDOUT, "=================================================================\n");
fwrite(STDOUT, " mkroz/director-laravel 1.1.x → 1.2.x — data migration\n");
fwrite(STDOUT, "=================================================================\n");

if ($dryRun) {
    fwrite(STDOUT, " DRY-RUN MODE — no statements will be executed.\n\n");
} else {
    fwrite(STDOUT, " WARNING: this migration is irreversible. Back up first.\n\n");
}

/**
 * For the migration script we need a database connection. The package
 * ships illuminate/database but does not boot a Laravel app, so we
 * use the Capsule manager directly with env-driven configuration.
 *
 * In a real install the script would be invoked from a Laravel app
 * (artisan command would be the right home long-term), and the
 * Capsule setup below would be replaced by app('db'). For the
 * standalone-script use case documented in UPGRADE_1.2.md, we read
 * the connection from environment variables.
 */

$capsule = bootstrap_capsule($connection);

$db = $capsule->getConnection();
$driver = $db->getDriverName();

fwrite(STDOUT, " Driver: {$driver}\n");
fwrite(STDOUT, " Chunk size: {$chunk}\n\n");

$table = 'auth_users';

if (! table_exists($db, $table)) {
    fwrite(STDOUT, "  • {$table} does not exist — nothing to migrate. Done.\n");
    exit(0);
}

$idColumn = column_info($db, $table, 'id');
if ($idColumn === null) {
    fwrite(STDERR, "ERROR: 'id' column missing from {$table}.\n");
    exit(1);
}

$idType = strtolower($idColumn['type'] ?? '');
$isAlreadyUuid = str_contains($idType, 'char') && (int) ($idColumn['length'] ?? 0) === 36;

if ($isAlreadyUuid) {
    fwrite(STDOUT, "  • {$table}.id is already CHAR(36). Migration is a no-op.\n");
    exit(0);
}

if (! str_contains($idType, 'int') && ! str_contains($idType, 'bigint')) {
    fwrite(STDERR, "ERROR: '{$table}.id' has unexpected type '{$idType}'. Refusing to migrate.\n");
    fwrite(STDERR, "       This script handles BIGINT → CHAR(36) only.\n");
    exit(2);
}

fwrite(STDOUT, " Detected {$table}.id type: {$idType} → CHAR(36) (UUID)\n\n");

run_step($db, $dryRun, 1, 'Add temporary id_uuid CHAR(36) column', static function ($db) use ($table, $driver): void {
    if (column_exists($db, $table, 'id_uuid')) {
        fwrite(STDOUT, "    (already exists, skipping)\n");
        return;
    }
    $db->statement("ALTER TABLE `{$table}` ADD COLUMN `id_uuid` CHAR(36) NULL");
});

run_step($db, $dryRun, 2, 'Populate id_uuid with UUIDv4 for every row', static function ($db) use ($table, $driver, $chunk): void {
    $uuidExpr = $driver === 'pgsql' ? 'gen_random_uuid()' : 'UUID()';
    $db->statement("UPDATE `{$table}` SET `id_uuid` = {$uuidExpr} WHERE `id_uuid` IS NULL");
});

run_step($db, $dryRun, 3, 'Drop the old BIGINT id column', static function ($db) use ($table): void {
    $db->statement("ALTER TABLE `{$table}` DROP COLUMN `id`");
});

run_step($db, $dryRun, 4, 'Rename id_uuid to id', static function ($db) use ($table): void {
    $db->statement("ALTER TABLE `{$table}` CHANGE COLUMN `id_uuid` `id` CHAR(36) NOT NULL");
});

run_step($db, $dryRun, 5, 'Add the primary key constraint on the new id', static function ($db) use ($table): void {
    $db->statement("ALTER TABLE `{$table}` ADD PRIMARY KEY (`id`)");
});

run_step($db, $dryRun, 6, 'Optionally add client_id tenant column (if mk_director.tenant.enabled)', static function () {
    // No-op outside a Laravel context. In a real install, this would
    // check config('mk_director.tenant.enabled') and add the column.
    fwrite(STDOUT, "    (skipped — no Laravel container available; run the\n");
    fwrite(STDOUT, "     standard migration `php artisan migrate` after this script)\n");
});

fwrite(STDOUT, "\n");
if ($dryRun) {
    fwrite(STDOUT, " DRY-RUN complete. Re-run without --dry-run to apply.\n");
} else {
    fwrite(STDOUT, " Migration complete. Run `php artisan migrate:status` to confirm.\n");
}
fwrite(STDOUT, "=================================================================\n");
exit(0);

// =============================================================================
// Helpers
// =============================================================================

/**
 * @param  array<int, string>  $argv
 * @return array<string, bool|string>
 */
function parse_cli_args(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            $eqPos = strpos($key, '=');
            if ($eqPos !== false) {
                $opts[substr($key, 0, $eqPos)] = substr($key, $eqPos + 1);
            } else {
                $opts[$key] = true;
            }
        }
    }
    return $opts;
}

function print_help(): void
{
    fwrite(STDOUT, <<<HELP
Usage: php bin/migrate-1.1-to-1.2.php [options]

Options:
  --dry-run           Preview the migration without executing statements.
  --connection=<name> Database connection name (from config/database.php).
  --chunk=<size>      Batch size for the population step (default 1000).
  --help              Show this help and exit.

This script performs a one-way BIGINT → UUID migration on the
auth_users table. Always back up your database first. See
docs/UPGRADE_1.2.md for the full upgrade guide.

HELP
    );
}

/**
 * Bootstrap a Capsule Manager from environment variables. The package
 * is a library so we cannot rely on a Laravel Application; this lets
 * the script run against any DB the operator points at via env vars.
 *
 * Environment variables read:
 *   DB_CONNECTION (mysql|pgsql|sqlite)
 *   DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
 *
 * @return \Illuminate\Database\Capsule\Manager
 */
function bootstrap_capsule(?string $connectionName): \Illuminate\Database\Capsule\Manager
{
    $capsule = new \Illuminate\Database\Capsule\Manager();

    $driver = getenv('DB_CONNECTION') ?: 'mysql';
    $config = [
        'driver' => $driver,
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'database' => getenv('DB_DATABASE') ?: '',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ];

    if ($driver === 'mysql') {
        $config['port'] = (int) (getenv('DB_PORT') ?: 3306);
    } elseif ($driver === 'pgsql') {
        $config['port'] = (int) (getenv('DB_PORT') ?: 5432);
        $config['schema'] = getenv('DB_SCHEMA') ?: 'public';
    } elseif ($driver === 'sqlite') {
        $config['database'] = $config['database'] ?: ':memory:';
    }

    $capsule->addConnection($config, $connectionName ?? 'default');
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    return $capsule;
}

/**
 * @return array{type: string, length: int|null}|null
 */
function column_info(\Illuminate\Database\Connection $db, string $table, string $column): ?array
{
    $driver = $db->getDriverName();
    $database = $db->getDatabaseName();

    if ($driver === 'mysql') {
        $rows = $db->select(
            'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS char_length
             FROM information_schema.columns
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        );
        if (empty($rows)) {
            return null;
        }
        $row = (array) $rows[0];
        return [
            'type' => strtolower((string) ($row['data_type'] ?? '')),
            'length' => isset($row['char_length']) ? (int) $row['char_length'] : null,
        ];
    }

    if ($driver === 'pgsql') {
        $rows = $db->select(
            'SELECT data_type, character_maximum_length
             FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        if (empty($rows)) {
            return null;
        }
        $row = (array) $rows[0];
        return [
            'type' => strtolower((string) ($row['data_type'] ?? '')),
            'length' => isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
        ];
    }

    // Fallback: PRAGMA for sqlite, best-effort for others.
    if ($driver === 'sqlite') {
        $rows = $db->select("PRAGMA table_info({$table})");
        foreach ($rows as $row) {
            $row = (array) $row;
            if (strtolower((string) ($row['name'] ?? '')) === strtolower($column)) {
                return [
                    'type' => strtolower((string) ($row['type'] ?? '')),
                    'length' => null,
                ];
            }
        }
    }

    return null;
}

function column_exists(\Illuminate\Database\Connection $db, string $table, string $column): bool
{
    return column_info($db, $table, $column) !== null;
}

function table_exists(\Illuminate\Database\Connection $db, string $table): bool
{
    try {
        return $db->getSchemaBuilder()->hasTable($table);
    } catch (\Throwable) {
        return false;
    }
}

/**
 * @param  \Closure(\Illuminate\Database\Connection): void  $action
 */
function run_step(\Illuminate\Database\Connection $db, bool $dryRun, int $step, string $description, \Closure $action): void
{
    fwrite(STDOUT, " [Step {$step}] {$description}\n");
    if ($dryRun) {
        fwrite(STDOUT, "    (dry-run, skipping)\n");
        return;
    }
    try {
        $action($db);
    } catch (\Throwable $e) {
        fwrite(STDERR, "    ERROR: " . $e->getMessage() . "\n");
        fwrite(STDERR, "    The migration aborted at step {$step}. Restore from backup before re-running.\n");
        exit(3);
    }
}