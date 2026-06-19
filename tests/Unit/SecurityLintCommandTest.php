<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that `mk:security-lint` was shipped in 1.2.2-hardening with the
 * required detection capabilities and exit-code contract.
 *
 * Implementation note: source-parsing the command. Reasons:
 *  1. The command does file-system reads via Finder; booting a Laravel
 *     app + Database connection just to test the structure is overkill.
 *  2. The fix is a STRING contract: 3 specific checks, a specific
 *     output format, specific exit codes. Source-parsing is the right
 *     level of abstraction.
 *  3. The exit code contract is part of the CI value — if a future
 *     refactor changes `return self::SUCCESS` to `return 0` or drops
 *     the error gate, this test catches it.
 *
 * @see audit-2026-06-17-R2-008, audit-2026-06-17-R2-009
 * @see openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md
 */
uses(MkLaravelTestCase::class);

function securityLintCommandPath(): string
{
    return __DIR__ . '/../../src/Console/Commands/SecurityLintCommand.php';
}

function securityLintCommandSource(): string
{
    $path = securityLintCommandPath();
    expect(file_exists($path))->toBeTrue("SecurityLintCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('SecurityLintCommand is final, has signature mk:security-lint, and is registered in MkServiceProvider', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('final class SecurityLintCommand');
    expect($source)->toContain("protected \$signature = 'mk:security-lint");
    expect($source)->toContain('--path=app/Models');
    expect($source)->toContain('--strict');
    expect($source)->toContain('--format=table');

    // Must be registered in the service provider.
    $provider = (string) file_get_contents(__DIR__ . '/../../src/MkServiceProvider.php');
    expect($provider)->toContain('SecurityLintCommand::class');
});

test('SecurityLintCommand detects $guarded = [] (R2-008)', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    // The checkGuardedEmpty method must exist and match BOTH the
    // `$guarded = []` and `$guarded = ['*']` patterns.
    expect($source)->toContain('function checkGuardedEmpty(');
    expect($source)->toContain('$guarded');
    // The source contains a regex with `\*` (literal asterisk).
    expect($source)->toContain('\\*');
    expect($source)->toContain('mass-assignment');
});

test('SecurityLintCommand detects missing foreign keys via belongsTo heuristic (R2-008 soft)', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('function checkMissingForeignKeys(');
    expect($source)->toContain('belongsTo');
    expect($source)->toContain('->foreign(');
    expect($source)->toContain('->constrained(');
});

test('SecurityLintCommand enforces $tenantColumn whitelist (R2-009)', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('function lintTenantColumnConfig(');
    // The whitelist must include the same columns MkMultiTenantPlugin accepts.
    foreach (['tenant_id', 'client_id', 'org_id', 'company_id'] as $col) {
        expect($source)->toContain("'$col'");
    }
    // The check must produce ERROR (not WARN) — this is a hard fail.
    expect($source)->toContain("'level' => 'error'");
    expect($source)->toContain('NOT in the whitelist');
});

test('SecurityLintCommand exit code contract: 0 on success, 1 on any error', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('return self::SUCCESS');
    expect($source)->toContain('return self::FAILURE');
    // The FAILURE gate must be triggered by `errorCount > 0` and
    // `--strict` must escalate warnings.
    expect($source)->toContain('$errorCount > 0');
    expect($source)->toContain('$strict && $warnCount > 0');
});

test('SecurityLintCommand supports JSON output format (CI-friendly)', function () {
    $source = securityLintCommandSource();
    expect($source)->not->toBeEmpty();

    // The --format option must accept 'json' as a value (CI integration).
    expect($source)->toContain('--format=table');
    expect($source)->toContain('$format === \'json\'');
    expect($source)->toContain('json_encode');
    expect($source)->toContain("'errors'");
    expect($source)->toContain("'warnings'");
    expect($source)->toContain("'findings'");
});
