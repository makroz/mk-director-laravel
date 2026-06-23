<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that MkUpdateCommand implements the dynamic version check,
 * Symfony Process execution, and codebase risk audits.
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

test('MkUpdateCommand is registered in MkServiceProvider', function () {
    // Must be registered in the service provider.
    $provider = (string) file_get_contents(__DIR__ . '/../../src/MkServiceProvider.php');
    expect($provider)->toContain('MkUpdateCommand::class');
});

test('MkUpdateCommand implements dynamic version retrieval and composer update', function () {
    $source = mkUpdateCommandSource();
    expect($source)->not->toBeEmpty();

    // Retrieves version dynamically from InstalledVersions (no hardcoding)
    expect($source)->toContain('function getInstalledVersion(');
    expect($source)->toContain('InstalledVersions::isInstalled');
    expect($source)->toContain('InstalledVersions::getPrettyVersion');

    // Integrates Symfony Process for running composer update
    expect($source)->toContain('use Symfony\Component\Process\Process;');
    expect($source)->toContain('function runComposerUpdate(');
    expect($source)->toContain("new Process(['composer', 'update', 'makroz/director-laravel'])");
});

test('MkUpdateCommand implements interactive database migration pipeline and codebase audits', function () {
    $source = mkUpdateCommandSource();
    
    expect($source)->toContain('function runDatabaseMigrationsPipeline(');
    expect($source)->toContain('function executeUuidMigration(');
    
    // Checks database state
    expect($source)->toContain("Schema::hasTable('auth_users')");
    expect($source)->toContain("getColumnInfo('auth_users', 'id')");
    
    // Asks user for confirmation and backup
    expect($source)->toContain('¿Tenés un backup completo y actualizado de tu base de datos?');
    expect($source)->toContain('¿Confirmás que querés proceder con la migración a UUID de auth_users.id?');
    
    // Codebase static audits
    expect($source)->toContain('function auditCodebaseRisks(');
    expect($source)->toContain('use HasTenantScope');
    expect($source)->toContain('$usesTenant');
    expect($source)->toContain('mk.ability:');
});
