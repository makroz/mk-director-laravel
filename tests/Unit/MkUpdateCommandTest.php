<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkUpdateCommand was added and registered successfully,
 * with the required update pipeline and safety audits.
 */
uses(MkLaravelTestCase::class);

function mkUpdateCommandPath(): string
{
    return __DIR__ . '/../../src/Console/Commands/MkUpdateCommand.php';
}

function mkUpdateCommandSource(): string
{
    $path = mkUpdateCommandPath();
    expect(file_exists($path))->toBeTrue("MkUpdateCommand.php must exist at $path");

    return (string) file_get_contents($path);
}

test('MkUpdateCommand is defined, has signature mk:update, and is registered in MkServiceProvider', function () {
    $source = mkUpdateCommandSource();
    expect($source)->not->toBeEmpty();

    expect($source)->toContain('class MkUpdateCommand extends Command');
    expect($source)->toContain("protected \$signature = 'mk:update");

    // Must be registered in the service provider.
    $provider = (string) file_get_contents(__DIR__ . '/../../src/MkServiceProvider.php');
    expect($provider)->toContain('MkUpdateCommand::class');
});

test('MkUpdateCommand implements the interactive database migration pipeline', function () {
    $source = mkUpdateCommandSource();
    
    expect($source)->toContain('function runDatabaseMigrationsPipeline(');
    expect($source)->toContain('function executeUuidMigration(');
    
    // Checks database state
    expect($source)->toContain("Schema::hasTable('auth_users')");
    expect($source)->toContain("getColumnInfo('auth_users', 'id')");
    
    // Asks user for confirmation and backup
    expect($source)->toContain('¿Tenés un backup completo y actualizado de tu base de datos?');
    expect($source)->toContain('¿Confirmás que querés proceder con la migración a UUID de auth_users.id?');
    
    // Performs ALTER TABLE transitions
    expect($source)->toContain('ALTER TABLE `auth_users` ADD COLUMN `id_uuid` CHAR(36)');
    expect($source)->toContain('ALTER TABLE `auth_users` DROP COLUMN `id`');
    expect($source)->toContain('ALTER TABLE `auth_users` ADD PRIMARY KEY (`id`)');
});

test('MkUpdateCommand implements codebase static audits', function () {
    $source = mkUpdateCommandSource();
    
    expect($source)->toContain('function auditCodebaseRisks(');
    
    // Scans for HasTenantScope without usesTenant (opt-in tenancy check)
    expect($source)->toContain('use HasTenantScope');
    expect($source)->toContain('$usesTenant');
    expect($source)->toContain('En v1.2+ el tenant es opt-in');
    
    // Scans for empty mk.ability route middleware registrations
    expect($source)->toContain('mk.ability:');
    expect($source)->toContain('Esto provocará un error HTTP 500');
});
