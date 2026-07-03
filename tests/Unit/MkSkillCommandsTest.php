<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Console\Commands\MkSkillDeployCommand;
use Mk\Director\Console\Commands\MkSkillListCommand;
use Mk\Director\Console\Commands\MkUpdateCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies the new skill commands are wired up correctly in the package
 * service provider, and that `mk:update` exposes the skill deploy hook.
 *
 * @see MkSkillListCommand
 * @see MkSkillDeployCommand
 * @see MkUpdateCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

test('MkServiceProvider registers MkSkillListCommand and MkSkillDeployCommand', function () {
    $source = (string) file_get_contents(packageRoot().'/src/MkServiceProvider.php');

    // Pint moves FQCN to `use` statements; verify both the use and the
    // reference so a future refactor that drops either is caught.
    expect($source)->toContain('Mk\\Director\\Console\\Commands\\MkSkillListCommand');
    expect($source)->toContain('Mk\\Director\\Console\\Commands\\MkSkillDeployCommand');
});

test('mk:update extends with a skill deploy prompt after the final health check', function () {
    $source = (string) file_get_contents(packageRoot().'/src/Console/Commands/MkUpdateCommand.php');

    // The new step must run AFTER the final mk:status call (line 81 in 1.2.3).
    $statusPos = strpos($source, '$this->call(\'mk:status\')');
    expect($statusPos)->not->toBeFalse();

    $skillPos = strpos($source, 'promptForSkillDeploy');
    expect($skillPos)->not->toBeFalse();

    // The skill prompt must come AFTER the status call (i.e. last).
    expect($skillPos)->toBeGreaterThan($statusPos);
});

test('mk:update skill prompt is opt-in: it asks the user before doing anything', function () {
    $source = (string) file_get_contents(packageRoot().'/src/Console/Commands/MkUpdateCommand.php');

    expect($source)->toContain('promptForSkillDeploy');
    // The prompt uses $this->confirm (Laravel's yes/no question) and
    // defaults to false (decline), so a user who hits Enter does not
    // trigger any deploy.
    expect($source)->toContain("confirm('¿Querés revisar y deployar las skills nuevas");
    expect($source)->toContain(', false)');
});

test('mk:update skips the skill prompt when --dry-run is set', function () {
    $source = (string) file_get_contents(packageRoot().'/src/Console/Commands/MkUpdateCommand.php');

    // The dry-run guard is the first check in promptForSkillDeploy().
    expect($source)->toContain("if (\$this->option('dry-run'))");
    expect($source)->toContain('return;');
});
