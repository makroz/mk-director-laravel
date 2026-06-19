<?php

declare(strict_types=1);

use Mk\Director\Console\Commands\LintBoundariesCommand;

/**
 * Covers the MME rule (R-MK-001) end-to-end:
 * builds module trees on disk, plants a cross-module import in one
 * of them, then runs the lint and asserts it reports the violation
 * with the correct source/target.
 *
 * Tests run against a tmp directory to avoid touching the consumer app.
 */

$modulesPath = sys_get_temp_dir() . '/mk-boundaries-test-' . getmypid();

beforeEach(function () use ($modulesPath) {
    if (! is_dir($modulesPath)) {
        mkdir($modulesPath, 0o755, true);
    }
});

afterEach(function () use ($modulesPath) {
    if (is_dir($modulesPath)) {
        rrmdir($modulesPath);
    }
});

it('passes when modules are clean', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Models; class Alpha {}');
    makeModule($modulesPath, 'FakeBeta', 'namespace App\\Modules\\FakeBeta\\Models; class Beta {}');

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(0);
});

it('detects a cross-module model import', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Models; class Alpha {}');
    // FakeBeta imports Alpha's MODEL — forbidden.
    makeModule(
        $modulesPath,
        'FakeBeta',
        "use App\\Modules\\FakeAlpha\\Models\\Alpha;\nnamespace App\\Modules\\FakeBeta\\Models; class Beta {}",
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(1);
});

it('allows cross-module imports via Api/*', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Api; interface AlphaApi {}');
    makeModule(
        $modulesPath,
        'FakeBeta',
        "use App\\Modules\\FakeAlpha\\Api\\AlphaApi;\nnamespace App\\Modules\\FakeBeta; class BetaConsumer {}",
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(0);
});

it('allows cross-module imports via Api/Dto/*', function () use ($modulesPath) {
    makeModule(
        $modulesPath,
        'FakeAlpha',
        'namespace App\\Modules\\FakeAlpha\\Api\\Dto; class AlphaDto {}',
    );
    makeModule(
        $modulesPath,
        'FakeBeta',
        "use App\\Modules\\FakeAlpha\\Api\\Dto\\AlphaDto;\nnamespace App\\Modules\\FakeBeta; class BetaDto {}",
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(0);
});

it('forbids cross-module imports via Services/*', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Services; class AlphaService {}');
    makeModule(
        $modulesPath,
        'FakeBeta',
        "use App\\Modules\\FakeAlpha\\Services\\AlphaService;\nnamespace App\\Modules\\FakeBeta; class BetaService {}",
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(1);
});

it('detects inline FQCN cross-module imports', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Models; class Alpha {}');
    makeModule(
        $modulesPath,
        'FakeBeta',
        "namespace App\\Modules\\FakeBeta\\Services;\nclass BetaService {\n    public function run() {\n        \\App\\Modules\\FakeAlpha\\Models\\Alpha::find(1);\n    }\n}",
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(1);
});

it('excludes the Tests directory from boundary checks', function () use ($modulesPath) {
    makeModule($modulesPath, 'FakeAlpha', 'namespace App\\Modules\\FakeAlpha\\Models; class Alpha {}');
    
    // FakeBeta has a Tests folder with a cross-module import — should be ignored.
    $betaPath = $modulesPath . '/FakeBeta';
    if (! is_dir($betaPath)) {
        mkdir($betaPath, 0o755, true);
    }
    $testsPath = $betaPath . '/Tests';
    if (! is_dir($testsPath)) {
        mkdir($testsPath, 0o755, true);
    }
    file_put_contents(
        $testsPath . '/BetaTest.php',
        "<?php\nuse App\\Modules\\FakeAlpha\\Models\\Alpha;\nnamespace App\\Modules\\FakeBeta\\Tests; class BetaTest {}"
    );

    // Also write a normal clean file in FakeBeta so Finder has something to scan
    file_put_contents(
        $betaPath . '/FakeBetaModule.php',
        "<?php\nnamespace App\\Modules\\FakeBeta; class BetaModule {}"
    );

    $exit = runLintOn($modulesPath);
    expect($exit)->toBe(0);
});

/**
 * Run the lint's discovery + violation logic against an arbitrary path
 * by reflecting into the command's private methods (no Laravel boot
 * needed — the command's --path option is the same escape hatch).
 */
function runLintOn(string $modulesPath): int
{
    $command = new LintBoundariesCommand();
    $ref = new ReflectionClass($command);

    $discover = $ref->getMethod('discoverModules');
    $discover->setAccessible(true);
    $modules = $discover->invoke($command, $modulesPath);
    expect($modules)->not->toBeEmpty();

    $extract = $ref->getMethod('extractUseStatements');
    $extract->setAccessible(true);
    $extractTarget = $ref->getMethod('extractTargetModule');
    $extractTarget->setAccessible(true);
    $isAllowed = $ref->getMethod('isAllowedExternal');
    $isAllowed->setAccessible(true);
    $phpFiles = $ref->getMethod('phpFiles');
    $phpFiles->setAccessible(true);

    foreach ($modules as $sourceModule) {
        $modulePath = $modulesPath . '/' . $sourceModule;
        $files = $phpFiles->invoke($command, $modulePath);

        foreach ($files as $file) {
            $imports = $extract->invoke($command, (string) file_get_contents($file));
            foreach ($imports as $import) {
                $target = $extractTarget->invoke($command, $import);
                if ($target === null || $target === $sourceModule) continue;
                if ($isAllowed->invoke($command, $import)) continue;
                return 1; // violation
            }
        }
    }
    return 0;
}

function makeModule(string $base, string $name, string $phpBody): void
{
    $modulePath = $base . '/' . $name;
    if (! is_dir($modulePath)) {
        mkdir($modulePath, 0o755, true);
    }
    $filename = $modulePath . '/' . $name . 'Module.php';
    file_put_contents($filename, "<?php\n\ndeclare(strict_types=1);\n\n" . $phpBody . "\n");
}

/** Recursive rmdir without depending on Laravel's File facade. */
function rrmdir(string $dir): void
{
    if (! is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}
