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

test('MkUpdateCommand implements dynamic version retrieval and interactive composer update (R-PKG-013)', function () {
    $source = mkUpdateCommandSource();
    expect($source)->not->toBeEmpty();

    // Retrieves version dynamically from InstalledVersions (no hardcoding)
    expect($source)->toContain('function getInstalledVersion(');
    expect($source)->toContain('InstalledVersions::isInstalled');
    expect($source)->toContain('InstalledVersions::getPrettyVersion');

    // Live Packagist API check
    expect($source)->toContain('use Illuminate\Support\Facades\Http;');
    expect($source)->toContain('https://repo.packagist.org/p2/makroz/director-laravel.json');

    // R-PKG-013: ahora devuelve TODAS las versiones superiores (incluyendo pre-releases),
    // ya no usa regex `/^v?\d+\.\d+\.\d+$/` que filtraba RCs.
    expect($source)->toContain('function getVersionsHigherThan(');
    expect($source)->toContain('function getVersionsHigherThan(string $currentVersion)');
    expect($source)->toContain('version_compare(');  // usa version_compare semver-aware
    expect($source)->toContain('dev-');  // filtra branches de desarrollo

    // La función antigua getLatestStableVersion YA NO EXISTE (fue reemplazada)
    expect($source)->not->toContain('function getLatestStableVersion(');
    expect($source)->not->toContain('function getLatestVersion(');

    // Integrates Symfony Process for running composer require con la versión elegida
    expect($source)->toContain('use Symfony\Component\Process\Process;');
    expect($source)->toContain('function runComposerUpdate(');
    expect($source)->toContain('function runComposerUpdate(string $targetVersion)');
    expect($source)->toContain("new Process(['composer', 'require'");

    // Warning about composer cache / CDN
    expect($source)->toContain('composer clear-cache');
    expect($source)->toContain('sigue siendo menor que la solicitada');
});

test('MkUpdateCommand presents interactive keyboard-navigable menu with RCs (R-PKG-013)', function () {
    $source = mkUpdateCommandSource();
    expect($source)->not->toBeEmpty();

    // Symfony/Laravel built-in choice() para navegación con flechas del teclado
    expect($source)->toContain('$this->choice(');
    expect($source)->toContain('↑↓ navegá');
    expect($source)->toContain('Enter para seleccionar');

    // Markers visuales para distinguir stables de pre-releases
    expect($source)->toContain('⭐');  // última estable
    expect($source)->toContain('🧪');  // pre-release (rc/alpha/beta)

    // Detección de pre-release en el regex
    expect($source)->toContain('/(rc|alpha|beta)/i');

    // NO debe pedir flags como --include-rc (Mario pidió UX default sin params)
    expect($source)->not->toContain('--include-rc');
    expect($source)->not->toContain('--channel=');
});

test('MkUpdateCommand uses version_compare semver-aware for pre-release ordering', function () {
    $source = mkUpdateCommandSource();

    // El filtro regex estricto de 3 dígitos (que ANTES ocultaba los RCs) NO debe existir más.
    // Usamos una aserción por substring sin escribir el regex literal completo:
    expect($source)->not->toContain('preg_match(\'/'.'^v?\\d+\\.\\d+\\.\\d+$/');

    // version_compare maneja -rc1, -beta correctamente
    // (1.6.0-rc2 > 1.6.0-rc1 > 1.6.0 > 1.5.0)
    expect($source)->toContain("version_compare(\$versionNorm, \$currentNorm, '>')");
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
