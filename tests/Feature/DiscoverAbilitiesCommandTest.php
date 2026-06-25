<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Filesystem\Filesystem;
use Mk\Director\Auth\Attributes\Ability as AbilityAttribute;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Tests for `php artisan mk:discover-abilities` (R-PKG-007).
 *
 * Cubre el design.md D1..D7:
 *  - D1 (hybrid): provider primario; attributes+docblock como fallback único cuando provider ausente.
 *  - D2: PHP 8.4 attribute primary, docblock fallback.
 *  - D3: interactive prompt con --force/--dry-run/--no-interaction escape hatches.
 *  - D4: auto_discover_abilities config flag.
 *  - D6: scope-aware ({scope}_abilities, no central abilities).
 *  - D7: fallback chain.
 *
 * Patrón: source-parsing tests (rápidos) + reflection-based end-to-end tests.
 * Los tests end-to-end usan `eval()` para definir clases con namespaces
 * únicos por test (evita "Cannot redeclare class" entre tests).
 *
 * @see design.md § "Testing Strategy"
 * @see openspec/changes/2026-06-24-discover-abilities-to-core/design.md
 */
uses(MkLaravelTestCase::class);

// ─── Source-parsing tests (rápidos, no DB) ──────────────────────────────

test('DiscoverAbilitiesCommand has correct signature', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/signature\s*=\s*[\'"]mk:discover-abilities/');
    expect($src)->toContain('--module=*');
    expect($src)->toContain('--dry-run');
    expect($src)->toContain('--force');
    expect($src)->toContain('--json');
});

test('DiscoverAbilitiesCommand implements hybrid source-of-truth (D1)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/function\s+discoverAbilitiesFromProvider\s*\(/');
    expect($src)->toMatch('/function\s+discoverAbilitiesFromAttributesAndDocblocks\s*\(/');
    expect($src)->toContain("'source' => 'provider'");
    expect($src)->toContain("'source' => 'fallback'");
    expect($src)->toMatch('/\$discovery\s*=\s*\$this->discoverAbilitiesFromProvider/');
    expect($src)->toMatch("/\\\$discovery\\['source'\\]\\s*===\\s*'provider'/");
    expect($src)->toMatch('/discoverAbilitiesFromAttributesAndDocblocks\(\$moduleInfo\)/');
});

test('DiscoverAbilitiesCommand implements interactive prompt with --force/--dry-run skip (D3)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/function\s+resolveWriteIntent\s*\(/');
    expect($src)->toContain("option('dry-run')");
    expect($src)->toContain('return false');
    expect($src)->toContain("option('force')");
    expect($src)->toContain('return true');
    expect($src)->toMatch('/\$this->confirm\([^,]+,\s*false\s*\)/s');
    expect($src)->toContain("No combines --dry-run y --force");
});

test('DiscoverAbilitiesCommand uses UPSERT for idempotency', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/function\s+upsertAbilities\s*\(/');
    expect($src)->toContain('->upsert(');
    expect($src)->toMatch('/upsert\(\s*\$rows,\s*\[\s*[\'"]name[\'"]\s*\]/');
    expect($src)->toContain("'description'");
    expect($src)->toContain("'updated_at'");
});

test('DiscoverAbilitiesCommand has overridable modulesPath() (testability, D7)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/function\s+modulesPath\s*\(\s*string\s+\$moduleName/');
    expect($src)->toContain("config('mk_director.paths.modules'");
});

test('DiscoverAbilitiesCommand uses Str::plural for scope detection', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toContain("Str::snake(Str::plural(\$moduleName))");
    expect($src)->toContain('{$scope}_abilities');
});

test('DiscoverAbilitiesCommand docblock regex escapes no curly braces (PHP 8.5 PCRE2)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/preg_match\(\s*[\'"]\/@mk-ability\\\\s\+\(\[a-z0-9\._\*-\]\+\)/');
    expect($src)->not->toMatch('/preg_match\([\'"][^\'"]*\\\}\}/');
});

