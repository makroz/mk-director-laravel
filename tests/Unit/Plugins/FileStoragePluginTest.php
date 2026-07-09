<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Plugins;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mockery;
use Mockery\MockInterface;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Plugins\FileStoragePlugin;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-045 F1 — FileStoragePlugin::beforeSave() D1 mapeo explícito.
 *
 * Per HALLAZGO-NEW-03 (R-PKG-020), source-parsing alone es anti-pattern —
 * pinea INTENCIÓN, NO EFECTIVIDAD. Estos 4 tests son runtime: ejercen el
 * código real con Request + UploadedFile mockeados vía Mockery para validar
 * que el mapeo D1 funciona end-to-end.
 *
 * Cubren D5 #1, #2, #4, #5 del sprint proposal. D5 #3/#6/#7-10 viven en
 * otros archivos (T2.3 auto-register, T3.2 audit regression, T4.1 e2e).
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: build a FileStoragePlugin with a mocked PluginManager that returns
 * the given plugin config from `getConfigValue('plugins_config.file_storage', [])`.
 */
function buildFileStoragePlugin(array $config): FileStoragePlugin
{
    /** @var MockInterface&PluginManager $manager */
    $manager = Mockery::mock(PluginManager::class);
    $manager->shouldReceive('getConfigValue')
        ->with('plugins_config.file_storage', [])
        ->andReturn($config);

    return new FileStoragePlugin($manager);
}

test('beforeSave con file válido escribe path en data (D5 #1)', function () {
    $plugin = buildFileStoragePlugin([
        'fields' => ['photo'],
        'disk' => 'public',
        'path' => 'uploads/files',
    ]);

    /** @var MockInterface&UploadedFile $uploadedFile */
    $uploadedFile = Mockery::mock(UploadedFile::class);
    $uploadedFile->shouldReceive('store')
        ->with('uploads/files', 'public')
        ->once()
        ->andReturn('uploads/files/abc123.jpg');

    /** @var MockInterface&Request $request */
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('hasFile')->with('photo')->andReturn(true);
    $request->shouldReceive('file')->with('photo')->andReturn($uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    expect($data)->toBe(['photo' => 'uploads/files/abc123.jpg']);
});

test('beforeSave sin file no modifica data (D5 #2)', function () {
    $plugin = buildFileStoragePlugin([
        'fields' => ['photo'],
        'disk' => 'public',
        'path' => 'uploads/files',
    ]);

    /** @var MockInterface&Request $request */
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('hasFile')->with('photo')->andReturn(false);
    // `file()` MUST NOT be called when hasFile() returns false — Mockery
    // will throw if the plugin tries. That's the regression guard.

    $data = ['existing' => 'value'];
    $plugin->beforeSave($request, $data, 'create');

    expect($data)->toBe(['existing' => 'value']);
});

test('beforeSave con mapeo explícito field→column rename (D5 #4 / D1 NEW)', function () {
    $plugin = buildFileStoragePlugin([
        'fields' => ['photo' => 'photo_path'],  // NEW: explicit rename
        'disk' => 'public',
        'path' => 'admins',
    ]);

    /** @var MockInterface&UploadedFile $uploadedFile */
    $uploadedFile = Mockery::mock(UploadedFile::class);
    $uploadedFile->shouldReceive('store')
        ->with('admins', 'public')
        ->once()
        ->andReturn('admins/xyz789.jpg');

    /** @var MockInterface&Request $request */
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('hasFile')->with('photo')->andReturn(true);  // request field = 'photo'
    $request->shouldReceive('file')->with('photo')->andReturn($uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    // D1: writes to COLUMN name 'photo_path', NOT request field 'photo'.
    expect($data)->toBe(['photo_path' => 'admins/xyz789.jpg']);
    expect($data)->not->toHaveKey('photo');
});

test('beforeSave con array plano auto-normaliza a identity map (D5 #5 / D1 BC)', function () {
    $plugin = buildFileStoragePlugin([
        'fields' => ['photo'],  // BC: flat array → identity map ['photo' => 'photo']
        'disk' => 'public',
        'path' => 'uploads/files',
    ]);

    /** @var MockInterface&UploadedFile $uploadedFile */
    $uploadedFile = Mockery::mock(UploadedFile::class);
    $uploadedFile->shouldReceive('store')
        ->with('uploads/files', 'public')
        ->once()
        ->andReturn('uploads/files/bc.jpg');

    /** @var MockInterface&Request $request */
    $request = Mockery::mock(Request::class);
    $request->shouldReceive('hasFile')->with('photo')->andReturn(true);  // identity: 'photo' === 'photo'
    $request->shouldReceive('file')->with('photo')->andReturn($uploadedFile);

    $data = [];
    $plugin->beforeSave($request, $data, 'create');

    // BC: array plano se auto-normaliza como identity map. Same behavior pre-R-PKG-045.
    expect($data)->toBe(['photo' => 'uploads/files/bc.jpg']);
});