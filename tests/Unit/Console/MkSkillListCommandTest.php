<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MkSkillListCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for the `mk:skill:list` command added in
 * sprint 2026-06-24.
 *
 * Pinned contract:
 *   - Command exists with the expected signature
 *   - Discovers skills from three sources (package, agency, local)
 *   - Detects whether a skill is deployed to the project (✅ or —)
 *   - Prints a table with the discovered skills
 *   - Does NOT modify any file (it's read-only)
 *
 * @see MkSkillListCommand
 */
uses(MkLaravelTestCase::class);

function skillListSource(): string
{
    $path = dirname(__DIR__, 3).'/src/Console/Commands/MkSkillListCommand.php';
    expect(file_exists($path))->toBeTrue("MkSkillListCommand must exist at $path");

    return (string) file_get_contents($path);
}

test('mk:skill:list command exists with the expected signature', function () {
    $source = skillListSource();

    expect($source)->toContain('class MkSkillListCommand extends Command');
    expect($source)->toContain("'mk:skill:list");
    expect($source)->toContain('--source=');
});

test('mk:skill:list discovers skills from package, agency and local sources', function () {
    $source = skillListSource();

    expect($source)->toContain('discoverPackageSkills');
    expect($source)->toContain('discoverAgencySkills');
    expect($source)->toContain('discoverLocalSkills');
    // Filter option dispatches to one of the three sources.
    expect($source)->toContain('$source === \'all\'');
});

test('mk:skill:list marks deployed skills with a checkmark and undeployed with a dash', function () {
    $source = skillListSource();

    expect($source)->toContain('isDeployed');
    expect($source)->toContain("'✅'");
    expect($source)->toContain("'—'");
});

test('mk:skill:list is read-only — does not write or copy any file', function () {
    $source = skillListSource();

    // The command only reads from disk and prints to the terminal.
    expect($source)->not->toContain('File::put(');
    expect($source)->not->toContain('File::copy(');
    expect($source)->not->toContain('File::write(');
});

test('mk:skill:list suggests running mk:skill:deploy when skills exist', function () {
    $source = skillListSource();

    expect($source)->toContain('mk:skill:deploy {nombre}');
});

test('mk:skill:list scans both the mavis agent home and the workspace agency', function () {
    $source = skillListSource();

    expect($source)->toContain('~/.mavis/agents/main/skills');
    expect($source)->toContain('.makromania/agency/skills');
});
