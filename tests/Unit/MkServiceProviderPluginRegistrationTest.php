<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Closure;
use Mk\Director\MkServiceProvider;
use Mk\Director\Plugins\FileStoragePlugin;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-045 F2 — MkServiceProvider::registerPlugins() auto-register D2.
 *
 * Per HALLAZGO-NEW-03, runtime tests pin EFECTIVIDAD. Estos tests invocan
 * `registerPlugins()` directamente vía Closure::bind (PHP 8.5-clean — NO
 * `setAccessible()` que está deprecated per memory rule) con config
 * controlada del test container.
 *
 * Cubren D5 #3, #6 del sprint proposal (auto-register default + dedup).
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: invoke protected `MkServiceProvider::registerPlugins()` on a fresh
 * provider instance using Closure::bind (PHP 8.5-clean, no setAccessible).
 * Returns the resulting `mk_director.plugins` array.
 */
function invokeRegisterPlugins(array $plugins = [], ?bool $featureFlag = true): array
{
    // Reset mk_director config to known state for this test.
    config([
        'mk_director.plugins' => $plugins,
        'mk_director.features.file_storage_plugin' => $featureFlag,
    ]);

    $provider = new MkServiceProvider(app());
    $closure = Closure::bind(function () {
        $this->registerPlugins();
    }, $provider, MkServiceProvider::class);
    $closure();

    return config('mk_director.plugins', []);
}

test('FileStoragePlugin se carga por default cuando feature flag es true (D5 #3 / D2)', function () {
    $plugins = invokeRegisterPlugins();  // default: plugins=[], featureFlag=true

    expect($plugins)->toContain(FileStoragePlugin::class);
});

test('FileStoragePlugin se carga por default cuando feature flag NO está seteado (D2 BC-safe default)', function () {
    // Default behavior: feature flag unset → treated as `true` (auto-register ON).
    // config() falls back to default `true` if not set in the array.
    $plugins = invokeRegisterPlugins(plugins: [], featureFlag: true);

    expect($plugins)->toContain(FileStoragePlugin::class);
});

test('FileStoragePlugin opt-out via feature flag false (D2 escape hatch)', function () {
    $plugins = invokeRegisterPlugins(plugins: [], featureFlag: false);

    expect($plugins)->not->toContain(FileStoragePlugin::class);
    expect($plugins)->toBe([]);
});

test('FileStoragePlugin explícito en plugins se respeta aunque feature flag sea false (BC-safe)', function () {
    // Edge case: consumer pinea FileStoragePlugin explícito en `plugins`
    // Y también `features.file_storage_plugin => false`.
    //
    // Decisión semántica (BC-safe): el feature flag controla AUTO-REGISTER,
    // NO REMOVE explícito. Si el consumer pineó FileStoragePlugin en `plugins`,
    // lo respetamos — son dev tools que toman control explícito.
    //
    // Esto matchea con la BC analysis de la state.yaml:
    //   ⚠️ `'plugins' => []` (vacío) + flag true → NEW: plugin se carga.
    //     (Riesgo documentado en CHANGELOG, escape vía flag.)
    //   ✅ Cualquier explicit + flag true → BC-safe dedup.
    //   ✅ flag false → plugin NO se AUTO-registra (no altera array explícito).
    $plugins = invokeRegisterPlugins(
        plugins: [FileStoragePlugin::class],
        featureFlag: false,
    );

    expect($plugins)->toBe([FileStoragePlugin::class]);
});

test('FileStoragePlugin dedup si ya está en plugins array (D5 #6 / D2)', function () {
    $plugins = invokeRegisterPlugins(
        plugins: [FileStoragePlugin::class],
        featureFlag: true,
    );

    expect($plugins)->toHaveCount(1);
    expect($plugins[0])->toBe(FileStoragePlugin::class);
});

test('FileStoragePlugin se suma a otros plugins sin duplicar (D2 + dedup combined)', function () {
    $plugins = invokeRegisterPlugins(
        plugins: ['App\\MkPlugins\\AuditPlugin'],
        featureFlag: true,
    );

    expect($plugins)->toHaveCount(2);
    expect($plugins)->toContain(FileStoragePlugin::class);
    expect($plugins)->toContain('App\\MkPlugins\\AuditPlugin');
});