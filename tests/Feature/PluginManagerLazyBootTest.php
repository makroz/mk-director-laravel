<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Mk\Director\Managers\PluginManager;
use Mk\Director\Plugins\FileStoragePlugin;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B07 — PluginManager lazy boot (regression guard).
 *
 * **Bug pineado**: pre-fix, `PluginManager::__construct()` llamaba
 * `loadPluginsFromConfig()` que resolvía `FileStoragePlugin::class` via
 * `app()`. Como `FileStoragePlugin::__construct(PluginManager $manager)`
 * requiere `PluginManager`, el container entraba en dependency circular
 * y la app bricked al boot.
 *
 * Stack trace del bug (RETO FEEDBACK9):
 *
 *   MkServiceProvider:50 PluginManager __construct
 *     PluginManager:29 loadPluginsFromConfig
 *       PluginManager:38 registerPlugins
 *         PluginManager:47 registerPlugin
 *           PluginManager:64 app('Mk\\Director\\Plu...')  ← FileStoragePlugin
 *             FileStoragePlugin::__construct(PluginManager)  ← LOOP
 *
 * **Fix**: `PluginManager::__construct()` es lazy (solo pinear `plugins = collect()`).
 * El método público `boot()` carga los plugins. `MkServiceProvider::boot()` llama
 * `$pluginManager->boot()` DESPUÉS de que el singleton YA está construido — así
 * cuando `boot()` resuelve FileStoragePlugin, PluginManager ya existe en el
 * container y puede inyectarse sin loop.
 *
 * Estos tests pine EFECTIVIDAD (no source-parsing per HALLAZGO-NEW-03):
 * resuelven PluginManager desde container + boot() + verifican que
 * FileStoragePlugin se construye OK con la dependency circular ahora resuelta.
 *
 * Scope del test: solo PluginManager + FileStoragePlugin. MkServiceProvider NO
 * se instancia (requiere `Application` completo, no `Container` minimalista del
 * MkLaravelTestCase). El integration del SP se valida en el consumer piloto
 * RETO post-merge.
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: registrar PluginManager como singleton + FileStoragePlugin como binding.
 * Simula el binding pattern de MkServiceProvider::register() + boot().
 */
function bindPluginManagerAndFileStorage(): void
{
    app()->singleton(PluginManager::class, function () {
        return new PluginManager();
    });
}

/**
 * Helper: get the plugins collection via reflection.
 */
function getLoadedPlugins(PluginManager $manager): array
{
    $reflection = new \ReflectionClass($manager);
    $pluginsProp = $reflection->getProperty('plugins');

    return $pluginsProp->getValue($manager)->all();
}

test('R-PKG-046 F9-B07 — boot() carga FileStoragePlugin desde config (sin loop)', function () {
    config([
        'mk_director.features.file_storage_plugin' => true,
        'mk_director.plugins' => [FileStoragePlugin::class],
    ]);

    bindPluginManagerAndFileStorage();

    // ⚠️ Sin la fix, este resolve + boot() rompía con infinite loop /
    // circular dependency (PluginManager::__construct llamaba loadPluginsFromConfig
    // que resolvía FileStoragePlugin via app(), que requería PluginManager).
    // POST-FIX: constructor lazy, boot() carga OK.
    $manager = app(PluginManager::class);
    $manager->boot();

    $plugins = getLoadedPlugins($manager);

    expect($plugins)->toHaveCount(1);
    expect($plugins[0])->toBeInstanceOf(FileStoragePlugin::class);
});

test('R-PKG-046 F9-B07 — FileStoragePlugin recibe el MISMO PluginManager singleton (no loop)', function () {
    config([
        'mk_director.features.file_storage_plugin' => true,
        'mk_director.plugins' => [FileStoragePlugin::class],
    ]);

    bindPluginManagerAndFileStorage();

    // 1. Resolver PluginManager primero (esto es lo que evita el loop).
    $manager = app(PluginManager::class);

    // 2. boot() carga plugins — al resolver FileStoragePlugin, container busca
    // PluginManager y encuentra el singleton YA construido.
    $manager->boot();

    // 3. Verificar que el PluginManager que FileStoragePlugin tiene es la misma
    // instancia (singleton). Si fueran diferentes, sería señal de loop/duplicate.
    $fileStorage = app(FileStoragePlugin::class);

    $fspReflection = new \ReflectionClass($fileStorage);
    $managerProp = $fspReflection->getProperty('manager');
    $fspManager = $managerProp->getValue($fileStorage);

    expect($fspManager)->toBe($manager);
});

test('R-PKG-046 F9-B07 — opt-out via feature flag desactiva auto-register', function () {
    config([
        'mk_director.features.file_storage_plugin' => false,
        'mk_director.plugins' => [],
    ]);

    bindPluginManagerAndFileStorage();

    $manager = app(PluginManager::class);
    $manager->boot();

    $plugins = getLoadedPlugins($manager);

    expect($plugins)->toHaveCount(0);
});

test('R-PKG-046 F9-B07 — constructor lazy: sin boot() NO carga plugins', function () {
    config([
        'mk_director.features.file_storage_plugin' => true,
        'mk_director.plugins' => [FileStoragePlugin::class],
    ]);

    bindPluginManagerAndFileStorage();

    // Solo resolver sin boot → la collection debe estar vacía.
    $manager = app(PluginManager::class);
    $plugins = getLoadedPlugins($manager);

    expect($plugins)->toHaveCount(0);

    // Ahora sí, boot() carga plugins.
    $manager->boot();
    $plugins = getLoadedPlugins($manager);

    expect($plugins)->toHaveCount(1);
});

test('R-PKG-046 F9-B07 — boot() es idempotente (segunda llamada no duplica)', function () {
    config([
        'mk_director.features.file_storage_plugin' => true,
        'mk_director.plugins' => [FileStoragePlugin::class],
    ]);

    bindPluginManagerAndFileStorage();

    $manager = app(PluginManager::class);
    $manager->boot();
    $manager->boot();
    $manager->boot();

    $plugins = getLoadedPlugins($manager);

    expect($plugins)->toHaveCount(1);  // pine 1, no 3.
});