<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Process;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * T7.1 (R3-013): UPGRADE_1.2.md + migrate-1.1-to-1.2.php script.
 *
 * Verifies the upgrade deliverable exists and has the documented
 * shape: section headings for breaking changes, idempotent
 * migration script with --dry-run + --help, and a rollback
 * warning.
 *
 * @see audit-2026-06-17-R3-013
 */
uses(MkLaravelTestCase::class);

test('UPGRADE_1.2.md exists', function () {
    $path = __DIR__ . '/../../../docs/UPGRADE_1.2.md';
    expect(file_exists($path))->toBeTrue();
});

test('UPGRADE_1.2.md documents the four breaking changes', function () {
    $md = (string) file_get_contents(__DIR__ . '/../../../docs/UPGRADE_1.2.md');

    expect($md)->toContain('UUID primary key');
    expect($md)->toContain('opt-in');
    expect($md)->toContain('MkAbility');
    expect($md)->toContain('unknown operator');
});

test('UPGRADE_1.2.md warns that the UUID migration is irreversible', function () {
    $md = (string) file_get_contents(__DIR__ . '/../../../docs/UPGRADE_1.2.md');

    // The migration is one-way. The doc MUST call this out and
    // require a backup.
    expect($md)->toMatch('/irreversible|no rollback|backup/i');
});

test('UPGRADE_1.2.md includes a rollback procedure', function () {
    $md = (string) file_get_contents(__DIR__ . '/../../../docs/UPGRADE_1.2.md');

    expect($md)->toContain('Rollback');
});

test('migrate-1.1-to-1.2.php script exists and is syntactically valid', function () {
    $path = __DIR__ . '/../../../bin/migrate-1.1-to-1.2.php';
    expect(file_exists($path))->toBeTrue();

    $output = [];
    $return = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $return);
    expect($return)->toBe(0);
    expect(implode("\n", $output))->toContain('No syntax errors detected');
});

test('migrate script supports --help', function () {
    $path = realpath(__DIR__ . '/../../../bin/migrate-1.1-to-1.2.php');
    expect($path)->not->toBeFalse();

    $output = [];
    $return = 0;
    exec('php ' . escapeshellarg($path) . ' --help 2>&1', $output, $return);

    expect($return)->toBe(0);
    $combined = implode("\n", $output);
    expect($combined)->toContain('Usage:');
    expect($combined)->toContain('--dry-run');
    expect($combined)->toContain('--connection=');
});

test('migrate script source declares strict_types=1', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../bin/migrate-1.1-to-1.2.php');
    expect($src)->toContain('declare(strict_types=1);');
});

test('migrate script refuses to run outside CLI', function () {
    // PHP_SAPI is 'cli' when run via PHPUnit/Pest from the command line.
    // We assert the source has the runtime guard by inspecting it.
    $src = (string) file_get_contents(__DIR__ . '/../../../bin/migrate-1.1-to-1.2.php');
    expect($src)->toContain("PHP_SAPI !== 'cli'");
});

test('migrate script handles the case where auth_users does not exist (no-op)', function () {
    // Run with an empty in-memory SQLite so the table does not exist.
    $script = realpath(__DIR__ . '/../../../bin/migrate-1.1-to-1.2.php');
    expect($script)->not->toBeFalse();

    $cmd = sprintf(
        'DB_CONNECTION=sqlite DB_DATABASE=:memory: php %s 2>&1',
        escapeshellarg($script)
    );

    $output = [];
    $return = 0;
    exec($cmd, $output, $return);

    expect($return)->toBe(0);
    expect(implode("\n", $output))->toContain('does not exist');
});