// ─── Ability attribute tests ────────────────────────────────────────────

test('Ability attribute is TARGET_METHOD + IS_REPEATABLE', function () {
    $reflection = new ReflectionClass(AbilityAttribute::class);
    $attributes = $reflection->getAttributes(\Attribute::class);

    expect($attributes)->toHaveCount(1);

    /** @var \Attribute $attr */
    $attr = $attributes[0]->newInstance();
    expect($attr->flags & \Attribute::TARGET_METHOD)->toBe(\Attribute::TARGET_METHOD);
    expect($attr->flags & \Attribute::IS_REPEATABLE)->toBe(\Attribute::IS_REPEATABLE);
});

test('Ability attribute has name and description properties', function () {
    $reflection = new ReflectionClass(AbilityAttribute::class);

    expect($reflection->hasProperty('name'))->toBeTrue();
    expect($reflection->hasProperty('description'))->toBeTrue();

    $constructor = $reflection->getConstructor();
    expect($constructor->getNumberOfParameters())->toBe(2);

    $params = $constructor->getParameters();
    expect($params[0]->getName())->toBe('name');
    expect($params[1]->getName())->toBe('description');
    expect($params[1]->allowsNull())->toBeTrue();
});

// ─── End-to-end tests (eval-based, full isolation per test) ─────────────

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        (new Filesystem)->deleteDirectory($this->tempDir);
    }

    // Reset global state so the next test starts clean.
    \Illuminate\Container\Container::setInstance(null);
    \Illuminate\Support\Facades\Facade::clearResolvedInstances();
    \Illuminate\Support\Facades\Facade::setFacadeApplication(null);
});

/**
 * Sub-classed command que override `modulesPath()` para escribir a tempdir.
 */
function makeTestCommand(string $basePath): DiscoverAbilitiesCommand
{
    return new class($basePath) extends DiscoverAbilitiesCommand {
        public string $testBasePath = '';

        public function __construct(string $basePath)
        {
            parent::__construct();
            $this->testBasePath = $basePath;
        }

        protected function modulesPath(string $moduleName = ''): string
        {
            return $this->testBasePath.($moduleName !== '' ? '/'.$moduleName : '');
        }
    };
}

/**
 * Invoke a protected/private method on the command via reflection.
 */
function invokeProtected(object $obj, string $method, array $args = [])
{
    $reflection = new ReflectionClass($obj);
    $m = $reflection->getMethod($method);

    return $m->invokeArgs($obj, $args);
}

/**
 * Build a fresh SQLite-in-memory Capsule + bind DB facade.
 * Returns the Capsule for tests to query rows.
 */
