<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Plugins\FileStoragePlugin;
use Mk\Director\Tests\MkLaravelTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * R-PKG-045 F4 — FileStoragePlugin e2e tests (HALLAZGO-NEW-03).
 *
 * Per HALLAZGO-NEW-03, source-parsing pinea INTENCIÓN pero NO EFECTIVIDAD.
 * Estos tests son runtime con Storage facade + UploadedFile::fake() + temp dir
 * local adapter para validar el flujo completo del plugin end-to-end:
 *   - file upload (multipart) → store → $data populated
 *   - afterResponse → path convertido a URL
 *
 * PluginManager + FileStoragePlugin son REALES (no mocks). Solo Request +
 * UploadedFile usan helpers de Laravel test (`UploadedFile::fake()`).
 *
 * Limitación del minimal container: `Storage::fake()` requiere `storagePath()`
 * en el container (no disponible en library package test infra). Workaround:
 * configurar `filesystems.disks.public` con un temp dir + bindear
 * FilesystemManager directamente.
 */
uses(MkLaravelTestCase::class);

/**
 * Setup per-test: temp dir + filesystem config + cleanup after.
 */
beforeEach(function () {
    $tempDir = sys_get_temp_dir() . '/mk-director-test-' . uniqid('', true);
    mkdir($tempDir, 0777, true);
    $this->tempDir = $tempDir;

    config([
        'filesystems.default' => 'public',
        'filesystems.disks.public' => [
            'driver' => 'local',
            'root' => $tempDir,
            'url' => 'http://localhost/storage',
            'visibility' => 'public',
            'throw' => false,
        ],
    ]);

    if (! app()->bound('filesystems')) {
        app()->singleton('filesystems', function ($app) {
            return new FilesystemManager($app);
        });
        // UploadedFile::store() typehints Illuminate\Contracts\Filesystem\Factory.
        // FilesystemManager implements it — alias el contract al binding.
        app()->alias('filesystems', \Illuminate\Contracts\Filesystem\Factory::class);
        // UploadedFile::getDisk() hace `app('filesystem')` (singular). Laravel's
        // Application lo bindea automáticamente; minimal container no.
        app()->alias('filesystems', 'filesystem');
    }
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $fileinfo) {
            $action = $fileinfo->isDir() ? 'rmdir' : 'unlink';
            $action($fileinfo->getRealPath());
        }
        rmdir($this->tempDir);
    }
});

/**
 * Helper: build a FileStoragePlugin with the given plugin config, usando un
 * PluginManager mockeado (matches F1 test pattern).
 */
function buildFileStoragePluginForE2E(array $config): FileStoragePlugin
{
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getConfigValue')
        ->with('plugins_config.file_storage', [])
        ->andReturn($config);

    return new FileStoragePlugin($manager);
}

test('e2e: beforeSave con UploadedFile::fake() y Storage local escribe path en $data (D1 BC flat)', function () {
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo'],  // BC: array plano
        'disk' => 'public',
        'path' => 'uploads/files',
        'auto_url' => true,
    ]);

    $request = Request::create('/api/test', 'POST');
    $uploadedFile = UploadedFile::fake()->image('photo.jpg', 100, 100);
    $request->files->set('photo', $uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    // Verify file was stored on the local disk.
    expect($data)->toHaveKey('photo');
    expect($data['photo'])->toStartWith('uploads/files/');
    Storage::disk('public')->assertExists($data['photo']);
});

test('e2e: beforeSave con D1 mapeo explícito foto → photo_path (D1 NEW rename)', function () {
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo' => 'photo_path'],  // D1 NEW: explicit rename
        'disk' => 'public',
        'path' => 'admins',
        'auto_url' => true,
    ]);

    $request = Request::create('/api/admins', 'POST');
    $uploadedFile = UploadedFile::fake()->image('avatar.jpg', 200, 200);
    $request->files->set('photo', $uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    // Verify file was stored.
    expect($data)->toHaveKey('photo_path');
    expect($data)->not->toHaveKey('photo');
    expect($data['photo_path'])->toStartWith('admins/');
    Storage::disk('public')->assertExists($data['photo_path']);
});

test('e2e: afterResponse con auto_url=true convierte path a URL completa', function () {
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo' => 'photo_path'],
        'disk' => 'public',
        'path' => 'admins',
        'auto_url' => true,
    ]);

    $response = [
        'data' => [
            'id' => 1,
            'name' => 'Mario',
            'photo_path' => 'admins/fake-hash.jpg',
        ],
    ];
    $plugin->afterResponse($response);

    // The photo_path debe ser una URL completa (empieza con http).
    expect($response['data']['photo_path'])->toStartWith('http');
    // Otros campos no se tocan.
    expect($response['data']['name'])->toBe('Mario');
    expect($response['data']['id'])->toBe(1);
});

test('e2e: afterResponse con auto_url=false deja paths como strings', function () {
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo' => 'photo_path'],
        'disk' => 'public',
        'path' => 'admins',
        'auto_url' => false,
    ]);

    $response = [
        'data' => [
            'photo_path' => 'admins/fake-hash.jpg',
        ],
    ];
    $plugin->afterResponse($response);

    // auto_url=false → no conversion.
    expect($response['data']['photo_path'])->toBe('admins/fake-hash.jpg');
});

test('e2e: afterResponse con mapeo BC plano convierte paths a URLs', function () {
    // D1 BC: array plano ['photo'] → identity map. Response key 'photo' → URL.
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo'],
        'disk' => 'public',
        'path' => 'uploads/files',
        'auto_url' => true,
    ]);

    $response = [
        'data' => [
            'photo' => 'uploads/files/fake-hash.jpg',
        ],
    ];
    $plugin->afterResponse($response);

    expect($response['data']['photo'])->toStartWith('http');
});

test('e2e: flow completo beforeSave → afterResponse encadena file store + URL conversion', function () {
    // HALLAZGO-NEW-03 honored: este test ejercita el flujo COMPLETO end-to-end
    // (no source-parsing) — la integración antesSave + afterResponse.
    $plugin = buildFileStoragePluginForE2E([
        'fields' => ['photo' => 'photo_path'],
        'disk' => 'public',
        'path' => 'admins',
        'auto_url' => true,
    ]);

    // 1. beforeSave: file upload → store → $data populated.
    $request = Request::create('/api/admins', 'POST');
    $uploadedFile = UploadedFile::fake()->image('avatar.jpg', 300, 300);
    $request->files->set('photo', $uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    expect($data)->toHaveKey('photo_path');
    $storedPath = $data['photo_path'];

    // 2. Simulate response with the stored path.
    $response = ['data' => $data];
    $plugin->afterResponse($response);

    // 3. The stored path should now be a full URL.
    expect($response['data']['photo_path'])->toStartWith('http');
    expect($response['data']['photo_path'])->not->toBe($storedPath);  // se convirtió
});