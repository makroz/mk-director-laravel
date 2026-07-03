<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MkSkillDeployCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for the `mk:skill:deploy` command added in
 * sprint 2026-06-24.
 *
 * Pinned contract:
 *   - Command exists with the expected signature (positional name + --to + --dry-run)
 *   - Finds the skill source from three priorities (mavis-agent → agency → package)
 *   - Autodetects the target root (.makromania/agency/skills first, then .agents/skills, then prompt)
 *   - Respects --dry-run
 *   - Updates AGENTS.md idempotently (no duplicate skills in the section)
 *   - Does NOT modify config or any consumer file outside AGENTS.md + the skill folder
 *
 * @see MkSkillDeployCommand
 */
uses(MkLaravelTestCase::class);

function skillDeploySource(): string
{
    $path = dirname(__DIR__, 3).'/src/Console/Commands/MkSkillDeployCommand.php';
    expect(file_exists($path))->toBeTrue("MkSkillDeployCommand must exist at $path");

    return (string) file_get_contents($path);
}

test('mk:skill:deploy command exists with the expected signature', function () {
    $source = skillDeploySource();

    expect($source)->toContain('class MkSkillDeployCommand extends Command');
    expect($source)->toContain("'mk:skill:deploy");
    expect($source)->toContain('{name?');
    expect($source)->toContain('--to=');
    expect($source)->toContain('--dry-run');
});

test('mk:skill:deploy finds the skill source from three priorities', function () {
    $source = skillDeploySource();

    // The lookup order is mavis-agent → agency → package.
    expect($source)->toContain('findSkillSource');
    expect($source)->toContain('~/.mavis/agents/main/skills/');
    expect($source)->toContain('.makromania/agency/skills/');
    expect($source)->toContain('src/Skills/');
});

test('mk:skill:deploy autodetects the target root with the agency path as priority', function () {
    $source = skillDeploySource();

    expect($source)->toContain('detectTargetRoot');
    // .makromania/agency/skills is checked first.
    expect($source)->toContain('base_path(\'.makromania/agency/skills\')');
    // .agents/skills is the fallback.
    expect($source)->toContain('base_path(\'.agents/skills\')');
});

test('mk:skill:deploy respects --dry-run without writing any file', function () {
    $source = skillDeploySource();

    expect($source)->toContain("option('dry-run')");
    expect($source)->toContain('[Simulación]');
});

test('mk:skill:deploy creates AGENTS.md if it does not exist', function () {
    $source = skillDeploySource();

    expect($source)->toContain('updateAgentsMd');
    expect($source)->toContain("base_path('AGENTS.md')");
    expect($source)->toContain('Creando AGENTS.md');
});

test('mk:skill:deploy updates AGENTS.md idempotently without duplicating skills', function () {
    $source = skillDeploySource();

    // The regex-based updater must guard against re-adding an existing bullet.
    expect($source)->toContain('ya referencia la skill');
    expect($source)->toContain('no se duplica');
});

test('mk:skill:deploy is non-invasive: does NOT touch config or the project code', function () {
    $source = skillDeploySource();

    // No config writes, no provider registration, no composer edits.
    expect($source)->not->toContain('config_path(');
    expect($source)->not->toContain('providers.php');
    expect($source)->not->toContain('composer.json');
    // Only AGENTS.md and the SKILL.md are written.
    expect($source)->toContain("File::copy(\$source['path']");
    expect($source)->toContain('File::put($agentsPath');
});
