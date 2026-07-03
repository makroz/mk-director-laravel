<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-044 v2.0.0 — CRUDSmart::autoTransform is a thin wrapper @deprecated
 * that delegates to BaseController::autoTransform (the canonical SSoT).
 *
 * Two paths must coexist:
 *
 *   1. **Canonical (v2.0.0+)**: per-model `public $apiResource` declaration.
 *      When SmartController does NOT pine `'resource' => FooResource::class`
 *      in `$mkConfig`, the wrapper delegates to `parent::autoTransform($data)`
 *      which uses `property_exists($data, 'apiResource')` + recursive array
 *      handling (HALLAZGO-NEW-FASE15-07).
 *
 *   2. **BC legacy (pre-v2.0.0)**: per-controller `mkConfig['resource']`.
 *      When SmartController pines `'resource' => FooResource::class` in
 *      `$mkConfig`, the wrapper uses that resource class (preserves BC
 *      for external consumers not yet migrated to per-model `$apiResource`).
 *
 * Why thin wrapper instead of hard delete?
 *   - Pattern from R-PKG-038 FASE17-02: package with 2+ implementations of
 *     the same contract with distinct shapes → SSoT + @deprecated thin wrapper
 *     (NEVER global handler that transforms all outputs).
 *   - RETO 2.0.0+ uses canonical (scaffolder pinea `public $apiResource` since R-PKG-035).
 *   - External consumers may still depend on `mkConfig['resource']` (BC).
 *
 * Spec:
 *   - `CRUDSmart::autoTransform` MUST contain a `@deprecated` JSDoc pointing
 *     to `parent::autoTransform()` (BaseController).
 *   - `CRUDSmart::autoTransform` MUST delegate to `parent::autoTransform($data)`
 *     when `$this->getResource()` returns null (canonical path).
 *   - `CRUDSmart::autoTransform` MUST keep the legacy BC path
 *     (`new $resourceClass($data)` / `::collection()`) when `getResource()`
 *     returns a valid class string.
 *
 * Source-parsing only (HALLAZGO-NEW-03: pine INTENCIÓN). EFECTIVIDAD covered
 * by e2e tests in apps/sandbox-laravel + RETO fase 18+ (per-model $apiResource
 * path).
 *
 * @see R-PKG-044 (binding rule, v2.0.0 architectural cleanup)
 * @see R-PKG-038 FASE17-02 (thin wrapper pattern)
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg044(): string
{
    return dirname(__DIR__, 3);
}

function readSourceRPkg044(string $relativePath): string
{
    $fullPath = packageRootRPkg044() . '/' . $relativePath;
    expect(file_exists($fullPath))->toBeTrue("Source must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('R-PKG-044 — CRUDSmart::autoTransform thin wrapper delegation to BaseController (canonical SSoT)', function (): void {
    $crudSmart = readSourceRPkg044('src/Traits/CRUDSmart.php');
    $baseController = readSourceRPkg044('src/Controllers/BaseController.php');

    test('CRUDSmart::autoTransform has @deprecated JSDoc pointing to parent::autoTransform', function () use ($crudSmart): void {
        // Pattern from R-PKG-038 FASE17-02: thin wrapper @deprecated
        expect($crudSmart)->toContain('@deprecated');
        expect($crudSmart)->toContain('parent::autoTransform()');
        expect($crudSmart)->toContain('BaseController::autoTransform');
    });

    test('CRUDSmart::autoTransform delegates to parent::autoTransform (canonical SSoT)', function () use ($crudSmart): void {
        // The wrapper MUST call parent::autoTransform($data) when canonical path.
        expect($crudSmart)->toContain('return parent::autoTransform($data)');
    });

    test('CRUDSmart::autoTransform keeps BC legacy path when $mkConfig[\'resource\'] is set', function () use ($crudSmart): void {
        // The wrapper MUST preserve the legacy per-controller resource lookup
        // for external consumers that haven't migrated to per-model $apiResource.
        expect($crudSmart)->toContain('$this->getResource()');
        expect($crudSmart)->toContain('new $resourceClass($data)');
        expect($crudSmart)->toContain('$resourceClass::collection($data)');
    });

    test('BaseController::autoTransform (canonical) handles per-model $apiResource + recursive arrays', function () use ($baseController): void {
        // The canonical SSoT must:
        // 1. Check property_exists() (HALLAZGO-NEW-FASE15-07 — Eloquent __get
        //    magic intercepts protected but property_exists inspects declaration).
        // 2. Check $data->apiResource !== null (the abstract Models\Model::$apiResource = null default).
        // 3. Handle recursive arrays with nested Models (login response shape).
        expect($baseController)->toContain('property_exists($data, \'apiResource\')');
        expect($baseController)->toContain('$data->apiResource !== null');
        expect($baseController)->toContain('is_array($data)');
    });

    test('CRUDSmart::autoTransform Migration JSDoc mentions per-model $apiResource path', function () use ($crudSmart): void {
        // Migration guide in JSDoc must point consumers to per-model $apiResource.
        expect($crudSmart)->toContain('public $apiResource');
        expect($crudSmart)->toContain('MIGRATION');
    });
});