function setupSqliteWithAbilities(): Capsule
{
    $capsule = new Capsule();
    $capsule->addConnection([
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    \Illuminate\Container\Container::setInstance(new \Illuminate\Container\Container());
    \Illuminate\Container\Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    \Illuminate\Support\Facades\Facade::setFacadeApplication(\Illuminate\Container\Container::getInstance());

    $schema = $capsule->getConnection()->getSchemaBuilder();
    foreach (['admin_abilities', 'billing_abilities', 'abilities'] as $table) {
        $schema->create($table, function ($t) {
            $t->id();
            $t->string('name')->unique();
            $t->string('description')->nullable();
            $t->timestamps();
        });
    }

    return $capsule;
}

/**
 * Build an isolated module structure in a tempdir with eval-based classes.
 * Returns a setup array with all the parts needed by tests.
 *
 * Classes are eval()'d in a unique namespace per test (using uniqid())
 * to avoid "Cannot redeclare class" between tests.
 *
 * `$providerBody` should be a PHP class body WITHOUT the `class XXXModuleServiceProvider { ... }`
 * wrapper — the helper wraps it. Use {ClassName} as a placeholder for the
 * auto-generated class name.
 */
function buildIsolatedModule(string $moduleName, string $providerBody = '', string $controllerBody = '', string $modelBody = ''): array
{
    $uid = str_replace('.', '', uniqid('', true));
    $ns = "TestNs\\{$moduleName}_{$uid}";
    // Class names end in 'ServiceProvider' / 'Controller' / model name so the
    // command's resolver (Str::endsWith(...ServiceProvider)) matches them.
    $providerClassName = "{$moduleName}ModuleServiceProvider";
    $controllerClassName = "{$moduleName}Controller";
    $modelClassName = "{$moduleName}";

    $providerFqn = "{$ns}\\{$providerClassName}";
    $controllerFqn = "{$ns}\\Http\\Controllers\\{$controllerClassName}";
    $modelFqn = "{$ns}\\Models\\{$modelClassName}";

    $tempDir = sys_get_temp_dir()."/mk-discover-{$uid}";
    mkdir("{$tempDir}/{$moduleName}/Http/Controllers", 0755, true);
    mkdir("{$tempDir}/{$moduleName}/Models", 0755, true);

    // Inject the moduleName into the provider body so the class name contains it.
    // The heuristic in resolveProviderClass() looks for `str_contains($class, $moduleName)`.
    if ($providerBody !== '') {
        $body = str_replace('{ClassName}', $providerClassName, $providerBody);
        eval("namespace {$ns}; {$body}");
    }
    if ($controllerBody !== '') {
        $body = str_replace('{ClassName}', $controllerClassName, $controllerBody);
        eval("namespace {$ns}\\Http\\Controllers; {$body}");
    }
    if ($modelBody !== '') {
        $body = str_replace('{ClassName}', $modelClassName, $modelBody);
        eval("namespace {$ns}\\Models; {$body}");
    }

    $capsule = setupSqliteWithAbilities();

    $command = makeTestCommand($tempDir);
    $command->setOutput(new OutputStyle(new \Symfony\Component\Console\Input\StringInput(''), new NullOutput()));

    return [
        'tempDir' => $tempDir,
        'moduleName' => $moduleName,
        'providerFqn' => $providerFqn,
        'controllerFqn' => $controllerFqn,
        'modelFqn' => $modelFqn,
        'providerClassName' => $providerClassName,
        'controllerClassName' => $controllerClassName,
        'capsule' => $capsule,
        'command' => $command,
    ];
}

/**
 * Build a minimal moduleInfo array for a single module with eval'd classes.
 * Mimics what discoverModules() would return if the command scanned the namespace.
 */
function buildModuleInfo(array $setup, string $moduleName, array $classes): array
{
    $tempDir = $setup['tempDir'];
    $modulePath = "{$tempDir}/{$moduleName}";

    return [
        $moduleName => [
            'path' => $modulePath,
            'classes' => $classes,
        ],
    ];
}

test('end-to-end: provider source — 15 abilities from discoverAbilities() are inserted (D1, Q1)', function () {
    $moduleName = 'Admin'.uniqid();
    $setup = buildIsolatedModule($moduleName, <<<'PHP'
class {ClassName}
{
    public function discoverAbilities(): array
    {
        return [
            'admin.users.viewAny', 'admin.users.view', 'admin.users.create',
            'admin.users.update', 'admin.users.delete',
            'admin.roles.viewAny', 'admin.roles.view', 'admin.roles.create',
            'admin.roles.update', 'admin.roles.delete',
            'admin.abilities.viewAny', 'admin.abilities.view',
            'admin.posts.view', 'admin.posts.create', 'admin.posts.delete',
        ];
    }
}
PHP);

    // Build moduleInfo manually (since the heuristic resolver looks for `App\Modules\*`).
    $moduleInfo = [
        'path' => $setup['tempDir'].'/'.$moduleName,
        'classes' => [$setup['providerFqn']],
    ];

    $discovery = invokeProtected($setup['command'], 'discoverAbilitiesFromProvider', [$moduleName, $moduleInfo]);
    expect($discovery['source'])->toBe('provider');
    expect($discovery['abilities'])->toHaveCount(15);

    invokeProtected($setup['command'], 'upsertAbilities', ['admin', $discovery['abilities']]);

    $rows = $setup['capsule']->getConnection()->table('admin_abilities')->get();
    expect($rows)->toHaveCount(15);
    expect($rows->pluck('name')->toArray())->toContain('admin.users.viewAny');
    expect($rows->pluck('name')->toArray())->toContain('admin.abilities.view');
});

test('end-to-end: fallback path — provider absent, attributes + docblock combined (Q1 hybrid)', function () {
    $moduleName = 'Billing'.uniqid();

    // Controller with 2 attributes + 1 docblock. NO provider.
    $controllerBody = <<<'PHP'
use Mk\Director\Auth\Attributes\Ability;

class {ClassName}
{
    #[Ability('billing.invoices.list', 'Listar facturas')]
    public function index() {}

    #[Ability('billing.invoices.create')]
    public function store() {}

    /**
     * Crear una nota de crédito.
     *
     * @mk-ability billing.invoices.refund Reembolsar factura
     */
    public function refund() {}
}
PHP;

    $setup = buildIsolatedModule($moduleName, '', $controllerBody);

    $moduleInfo = [
        'path' => $setup['tempDir'].'/'.$moduleName,
        'classes' => [$setup['controllerFqn']],
    ];

    // Provider absent → fallback.
    $discovery = invokeProtected($setup['command'], 'discoverAbilitiesFromProvider', [$moduleName, $moduleInfo]);
    expect($discovery['source'])->toBe('fallback');

    $abilities = invokeProtected($setup['command'], 'discoverAbilitiesFromAttributesAndDocblocks', [$moduleInfo]);
    expect($abilities)->toHaveCount(3);

    invokeProtected($setup['command'], 'upsertAbilities', ['billing', $abilities]);

    $rows = $setup['capsule']->getConnection()->table('billing_abilities')->get();
    expect($rows)->toHaveCount(3);

    $names = $rows->pluck('name')->toArray();
    expect($names)->toContain('billing.invoices.list');
    expect($names)->toContain('billing.invoices.create');
    expect($names)->toContain('billing.invoices.refund');

    // Description del attribute.
    expect($rows->where('name', 'billing.invoices.list')->first()->description)->toBe('Listar facturas');
});

test('end-to-end: provider present OVERRIDES attributes — D1 hybrid semantics', function () {
    $moduleName = 'Override'.uniqid();

    $providerBody = <<<'PHP'
class {ClassName}
{
    public function discoverAbilities(): array
    {
        return ['override.from_provider', 'override.from_provider_2'];
    }
}
PHP;

    $controllerBody = <<<'PHP'
use Mk\Director\Auth\Attributes\Ability;
class {ClassName}
{
    #[Ability('override.from_attribute')]
    public function index() {}
}
PHP;

    $setup = buildIsolatedModule($moduleName, $providerBody, $controllerBody);

    // Create the abilities table for this scope.
    $setup['capsule']->getConnection()->getSchemaBuilder()->create('override_abilities', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->string('description')->nullable();
        $t->timestamps();
    });

    $moduleInfo = [
        'path' => $setup['tempDir'].'/'.$moduleName,
        'classes' => [$setup['providerFqn'], $setup['controllerFqn']],
    ];

    $discovery = invokeProtected($setup['command'], 'discoverAbilitiesFromProvider', [$moduleName, $moduleInfo]);
    expect($discovery['source'])->toBe('provider');
    expect($discovery['abilities'])->toHaveCount(2);

    invokeProtected($setup['command'], 'upsertAbilities', ['override', $discovery['abilities']]);

    $rows = $setup['capsule']->getConnection()->table('override_abilities')->get();
    $names = $rows->pluck('name')->toArray();

    expect($names)->toContain('override.from_provider');
    expect($names)->toContain('override.from_provider_2');
    expect($names)->not->toContain('override.from_attribute');
    expect($rows)->toHaveCount(2);
});

test('end-to-end: idempotency — UPSERT twice = same final state', function () {
    $moduleName = 'Idemp'.uniqid();

    $providerBody = <<<'PHP'
class {ClassName}
{
    public function discoverAbilities(): array
    {
        return ['idemp.users.view', 'idemp.users.create'];
    }
}
PHP;

    $setup = buildIsolatedModule($moduleName, $providerBody);

    // Create the abilities table for this scope.
    $setup['capsule']->getConnection()->getSchemaBuilder()->create('idemp_abilities', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->string('description')->nullable();
        $t->timestamps();
    });

    $moduleInfo = [
        'path' => $setup['tempDir'].'/'.$moduleName,
        'classes' => [$setup['providerFqn']],
    ];

    $abilities = invokeProtected($setup['command'], 'discoverAbilitiesFromProvider', [$moduleName, $moduleInfo])['abilities'];

    // First upsert.
    invokeProtected($setup['command'], 'upsertAbilities', ['idemp', $abilities]);
    expect($setup['capsule']->getConnection()->table('idemp_abilities')->count())->toBe(2);

    // Second upsert.
    invokeProtected($setup['command'], 'upsertAbilities', ['idemp', $abilities]);
    expect($setup['capsule']->getConnection()->table('idemp_abilities')->count())->toBe(2);

    $names = $setup['capsule']->getConnection()->table('idemp_abilities')->pluck('name')->all();
    expect(array_unique($names))->toEqual($names);
});

