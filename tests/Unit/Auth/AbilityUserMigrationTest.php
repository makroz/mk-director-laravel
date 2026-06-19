<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies the published `ability_user` migration:
 *  - declares foreignId('ability_id')->constrained('abilities')
 *  - declares uuid('user_id') (matches auth_users UUID primary key)
 *  - declares unique composite index on (ability_id, user_id, user_type)
 *  - has a working down() that drops the table
 *  - returns a Migration anonymous class with up() and down()
 *
 * Implementation note: we parse the migration source code rather than
 * executing Schema::create() because the Schema facade requires a real
 * DatabaseManager binding (db.connection) which is not available in unit
 * tests. Parsing the source keeps the test self-contained and asserts the
 * actual contract of the migration file on disk. This is the same pattern
 * used by T2.5 for the AuthUser docblock test.
 *
 * @see audit-2026-06-17-R1-002
 */
uses(MkLaravelTestCase::class);

function abilityUserMigrationPath(): string
{
    return __DIR__ . '/../../../src/Auth/Database/Migrations/2026_06_10_000007_create_ability_user_table.php';
}

function abilityUserMigrationSource(): string
{
    $path = abilityUserMigrationPath();
    expect(file_exists($path))->toBeTrue("ability_user migration must exist at $path");

    return (string) file_get_contents($path);
}

test('ability_user migration declares foreignId(ability_id) with constrained FK', function () {
    $src = abilityUserMigrationSource();

    expect($src)->toContain("\$table->foreignId('ability_id')");
    expect($src)->toContain("->constrained('abilities')");
    expect($src)->toContain('->cascadeOnDelete()');
});

test('ability_user migration declares user_id as UUID (matches auth_users migration)', function () {
    $src = abilityUserMigrationSource();

    expect($src)->toContain("\$table->uuid('user_id')");
    // Must NOT use foreignId('user_id') — that emits BIGINT UNSIGNED
    // and breaks the relationship with auth_users.id (UUID).
    expect($src)->not->toContain("\$table->foreignId('user_id')");
});

test('ability_user migration declares unique composite index on (ability_id, user_id, user_type)', function () {
    $src = abilityUserMigrationSource();

    expect($src)->toContain("\$table->unique(['ability_id', 'user_id', 'user_type'], 'ability_user_unique')");
});

test('ability_user migration declares an index on (user_id, user_type) for lookup performance', function () {
    $src = abilityUserMigrationSource();

    expect($src)->toContain("\$table->index(['user_id', 'user_type'])");
});

test('ability_user migration defines down() that drops the table', function () {
    $migration = require abilityUserMigrationPath();

    expect(method_exists($migration, 'down'))->toBeTrue();
    expect(method_exists($migration, 'up'))->toBeTrue();

    // Verify down() drops the right table by reading the source.
    $src = abilityUserMigrationSource();
    expect($src)->toContain("Schema::dropIfExists('ability_user')");
});

test('ability_user migration is positioned AFTER the abilities migration (filename ordering)', function () {
    $migrationPath = abilityUserMigrationPath();
    $filename = basename($migrationPath);

    // Filename must sort after 000003 (abilities) and 000005 (ability_role)
    // because it depends on both via foreign keys.
    expect($filename)->toMatch('/^2026_06_10_00000[6-9]_/');

    // And it must exist on disk.
    expect(file_exists($migrationPath))->toBeTrue();
});