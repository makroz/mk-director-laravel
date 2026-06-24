<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for the `mk:make:auth-user` command added in
 * sprint 2026-06-24.
 *
 * The package does not boot a full Laravel app in unit tests (see
 * MkLaravelTestCase docblock), so we assert the contract of the command
 * by reading the source files directly. This is the same strategy used
 * by MkServiceProviderCacheListenerTest for the cache-listener hardening.
 *
 * Contract pinned here:
 *   - Command file exists with the expected signature.
 *   - Five stubs exist under src/Stubs/ with the auth-user.* prefix.
 *   - The model stub extends AuthUser and pins auth_scope in the constructor.
 *   - The migration stub declares an indexed `auth_scope` column.
 *   - The auth-controller stub ships the 6 endpoints
 *     (login / refresh / logout / me / forgot / reset) and tells the
 *     consumer it is a skeleton (TODO markers, not a finished implementation).
 *   - The routes stub uses the `mk.auth:{scope}` middleware from the package.
 *   - The service-provider stub loads routes + migrations for the scope.
 *   - The command explicitly does NOT modify config/auth.php — it prints
 *     snippets so the consumer can review. This is the "least surprise"
 *     commitment made in the command's docblock.
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

// ── Paths ────────────────────────────────────────────────────────────────

function packageRoot(): string
{
    return dirname(__DIR__, 3);
}

