<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use FilesystemIterator;
use Illuminate\Console\OutputStyle;
use Illuminate\Filesystem\Filesystem;
use Mk\Director\Console\Commands\MakeModuleCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for `php artisan mk:module {Name} --with-rbac` (R-PKG-008).
 *
 * Cubre el spec RBAC-001..005:
 *  - RBAC-001: el scaffolder genera 20 archivos (User + Role + Ability + 2 pivots + 3 Policies + RbacService + ServiceProvider + 5 Migrations + 3 standard reusados).
 *  - RBAC-002: FK constraints con `cascadeOnDelete` en ambos lados de los pivots (R-RISK-001, hardening R3-014).
 *  - RBAC-003: Gate::policy auto-bind para los 3 modelos en el ServiceProvider.
 *  - RBAC-004: Default-deny (cada método Policy usa `hasAbility()`) + `before()` super-admin bypass.
 *  - RBAC-005: User model extiende `Illuminate\Foundation\Auth\User` (NO `AuthUser`).
 *
 * Patrón: source-parsing (convención del paquete) + 1 test end-to-end con tempdir
 * (subclaseando MakeModuleCommand para override de `modulesPath()`).
 *
 * @see design.md § "Testing Strategy"
 * @see openspec/changes/2026-06-24-admin-with-rbac/design.md
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    // Cleanup de cualquier tempdir creado por el test instance.
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        (new Filesystem)->deleteDirectory($this->tempDir);
    }
});

// ─── Command source tests (RBAC-001 partial — shape) ─────────────────────

test('MakeModuleCommand declares --with-rbac option in $signature', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/MakeModuleCommand.php');

    expect($src)->toContain('mk:module');
    expect($src)->toContain('--with-rbac');
    expect($src)->toMatch('/signature\s*=\s*[\'"]mk:module\s*\{name/');
});

test('MakeModuleCommand has generateRbacPack() method', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/MakeModuleCommand.php');

    expect($src)->toMatch('/function\s+generateRbacPack\s*\(/');
    // handle() despacha al método correcto según el flag
    expect($src)->toMatch('/if\s*\(\s*\$withRbac\s*\)/');
    expect($src)->toContain('$this->generateRbacPack($moduleName)');
});

test('MakeModuleCommand uses sequential addSeconds() to avoid migration filename collisions', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/MakeModuleCommand.php');

    expect($src)->toMatch('/function\s+generateMigration\s*\(/');
    // El loop de migrations usa addSeconds($i) — patrón crítico para que 5
    // migrations generadas en el mismo run tengan timestamps únicos.
    expect($src)->toContain('addSeconds($i)');
});

test('MakeModuleCommand has overridable modulesPath() for testability', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/MakeModuleCommand.php');

    expect($src)->toMatch('/function\s+modulesPath\s*\(\s*string\s+\$moduleName/');
    // Default usa app_path() (convención Laravel)
    expect($src)->toMatch('/function\s+modulesPath[\s\S]*?app_path\(/');
});

test('MakeModuleCommand generateRbacPack generates 15 non-migration + 5 migration files (20 total)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/MakeModuleCommand.php');

    // 15 generateFile() calls para archivos no-migration (3 models + 3 controllers + 3 policies
    // + 1 service + 1 dto + 1 contract + 1 repository + 1 routes + 1 provider)
    $genFileCalls = substr_count($src, '$this->generateFile(');
    expect($genFileCalls)->toBeGreaterThanOrEqual(15);

    // El array $migrations define 5 entries (user, role, ability, role_user pivot, ability_role pivot)
    // que se iteran con foreach para generar las 5 migrations.
    $migrationsArray = preg_match_all(
        "/'stub'\s*=>\s*'migration-(?:user|role|ability|role-user-pivot|ability-role-pivot)\.stub'/",
        $src,
        $_
    );
    expect($migrationsArray)->toBe(5);
});

// ─── Stub content tests (RBAC-002..005) ──────────────────────────────────

test('migration-role-user-pivot.stub has FK with cascadeOnDelete on BOTH columns (RBAC-002, R-RISK-001)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/migration-role-user-pivot.stub');

    // role_id FK: constrained + cascadeOnDelete (en ese orden)
    expect($src)->toMatch(
        "/foreignId\\('role_id'\\)[\\s\\S]*?->constrained\\('{{moduleNameLower}}_roles'\\)[\\s\\S]*?->cascadeOnDelete/"
    );
    // user_id FK: constrained + cascadeOnDelete
    expect($src)->toMatch(
        "/foreignId\\('user_id'\\)[\\s\\S]*?->constrained\\('{{moduleNameLower}}_users'\\)[\\s\\S]*?->cascadeOnDelete/"
    );
    // Composite PK para que (role_id, user_id) sea único
    expect($src)->toContain("->primary(['role_id', 'user_id'])");
});

