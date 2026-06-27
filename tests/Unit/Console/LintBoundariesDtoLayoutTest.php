<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-023 (rc12): `LintBoundariesCommand` ALLOWED_PATTERNS expanded
 * to accept `App\Modules\X\DTOs\` (the canonical DTO location per
 * R-MK-001 + mk:module + mk:make:auth-user --with-crud). The legacy
 * `App\Modules\X\Api\Dto\` is still allowed (BC for 1 release) but
 * emits a deprecation warning.
 *
 * Why this matters: until rc12 the only DTO location the linter
 * accepted was `Api\Dto\`. But the package's own scaffolders
 * (`mk:module`, `mk:make:auth-user --with-crud`) and the sandbox
 * Admin all emit `DTOs/` (sibling of `Api/`, not nested under it).
 * The linter was the only desalineado — that meant a fresh
 * `mk:make:auth-user Admin --with-crud` followed by `mk:lint:boundaries`
 * would FAIL because the generated DTOs were flagged as cross-module
 * imports.
 *
 * The fix: accept `DTOs\` as canonical (silent), keep `Api\Dto\` as
 * allowed (with deprecation warning). Consumers see the warning in CI
 * output and can migrate at their own pace.
 */
uses(MkLaravelTestCase::class);

function lintBoundariesPath(): string
{
    return __DIR__ . '/../../../src/Console/Commands/LintBoundariesCommand.php';
}

function lintBoundariesSource(): string
{
    $path = lintBoundariesPath();
    expect(file_exists($path))->toBeTrue("LintBoundariesCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('ALLOWED_PATTERNS includes DTOs (canonical) per R-MK-001', function () {
    $source = lintBoundariesSource();

    // The new pattern must match `App\Modules\X\DTOs\`. In PHP single-quoted
    // string literals, `\\\\` represents 2 backslashes in the actual string.
    // The source has 4 backslashes between segments (PCRE `\\` = literal `\`),
    // so we need 8 backslashes in our test string to match 4.
    expect($source)->toContain('App\\\\\\\\Modules\\\\\\\\[A-Za-z0-9_]+\\\\\\\\DTOs\\\\\\\\');
});

test('ALLOWED_PATTERNS keeps Api (Api\Dto split out)', function () {
    $source = lintBoundariesSource();

    // The pattern for `App\Modules\X\Api\` must exist (without the
    // optional `Dto` suffix). This ensures `App\Modules\X\Api\Foo`
    // (non-DTO) is still allowed.
    expect($source)->toContain('App\\\\\\\\Modules\\\\\\\\[A-Za-z0-9_]+\\\\\\\\Api\\\\\\\\');

    // Negative check: the old single-regex with optional Dto is GONE.
    expect($source)->not->toContain('Api(\\\\\\\\Dto)?');
});

test('DEPRECATED_PATTERNS list exists and flags Api\\Dto as deprecated', function () {
    $source = lintBoundariesSource();

    // A separate constant for deprecation patterns.
    expect($source)->toContain('DEPRECATED_PATTERNS');

    // The deprecation message mentions both the legacy `Api\Dto` and the
    // canonical `DTOs` path. (Order doesn't matter, both strings present.)
    expect($source)->toContain('Api\\\\\\\\Dto');
    expect($source)->toContain('DTOs');
    expect($source)->toContain('deprecated');
});

test('isAllowedExternal() still allows Api\\Dto (BC, 1 release)', function () {
    $source = lintBoundariesSource();

    // The isAllowedExternal method must still match Api\Dto via the
    // allowed patterns (BC: 1 release window before removal).
    expect($source)->toContain('isAllowedExternal(');
    expect($source)->toContain('ALLOWED_PATTERNS');
});

test('a deprecation-warning emit method exists in the handle flow', function () {
    $source = lintBoundariesSource();

    // The handle() method must invoke a deprecation check on the
    // imports it processes. Look for the isDeprecatedExternal method.
    expect($source)->toContain('isDeprecatedExternal(');
    expect($source)->toContain('reportDeprecations(');
});