function commandSource(): string
{
    $path = packageRoot().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource(string $name): string
{
    $path = packageRoot()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command file ────────────────────────────────────────────────────────

test('mk:make:auth-user command exists with expected signature', function () {
    $source = commandSource();
    expect($source)->toContain('class MakeAuthUserCommand extends Command');
    expect($source)->toContain("'mk:make:auth-user {scope");
    expect($source)->toContain('Str::studly($this->argument(\'scope\'))');
});

test('mk:make:auth-user command generates exactly five stubs', function () {
    $source = commandSource();

    $stubs = [
        'auth-user.model.stub',
        'auth-user.migration.stub',
        'auth-user.auth-controller.stub',
        'auth-user.routes.stub',
        'auth-user.service-provider.stub',
    ];
    foreach ($stubs as $stub) {
        expect($source)->toContain("'{$stub}'");
    }
});

test('mk:make:auth-user command does NOT modify config/auth.php directly', function () {
    // Decisión de diseño: el command imprime los snippets a mano, no
    // escribe en config/auth.php del consumer. Si alguien rompe esto,
    // el principio de least surprise cae.
    $source = commandSource();

    expect($source)->toContain('printAuthConfigSnippets');
    expect($source)->toContain('NO se modifican automáticamente');
    expect($source)->not->toContain("config_path('auth.php')");
    expect($source)->not->toContain("'config/auth.php'"); // any File::put call would reference a literal path
});

test('mk:make:auth-user command auto-registers the ServiceProvider in Laravel 11+ bootstrap/providers.php', function () {
    $source = commandSource();
    expect($source)->toContain('registerServiceProvider');
    expect($source)->toContain("base_path('bootstrap/providers.php')");
});

test('mk:make:auth-user command builds the ServiceProvider FQCN with the Providers subnamespace (bug 1.3.0-001)', function () {
    // The provider is generated at app/Modules/{Scope}/Providers/{Scope}ServiceProvider.php
    // (see auth-user.service-provider.stub) — so the FQCN written into
    // bootstrap/providers.php MUST include the `Providers\` subnamespace.
    // The previous version omitted it, producing
    // `App\Modules\{Scope}\{Scope}ServiceProvider::class` which Laravel
    // could not resolve and the module loaded zero routes.
    $source = commandSource();

    // The file source contains literal `\\` (two backslash characters) inside
    // a PHP double-quoted string. In a single-quoted PHP literal, `\\` is
    // an escape for one `\`, so we need FOUR backslashes in source to
    // produce TWO backslashes in the runtime string.
    $correctFqn = 'App\\\\Modules\\\\{$scope}\\\\Providers\\\\{$scope}ServiceProvider::class';
    $brokenFqn = 'App\\\\Modules\\\\{$scope}\\\\{$scope}ServiceProvider::class';

    expect($source)->toContain($correctFqn);
    expect($source)->not->toContain($brokenFqn);
});

test('mk:make:auth-user package does NOT ship a hardcoded create_admins_table migration (bug 1.3.0-002)', function () {
    // The package used to ship `2026_06_10_000006_create_admins_table.php`
    // as a leftover from the original Admin scope. Combined with the
    // scaffolder's own migration (which also creates the `admins` table
    // for any scope called `admin`), this caused `php artisan migrate`
    // to fail with "Table 'admins' already exists".
    //
    // The scaffolder is the canonical source for the scope's table —
    // the hardcoded migration was deleted in 1.3.1.
    $migrationsDir = packageRoot().'/src/Auth/Database/Migrations';
    $hardcoded = $migrationsDir.'/2026_06_10_000006_create_admins_table.php';
    expect(file_exists($hardcoded))->toBeFalse(
        "Hardcoded admins migration must be removed from the package. ".
        "The scaffolder (auth-user.migration.stub) is the canonical source."
    );
});

// ── Model stub ──────────────────────────────────────────────────────────

test('auth-user model stub extends AuthUser and pins auth_scope in the constructor', function () {
    $source = stubSource('auth-user.model.stub');

    expect($source)->toContain('extends AuthUser');
    expect($source)->toContain("setAuthScope('{{moduleNameLower}}')");
    expect($source)->toContain("protected \$table = '{{moduleNamePluralLower}}'");
    expect($source)->toContain('declare(strict_types=1)');
});

// ── Migration stub ──────────────────────────────────────────────────────

test('auth-user migration stub creates the scope table with indexed auth_scope', function () {
    $source = stubSource('auth-user.migration.stub');

    expect($source)->toContain("Schema::create('{{moduleNamePluralLower}}'");
    expect($source)->toContain("string('auth_scope')->default('{{moduleNameLower}}')");
    expect($source)->toContain('->index()');
    expect($source)->toContain('{{moduleNameLower}}_password_reset_tokens');
});

// ── AuthController stub ─────────────────────────────────────────────────

test('auth-user auth-controller stub exposes all six endpoints as skeletons', function () {
    $source = stubSource('auth-user.auth-controller.stub');

    // 6 endpoints
    foreach (['login', 'refresh', 'logout', 'me', 'forgot', 'reset'] as $endpoint) {
        expect($source)->toContain("public function {$endpoint}(");
    }

    // Skeleton character — at least one TODO per unfinished method
    expect($source)->toContain('TODO');
    expect($source)->toContain('501'); // refresh + reset return 501 not_implemented
});

test('auth-user auth-controller stub mentions TokenIssuer for the dev to wire up', function () {
    $source = stubSource('auth-user.auth-controller.stub');
    expect($source)->toContain('TokenIssuer');
});

// ── Routes stub ─────────────────────────────────────────────────────────

test('auth-user routes stub uses mk.auth:{scope} middleware for protected endpoints', function () {
    $source = stubSource('auth-user.routes.stub');

    // Bug 1.3.0-003 fix: routes must be prefixed with `api/` because
    // Laravel 11+ `loadRoutesFrom` from a ServiceProvider does NOT
    // inherit the `apiPrefix` from `bootstrap/app.php` (that only
    // applies to the central `routes/api.php`). The AuthController
    // docblock, the command's success output, and CHANGELOG 1.3.0
    // all advertised `/api/{scope}/auth/*`; the stub now matches.
    expect($source)->toContain("prefix('api/{{moduleNameLower}}/auth')");
    expect($source)->toContain("'mk.auth:{{moduleNameLower}}'");
    expect($source)->toContain('Route::post(\'login\'');
    expect($source)->toContain('Route::post(\'refresh\'');
    expect($source)->toContain('Route::post(\'logout\'');
    expect($source)->toContain('Route::get(\'me\'');
    expect($source)->toContain('Route::post(\'forgot\'');
    expect($source)->toContain('Route::post(\'reset\'');
});

// ── ServiceProvider stub ────────────────────────────────────────────────

test('auth-user service-provider stub loads routes and migrations for the scope', function () {
    $source = stubSource('auth-user.service-provider.stub');

    expect($source)->toContain('extends ServiceProvider');
    expect($source)->toContain('loadRoutesFrom(__DIR__ . \'/../Http/Routes/api.php\')');
    expect($source)->toContain('loadMigrationsFrom(__DIR__ . \'/../Database/Migrations\')');
});
