<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mockery;

/**
 * T6.5: Verifies that the role_user migration declares the right
 * schema (uuid user_id, configurable user_type default, FK on role_id).
 *
 * The previous version used Mockery::mock(Blueprint::class) which
 * enforces Laravel's real signatures — chained Blueprint calls
 * (foreignId(...)->constrained(...), string(...)->default(...))
 * trigger ArgumentCountError when the chained method requires args
 * we did not satisfy. We parse the source file directly instead
 * (same approach as AbilityUserMigrationTest / AuthUserMigrationTest).
 *
 * @see audit-2026-06-17-R3-003
 */
uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

function roleUserMigrationSource(): string
{
    $path = __DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000004_create_role_user_table.php';
    expect(file_exists($path))->toBeTrue();

    return (string) file_get_contents($path);
}

test('role_user migration declares foreignId(role_id) with cascade delete', function () {
    $src = roleUserMigrationSource();

    expect($src)->toContain("\$table->foreignId('role_id')");
    expect($src)->toContain("->constrained('roles')");
    expect($src)->toContain('->cascadeOnDelete()');
});

test('role_user migration declares user_id as UUID', function () {
    $src = roleUserMigrationSource();

    expect($src)->toContain("\$table->uuid('user_id')");
    // Must NOT use foreignId('user_id') — that emits BIGINT UNSIGNED.
    expect($src)->not->toContain("\$table->foreignId('user_id')");
});

test('role_user migration declares user_type with default from config', function () {
    $src = roleUserMigrationSource();

    expect($src)->toContain("\$table->string('user_type')");
    expect($src)->toContain('mk_director.auth.default_user_type');
    expect($src)->toContain('->default(');
});

test('role_user migration declares a unique composite index on (role_id, user_id, user_type)', function () {
    $src = roleUserMigrationSource();

    expect($src)->toContain("\$table->unique(['role_id', 'user_id', 'user_type'], 'role_user_unique')");
});

test('role_user migration exposes both up() and down()', function () {
    $migration = require __DIR__ . '/../../src/Auth/Database/Migrations/2026_06_10_000004_create_role_user_table.php';

    expect(method_exists($migration, 'up'))->toBeTrue();
    expect(method_exists($migration, 'down'))->toBeTrue();

    $src = roleUserMigrationSource();
    expect($src)->toContain("Schema::dropIfExists('role_user')");
});