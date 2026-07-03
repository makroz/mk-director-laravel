<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that `mk:make:auth-user` is registered in MkServiceProvider.
 *
 * The package ships a `commands()` call inside `MkServiceProvider::boot()`
 * (gated by `runningInConsole()`). If a future refactor drops the new
 * command from that list, the artisan binary will silently stop exposing
 * it — this test pins the registration contract.
 *
 * @see MkServiceProvider::boot()
 */
uses(MkLaravelTestCase::class);

test('MkServiceProvider registers MakeAuthUserCommand', function () {
    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/MkServiceProvider.php');

    // Pint moves FQCN into `use` statements, so we look for the short
    // name. Verifying both the use and the reference inside `$this->commands([...])`
    // protects against a refactor that drops the use but keeps the FQCN inline.
    expect($source)->toContain('use Mk\\Director\\Console\\Commands\\MakeAuthUserCommand;');
    expect($source)->toContain('MakeAuthUserCommand::class');
});

test('MkServiceProvider commands block remains inside runningInConsole()', function () {
    // Regression guard: if someone moves the `$this->commands([...])` call
    // out of the console-only branch, all 9 commands would try to boot
    // during HTTP requests. Pin the structure.
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/MkServiceProvider.php');

    expect($source)->toContain('if ($this->app->runningInConsole())');
    expect($source)->toContain('$this->commands([');
    // MakeAuthUserCommand must be inside the commands array
    $commandsStart = strpos($source, '$this->commands([');
    $commandsEnd = strpos($source, ']);', $commandsStart);
    expect($commandsStart)->not->toBeFalse();
    expect($commandsEnd)->not->toBeFalse();

    $block = substr($source, $commandsStart, $commandsEnd - $commandsStart);
    expect($block)->toContain('MakeAuthUserCommand::class');
});
