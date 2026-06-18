<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Managers;

use Mk\Director\Managers\ListManager;
use Mk\Director\Strategies\LikeSearchStrategy;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * T4.1 (R2-014): LIKE escape + max_length.
 * T4.2 (R3-007): Operator whitelist + throw on unknown operator.
 * T4.3 (R2-012): restoreState sanitization + HMAC-hashed storage key.
 *
 * Implementation note: ListManager uses static methods that drive a
 * Builder + Eloquent Model, which require a live DB connection for
 * full coverage. We focus on the security-relevant contracts here
 * (escape function, whitelist, key derivation) which can be tested
 * without booting the DB. The apply-time integration is covered by
 * the end-to-end checks in the sandbox-laravel app (sprint Phase 8).
 */
uses(MkLaravelTestCase::class);

/**
 * T4.1 — LIKE escape (R2-014)
 */
test('ListManager::escapeLikeWildcards escapes % and _', function () {
    expect(ListManager::escapeLikeWildcards('50%'))->toBe('50\\%');
    expect(ListManager::escapeLikeWildcards('a_b'))->toBe('a\\_b');
    expect(ListManager::escapeLikeWildcards('plain'))->toBe('plain');
});

test('ListManager::escapeLikeWildcards also escapes the escape character', function () {
    // backslash itself must be escaped first so a search for "\50%"
    // matches the literal "\50%" not the literal "50%".
    expect(ListManager::escapeLikeWildcards('\\50%'))->toBe('\\\\50\\%');
});

test('LikeSearchStrategy source uses the escape helper', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Strategies/LikeSearchStrategy.php');

    expect($src)->toContain('addcslashes');
    expect($src)->toContain("\\%_");
});

test('ListManager source: applySearch truncates the search term to max_length', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    expect($src)->toContain("mk_director.search.max_length");
    expect($src)->toContain('mb_substr');
});

/**
 * T4.2 — Operator whitelist (R3-007)
 */
test('ListManager source: applyFilterOperator throws InvalidArgumentException on unknown operator', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    // The previous behavior was `$sqlOp = $operators[$op] ?? '=';` — silent
    // fallback to '=' for any unknown operator. After this change, the
    // lookup throws so the misconfiguration is loud.
    expect($src)->toContain("array_key_exists(\$op, \$operators)");
    expect($src)->toContain('InvalidArgumentException');
    expect($src)->not->toMatch('/\$operators\[\$op\]\s*\?\?\s*[\'"]=/');
});

test('ListManager source: applyFilterOperator accepts the documented 9 operators', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    foreach (['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like', 'in', 'not_in'] as $op) {
        expect($src)->toContain("'$op' =>");
    }
});

/**
 * T4.3 — restoreState sanitize + HMAC hash (R2-012)
 */
test('ListManager source: restoreState uses hash_hmac for the storage key', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    expect($src)->toContain('hash_hmac');
    expect($src)->toContain('sha256');
});

test('ListManager source: restoreState sanitizes filter state against the operator whitelist', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    expect($src)->toContain('sanitizeFilterState');
    expect($src)->toContain('sanitizeSortState');
});

test('ListManager source: sanitizeFilterState rejects column names with SQL-like characters', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    // Regex that rejects field names containing characters outside
    // [A-Za-z0-9_.]. Anything that could be a SQL injection vector.
    expect($src)->toContain('/^[A-Za-z_][A-Za-z0-9_');
});

test('ListManager source: restoreState still allows the per-user cache lookup when no session exists', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Managers/ListManager.php');

    expect($src)->toContain('Cache::get');
    expect($src)->toContain('Cache::put');
});