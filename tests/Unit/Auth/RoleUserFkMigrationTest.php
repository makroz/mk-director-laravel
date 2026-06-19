<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that the 1.2.2-hardening sprint shipped a migration that adds
 * the missing `role_user.user_id → auth_users.id` foreign key (R3-014).
 *
 * Implementation note: source-parsing the migration. Reasons:
 *  1. Migrations need a real DB connection to test end-to-end. The
 *     package does not include a sandbox app, and `tests/Feature/`
 *     migrations tests would require a full Laravel kernel + a SQLite
 *     file. Source-parsing is the unit-test level of abstraction that
 *     matches sprint `4r-fixes` precedent.
 *  2. The fix is a STRING contract: the migration MUST reference
 *     `auth_users` and MUST use `cascadeOnDelete()`. Pinning those
 *     strings is what catches a regression.
 *
 * @see audit-2026-06-17-R3-014
 */
uses(MkLaravelTestCase::class);

function roleUserFkMigrationPath(): string
{
    // The hardening migration was added in 1.2.2. Filename includes the
    // date prefix 2026_06_18 so the migration order is deterministic.
    $candidates = glob(__DIR__ . '/../../../src/Auth/Database/Migrations/*add_fk_role_user*.php');
    if (empty($candidates)) {
        return '';
    }
    return (string) $candidates[0];
}

function roleUserFkMigrationSource(): string
{
    $path = roleUserFkMigrationPath();
    expect($path)->not->toBeEmpty('add_fk_role_user migration must exist in src/Auth/Database/Migrations/');
    expect(file_exists($path))->toBeTrue("Migration file must exist at $path");

    return (string) file_get_contents($path);
}

test('R3-014 migration exists and declares up()/down() with try/catch in down()', function () {
    $source = roleUserFkMigrationSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('public function up(): void');
    expect($source)->toContain('public function down(): void');
    // try/catch in down() is the BC safety net for installs where the
    // FK does not exist yet (idempotent rollback).
    expect($source)->toMatch('/try\s*\{/');
    expect($source)->toContain('catch');
    expect($source)->toContain('dropForeign');
});

test('R3-014 migration declares FK user_id → auth_users.id with cascadeOnDelete', function () {
    $source = roleUserFkMigrationSource();
    expect($source)->not->toBeEmpty();

    // The FK must reference the `id` column of the `auth_users` table
    // (UUID primary key) and use cascadeOnDelete() so deleting an
    // auth_users row also drops the role_user rows referencing it.
    expect($source)->toContain("->foreign('user_id')");
    expect($source)->toContain("->references('id')");
    expect($source)->toContain("->on('auth_users')");
    expect($source)->toContain('->cascadeOnDelete()');
});

test('R3-014 migration skips silently if role_user table does not exist (defensive)', function () {
    $source = roleUserFkMigrationSource();
    expect($source)->not->toBeEmpty();

    // Consumer apps may not have run the package's auth migrations yet
    // (e.g. they use a different User table). The up() must NOT crash
    // on a missing `role_user` table.
    expect($source)->toContain("Schema::hasTable('role_user')");
    expect($source)->toContain('return');
});
