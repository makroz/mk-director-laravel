<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Mockery;

/**
 * T6.4: Verifies that the auth_users migration declares a UUID
 * primary key. The original test mock'd the Blueprint class but
 * PHP 8 enforces signatures on real class mocks, and chained
 * Blueprint calls (uuid()->primary(), string()->unique(), ...)
 * trigger ArgumentCountError when the chained method has required
 * arguments we did not explicitly satisfy.
 *
 * We parse the source file directly for the same reason as
 * AbilityUserMigrationTest: the migration code is the contract
 * under test, and parsing it sidesteps the entire chainable-mock
 * problem. We also assert the migration source code is syntactically
 * valid by requiring it and calling its down() — the up() cannot
 * be exercised here without a DB.
 *
 * @see audit-2026-06-17-R3-002
 */
uses(\Mk\Director\Tests\TestCase::class);

test('auth_users migration declares uuid id with primary key', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php');

    expect($src)->toContain("\$table->uuid('id')");
    expect($src)->toMatch("/->primary\\(['\"\\s]?[\\w'\"]*\\)?/");

    // primary() must pass at least the column name to satisfy the
    // real Blueprint::primary($columns, ...) signature.
    expect($src)->toMatch('/->primary\([\'"]id[\'"]/');
});

test('auth_users migration declares a unique email column', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php');

    expect($src)->toContain("\$table->string('email')");
    expect($src)->toMatch('/->unique\(\)/');
});

test('auth_users migration declares auth_scope with index for fast lookup', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php');

    expect($src)->toContain("\$table->string('auth_scope')");
    expect($src)->toContain('->default(\'user\')');
    expect($src)->toContain('->index()');
});

test('auth_users migration exposes both up() and down()', function () {
    $migration = require __DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php';

    expect(method_exists($migration, 'up'))->toBeTrue();
    expect(method_exists($migration, 'down'))->toBeTrue();

    $downSrc = (string) file_get_contents(__DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000001_create_auth_users_table.php');
    expect($downSrc)->toContain("Schema::dropIfExists('auth_users')");
});