test('migration-ability-role-pivot.stub has FK with cascadeOnDelete on BOTH columns (RBAC-002, R-RISK-001)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/migration-ability-role-pivot.stub');

    expect($src)->toMatch(
        "/foreignId\\('ability_id'\\)[\\s\\S]*?->constrained\\('{{moduleNameLower}}_abilities'\\)[\\s\\S]*?->cascadeOnDelete/"
    );
    expect($src)->toMatch(
        "/foreignId\\('role_id'\\)[\\s\\S]*?->constrained\\('{{moduleNameLower}}_roles'\\)[\\s\\S]*?->cascadeOnDelete/"
    );
    expect($src)->toContain("->primary(['ability_id', 'role_id'])");
});

test('provider-rbac.stub binds Gate::policy for the 3 RBAC models (RBAC-003)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/provider-rbac.stub');

    // Auto-discovery array: 3 models → 3 policies
    expect($src)->toContain('{{ModuleName}}::class => {{ModuleName}}Policy::class');
    expect($src)->toContain('Role::class           => RolePolicy::class');
    expect($src)->toContain('Ability::class        => AbilityPolicy::class');
    // boot() aplica Gate::policy en un loop
    expect($src)->toContain('Gate::policy($model, $policy)');
});

test('provider-rbac.stub defines Gates via discoverAbilities() (RBAC-003, 15 abilities)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/provider-rbac.stub');

    // discoverAbilities() retorna 15 abilities: 7 user + 6 role + 2 ability.
    // Contamos líneas que matcheen el patrón de ability string (comienza con "{$scope}.).
    $abilityCount = preg_match_all(
        '/^\s*"\{\$scope\}\.[a-zA-Z{}$_.]+\.[a-zA-Z]+",?\s*$/m',
        $src,
        $_
    );
    expect($abilityCount)->toBe(15);

    // boot() itera discoverAbilities() y llama Gate::define para cada uno
    expect($src)->toMatch('/foreach\s*\(\s*\$this->discoverAbilities\(\)[\s\S]*?Gate::define/s');
    // Cada Gate evalúa via hasAbility() (default-deny)
    expect($src)->toContain('Gate::define($ability, static function ($user)');
    expect($src)->toContain('$user->hasAbility($ability)');
});

test('provider-rbac.stub binds RbacService as singleton', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/provider-rbac.stub');

    expect($src)->toMatch('/function\s+register\s*\(\s*\)\s*:\s*void/');
    expect($src)->toContain('$this->app->singleton(RbacService::class)');
});

test('policy-user.stub has before() with super-admin bypass (RBAC-004)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/policy-user.stub');

    expect($src)->toMatch('/function\s+before\s*\(\s*{{ModuleName}}\s+\$user,\s*string\s+\$ability\s*\)\s*:\s*\?bool/');
    // Con returns: hasRole('super-admin') ? true : null
    expect($src)->toMatch("/return\s+\\\$user->hasRole\\('super-admin'\\)\s+\?\s+true\s+:\s+null/");
});

test('policy-user.stub uses hasAbility() in all CRUD methods (RBAC-004 default-deny)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/policy-user.stub');

    // 7 métodos: viewAny, view, create, update, delete, assignRole, revokeRole
    $expectedMethods = ['viewAny', 'view', 'create', 'update', 'delete', 'assignRole', 'revokeRole'];
    foreach ($expectedMethods as $method) {
        expect($src)->toMatch("/function\s+{$method}\\s*\\(/");
    }
    // Cada uno usa hasAbility() (default-deny)
    $hasAbilityCount = substr_count($src, '$user->hasAbility(');
    expect($hasAbilityCount)->toBe(7);
});

test('model-user.stub extends foundation User, NOT AuthUser (RBAC-005, D2)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/model-user.stub');

    // Extiende Authenticatable (foundation)
    expect($src)->toContain('extends Authenticatable');
    // NO extiende AuthUser (decisión D2)
    expect($src)->not->toContain('extends AuthUser');
    expect($src)->not->toContain('extends \\Mk\\Director\\Auth\\Models\\AuthUser');
    expect($src)->not->toContain('extends Mk\\Director\\Auth\\Models\\AuthUser');
    // Tabla scope-prefixed (decisión D3)
    expect($src)->toContain("protected \$table = '{{moduleNameLower}}_users'");
});

test('model-user.stub has hasRole(), hasAbility() and assignRole() methods (RBAC-004)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Stubs/module-rbac/model-user.stub');

    expect($src)->toMatch('/function\s+hasRole\s*\(\s*string\|int\s+\$role\s*\)\s*:\s*bool/');
    expect($src)->toMatch('/function\s+hasAbility\s*\(\s*string\s+\$ability\s*\)\s*:\s*bool/');
    expect($src)->toMatch('/function\s+assignRole\s*\(\s*Role\s+\$role\s*\)\s*:\s*void/');
    // hasAbility() atraviesa roles → abilities (default-deny en policy requiere este chain)
    expect($src)->toContain('->flatMap->abilities');
});

// ─── End-to-end test (RBAC-001 full) ──────────────────────────────────────

