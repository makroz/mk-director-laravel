<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mk\Director\Contracts\MkPluginInterface;
use Mk\Director\Managers\PluginManager;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-045 F3 — PluginManager::auditRequirements() D3 audit fix.
 *
 * Per HALLAZGO-NEW-03, runtime tests pin EFECTIVIDAD. Estos tests ejercen
 * `auditRequirements()` con un fake plugin que requiere
 * `plugins_config.file_storage.fields` (mismo patrón que FileStoragePlugin),
 * validando que:
 *   - key missing → 'error' finding (no info)
 *   - key empty (e.g. `[]`) → 'info' finding (NO warning — F8-B04 fix)
 *   - key set → no finding
 *
 * Cubren D5 #10 (F8-B04 regression) del sprint proposal.
 */
uses(MkLaravelTestCase::class);

/**
 * Build a PluginManager with a fake plugin that requires
 * `plugins_config.file_storage.fields` (mismo patrón que FileStoragePlugin).
 */
function buildPluginManagerWithFakePlugin(): PluginManager
{
    $fakePlugin = new class implements MkPluginInterface {
        public function boot(): void {}
        public function getRequirements(): array
        {
            return [
                'required_config' => ['plugins_config.file_storage.fields'],
                'fields_added' => ['photo'],
            ];
        }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($fakePlugin)]]);
    app()->instance(get_class($fakePlugin), $fakePlugin);

    return new PluginManager();
}

test('audit con fields vacío no dispara warning — solo info (F8-B04 regression guard)', function () {
    $manager = buildPluginManagerWithFakePlugin();

    $findings = $manager->auditRequirements(
        mkConfig: ['plugins_config' => ['file_storage' => ['fields' => []]]],
        fillable: ['photo'],
    );

    // D3: key existe pero vacío → info, NO warning.
    $warnings = array_values(array_filter($findings, fn($f) => $f['type'] === 'warning'));
    expect($warnings)->toBeEmpty();

    // Y debe haber al menos un info finding.
    $infos = array_values(array_filter($findings, fn($f) => $f['type'] === 'info'));
    expect($infos)->not->toBeEmpty();
});

test('audit con key missing dispara error (no info ni warning)', function () {
    $manager = buildPluginManagerWithFakePlugin();

    $findings = $manager->auditRequirements(
        mkConfig: [],  // ← sin 'plugins_config.file_storage.fields'
        fillable: ['photo'],
    );

    $errors = array_values(array_filter($findings, fn($f) => $f['type'] === 'error'));
    expect($errors)->not->toBeEmpty();

    // Sin info findings cuando la key está missing — solo error.
    $infos = array_values(array_filter($findings, fn($f) => $f['type'] === 'info'));
    expect($infos)->toBeEmpty();

    $warnings = array_values(array_filter($findings, fn($f) => $f['type'] === 'warning'));
    expect($warnings)->toBeEmpty();
});

test('audit con key set correctamente dispara ok sin finding', function () {
    $manager = buildPluginManagerWithFakePlugin();

    $findings = $manager->auditRequirements(
        mkConfig: ['plugins_config' => ['file_storage' => ['fields' => ['photo']]]],
        fillable: ['photo'],
    );

    expect($findings)->toBeEmpty();
});

test('audit con fields vacío retorna info con mensaje claro sobre plugin sin fields', function () {
    $manager = buildPluginManagerWithFakePlugin();

    $findings = $manager->auditRequirements(
        mkConfig: ['plugins_config' => ['file_storage' => ['fields' => []]]],
        fillable: ['photo'],
    );

    $infoMessages = array_map(
        fn($f) => $f['message'],
        array_values(array_filter($findings, fn($f) => $f['type'] === 'info'))
    );
    expect($infoMessages)->toContain(
        "La llave 'plugins_config.file_storage.fields' existe pero está vacía — el plugin está registrado sin fields configurados."
    );
});

test('audit con key missing retorna error sobre llave faltante', function () {
    $manager = buildPluginManagerWithFakePlugin();

    $findings = $manager->auditRequirements(
        mkConfig: [],
        fillable: ['photo'],
    );

    $errorMessages = array_map(
        fn($f) => $f['message'],
        array_values(array_filter($findings, fn($f) => $f['type'] === 'error'))
    );
    expect($errorMessages)->toContain(
        "Falta la llave de configuración 'plugins_config.file_storage.fields' en \$mkConfig."
    );
});

test('audit con D1 mapeo explícito (assoc) trata value como el fillable esperado', function () {
    // D1: fields_added puede ser assoc ['photo' => 'photo_path'] post-rename.
    // El audit check debe usar el VALUE (column name) para matchear fillable,
    // no el KEY (request field name).

    $fakePlugin = new class implements MkPluginInterface {
        public function boot(): void {}
        public function getRequirements(): array
        {
            return [
                'required_config' => ['plugins_config.file_storage.fields'],
                'fields_added' => ['photo' => 'photo_path'],  // D1 assoc
            ];
        }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($fakePlugin)]]);
    app()->instance(get_class($fakePlugin), $fakePlugin);
    $manager = new PluginManager();

    // fillable tiene 'photo_path' (el column name post-D1) → no error.
    $findings = $manager->auditRequirements(
        mkConfig: ['plugins_config' => ['file_storage' => ['fields' => ['photo' => 'photo_path']]]],
        fillable: ['photo_path'],
    );

    expect($findings)->toBeEmpty();
});