test('end-to-end: scope detection — provider writes to scopetest_abilities, not abilities (D6)', function () {
    $moduleName = 'ScopeTest'.uniqid();

    $providerBody = <<<'PHP'
class {ClassName}
{
    public function discoverAbilities(): array { return ['scopetest.test']; }
}
PHP;

    $setup = buildIsolatedModule($moduleName, $providerBody);

    // Create scopetest_abilities table.
    $setup['capsule']->getConnection()->getSchemaBuilder()->create('scopetest_abilities', function ($t) {
        $t->id();
        $t->string('name')->unique();
        $t->string('description')->nullable();
        $t->timestamps();
    });

    $moduleInfo = [
        'path' => $setup['tempDir'].'/'.$moduleName,
        'classes' => [$setup['providerFqn']],
    ];

    $abilities = invokeProtected($setup['command'], 'discoverAbilitiesFromProvider', [$moduleName, $moduleInfo])['abilities'];

    invokeProtected($setup['command'], 'upsertAbilities', ['scopetest', $abilities]);

    expect($setup['capsule']->getConnection()->table('scopetest_abilities')->count())->toBe(1);
    expect($setup['capsule']->getConnection()->table('admin_abilities')->count())->toBe(0);
    expect($setup['capsule']->getConnection()->table('billing_abilities')->count())->toBe(0);
    expect($setup['capsule']->getConnection()->table('abilities')->count())->toBe(0);
});

test('MkServiceProvider registers DiscoverAbilitiesCommand', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/MkServiceProvider.php');

    expect($src)->toContain('use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;');
    expect($src)->toContain('DiscoverAbilitiesCommand::class');
});

test('MkServiceProvider has registerAutoDiscoverAbilities hook gated by config flag (D4)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/src/MkServiceProvider.php');

    expect($src)->toMatch('/function\s+registerAutoDiscoverAbilities\s*\(/');
    expect($src)->toContain("config('mk_director.features.auto_discover_abilities'");
    expect($src)->toContain('--force');
    expect($src)->toContain('--json');
});

test('config has paths.modules and features.auto_discover_abilities', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2).'/config/mk_director.php');

    expect($src)->toContain("'paths' => [");
    expect($src)->toContain("'modules'");
    expect($src)->toContain("MK_MODULES_PATH");
    expect($src)->toContain('auto_discover_abilities');
    expect($src)->toContain('MK_AUTO_DISCOVER_ABILITIES');
});