test('mk:module TestRbac --with-rbac generates 20 files in tempdir with all tokens replaced', function () {
    $this->tempDir = sys_get_temp_dir().'/mk-rbac-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Subclase que override modulesPath() para escribir a tempdir,
    // y skip registerServiceProvider() para no tocar bootstrap/providers.php.
    $command = new class extends MakeModuleCommand
    {
        public string $testBasePath = '';

        protected function modulesPath(string $moduleName = ''): string
        {
            return $this->testBasePath.($moduleName !== '' ? "/{$moduleName}" : '');
        }

        protected function registerServiceProvider(string $moduleName): void
        {
            // no-op: tests no deben tocar bootstrap/providers.php
        }
    };
    $command->testBasePath = $this->tempDir;
    // Suprimir output del command (writeln, info, etc.) durante el test.
    $command->setOutput(new OutputStyle(
        new StringInput(''),
        new NullOutput
    ));

    // Replicamos la creación de directorios que hace handle() (sin auto-register).
    $directories = [
        'Controllers', 'Contracts', 'DTOs', 'Enums', 'Models',
        'Repositories', 'Requests', 'Resources', 'Routes', 'Services',
        'Database/Migrations', 'Policies',
    ];
    foreach ($directories as $dir) {
        mkdir("{$this->tempDir}/TestRbac/{$dir}", 0755, true);
    }

    // Invocar generateRbacPack() vía reflection (PHP 8.1+ ya no requiere setAccessible()).
    $reflection = new \ReflectionClass($command);
    $genRbac = $reflection->getMethod('generateRbacPack');
    $genRbac->invoke($command, 'TestRbac');

    // ── Verificar 15 archivos no-migration ────────────────────────────
    $expectedNonMigration = [
        'Models/TestRbac.php',
        'Models/Role.php',
        'Models/Ability.php',
        'Controllers/TestRbacController.php',
        'Controllers/RoleController.php',
        'Controllers/AbilityController.php',
        'Policies/TestRbacPolicy.php',
        'Policies/RolePolicy.php',
        'Policies/AbilityPolicy.php',
        'Services/RbacService.php',
        'DTOs/TestRbacData.php',
        'Contracts/TestRbacRepositoryInterface.php',
        'Repositories/TestRbacRepository.php',
        'Routes/api.php',
        'TestRbacModuleServiceProvider.php',  // en la raíz del módulo
    ];
    foreach ($expectedNonMigration as $relative) {
        $full = "{$this->tempDir}/TestRbac/{$relative}";
        expect(file_exists($full))->toBeTrue("Missing file: TestRbac/{$relative}");
    }

    // ── Verificar 5 migrations con timestamps secuenciales ────────────
    $migrations = glob("{$this->tempDir}/TestRbac/Database/Migrations/*_create_test_rbac_*.php") ?: [];
    expect(count($migrations))->toBe(5);

    // Migrations en orden FK-safe: users, roles, abilities, role_user pivot, ability_role pivot
    $sortedMigrations = $migrations;
    sort($sortedMigrations);
    expect(basename($sortedMigrations[0]))->toContain('create_test_rbac_users_table');
    expect(basename($sortedMigrations[1]))->toContain('create_test_rbac_roles_table');
    expect(basename($sortedMigrations[2]))->toContain('create_test_rbac_abilities_table');
    expect(basename($sortedMigrations[3]))->toContain('create_test_rbac_role_user_table');
    expect(basename($sortedMigrations[4]))->toContain('create_test_rbac_ability_role_table');

    // Timestamps deben ser únicos (sequential, no collision)
    $timestamps = array_map(
        fn ($f) => substr(basename($f), 0, 17), // YYYY_MM_DD_HHMMSS (17 chars)
        $migrations
    );
    expect(count(array_unique($timestamps)))->toBe(5);

    // ── Total: 20 archivos PHP ───────────────────────────────────────
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("{$this->tempDir}/TestRbac", FilesystemIterator::SKIP_DOTS)
    );
    $phpFiles = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $phpFiles[] = $file->getPathname();
        }
    }
    expect(count($phpFiles))->toBe(20);

    // ── Tokens reemplazados en TODOS los archivos ─────────────────────
    foreach ($phpFiles as $file) {
        $content = (string) file_get_contents($file);
        expect($content)->not->toContain('{{ModuleName}}', "Token not replaced in {$file}");
        expect($content)->not->toContain('{{moduleNameLower}}', "Token not replaced in {$file}");
        expect($content)->not->toContain('{{moduleNamePluralLower}}', "Token not replaced in {$file}");
        expect($content)->not->toContain('{{migrationDate}}', "Token not replaced in {$file}");
    }

    // ── Smoke check: tokens sí fueron aplicados (sanity) ──────────────
    $userModel = (string) file_get_contents("{$this->tempDir}/TestRbac/Models/TestRbac.php");
    expect($userModel)->toContain('namespace App\Modules\TestRbac\Models');
    expect($userModel)->toContain("protected \$table = 'test_rbac_users'");
    expect($userModel)->toContain('class TestRbac extends Authenticatable');

    $provider = (string) file_get_contents("{$this->tempDir}/TestRbac/TestRbacModuleServiceProvider.php");
    expect($provider)->toContain('namespace App\Modules\TestRbac');
    expect($provider)->toContain('class TestRbacModuleServiceProvider extends ServiceProvider');
    expect($provider)->toContain('TestRbac::class => TestRbacPolicy::class');
});
