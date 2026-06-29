<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-032 (v1.8.0 MAJOR) — PAGINATION ENVELOPE (INTENCIÓN side).
 *
 * Per HALLAZGO-NEW-03, source-parsing alone is insufficient — this is the
 * INTENT side; runtime EFECTIVIDAD lives in
 * `tests/Feature/BaseControllerPaginationEnvelopeE2ETest.php` and the adapted
 * `BaseControllerSingleLevelEnvelopeE2ETest.php`.
 *
 * Spec:
 *   - `BaseController::sendResponse()` wraps `extractPaginationMetadata()` output
 *     under `__extraData.pagination` (NOT flat).
 *   - `ListManager::getExtraData()` returns the same grouped shape.
 *   - The helper `extractPaginationMetadata()` itself is unchanged — it still
 *     returns the 5 (LA) / 3 (Cursor) snake_case keys flat. The wrapping
 *     happens in the call sites.
 *   - No flag opt-in. No BC bridge. v1.7.x consumers that read
 *     `__extraData.last_page` flat MUST migrate to `__extraData.pagination.last_page`.
 *
 * @see R-PKG-032 (binding rule, rules_orchestration.md)
 * @see .makromania/projects/mk-director/openspec/changes/2026-06-29-r-pkg-032-pagination-envelope/
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg032(): string
{
    return dirname(__DIR__, 3);
}

function readSourceRPkg032(string $relativePath): string
{
    $fullPath = packageRootRPkg032() . '/' . $relativePath;
    expect(file_exists($fullPath))->toBeTrue("Source must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('R-PKG-032 — BaseController::sendResponse() wraps pagination under __extraData.pagination (INTENCIÓN)', function (): void {
    $baseController = readSourceRPkg032('src/Controllers/BaseController.php');

    test('sendResponse() wraps extractPaginationMetadata() output under "pagination" key (grouped envelope)', function () use ($baseController): void {
        // R-PKG-032: the merge that assembles $extra must group the pagination
        // metadata under a 'pagination' key, NOT pass it flat. We use simple
        // string contains (more robust than regex under PHP escape rules).
        expect($baseController)->toContain("array_merge(['pagination' => \$this->extractPaginationMetadata");
    });

    test('sendResponse() docblock documents the new pagination envelope shape', function () use ($baseController): void {
        // The docblock must show the grouped shape:
        //   __extraData: { pagination: { current_page, last_page, ... } }
        expect($baseController)->toContain('__extraData');
        expect($baseController)->toContain('"pagination"');
        expect($baseController)->toContain('current_page');
        expect($baseController)->toContain('last_page');
        expect($baseController)->toContain('has_more_pages');
    });

    test('sendResponse() docblock calls out R-PKG-032 as the change', function () use ($baseController): void {
        expect($baseController)->toContain('R-PKG-032');
        expect($baseController)->toContain('PAGINATION ENVELOPE');
    });

    test('extractPaginationMetadata() helper is RETAINED unchanged (low-level composable helper)', function () use ($baseController): void {
        // The helper stays composing the 5 LA keys + 3 Cursor keys flat.
        // The grouping happens in sendResponse(). This test pins no surprise
        // refactor inside the helper.
        expect($baseController)->toContain('protected function extractPaginationMetadata');
        expect($baseController)->toContain("'has_more_pages'");
        expect($baseController)->toContain('$paginator->hasMorePages()');
    });

    test('sendResponse() does NOT emit pagination keys flat at __extraData top-level (regression guard)', function () use ($baseController): void {
        // Defense-in-depth: ensure no future refactor flattens the grouping
        // back. The pattern would re-introduce v1.7.x flat shape.
        expect($baseController)->not->toContain("array_merge(\$this->extractPaginationMetadata");
    });
});

describe('R-PKG-032 — ListManager::getExtraData() returns pagination grouped (INTENCIÓN)', function (): void {
    $listManager = readSourceRPkg032('src/Managers/ListManager.php');

    test('ListManager::getExtraData() returns array with "pagination" sub-key (grouped)', function () use ($listManager): void {
        // The helper groups the 5 pagination keys under a "pagination" sub-array.
        expect($listManager)->toContain("['pagination' => [");
        expect($listManager)->toContain("'current_page'   =>");
    });

    test('ListManager::getExtraData() emits the 5 snake_case LengthAwarePaginator keys', function () use ($listManager): void {
        // Drift trazable — the helper must still emit the canonical 5 keys.
        expect($listManager)->toContain("'current_page'");
        expect($listManager)->toContain("'last_page'");
        expect($listManager)->toContain("'per_page'");
        expect($listManager)->toContain("'total'");
        expect($listManager)->toContain("'has_more_pages'");
    });

    test('ListManager::getExtraData() accepts an optional $extras parameter for custom keys', function () use ($listManager): void {
        // R-PKG-032 D5: custom keys (audit_checked, request_id, etc.) must
        // merge FLAT in __extraData, not inside the pagination group.
        // The new signature supports `$extras = []`.
        expect($listManager)->toContain('array $extras = []');
        expect($listManager)->toContain('public static function getExtraData(LengthAwarePaginator $paginator, array $extras = [])');
    });

    test('ListManager::getExtraData() docblock calls out R-PKG-032 grouping', function () use ($listManager): void {
        expect($listManager)->toContain('R-PKG-032');
        expect($listManager)->toContain('PAGINATION ENVELOPE');
    });
});

describe('R-PKG-032 — Cross-package consistency: BaseController and ListManager emit same grouped shape', function (): void {
    $baseController = readSourceRPkg032('src/Controllers/BaseController.php');
    $listManager    = readSourceRPkg032('src/Managers/ListManager.php');

    test('Both helpers group pagination metadata under "pagination" sub-key', function () use ($baseController, $listManager): void {
        // Drift trazable: the 2 helpers of the package must use the same
        // grouping (sprint R-PKG-031 PKG-NEW-18 closed the previous drift —
        // the 5-key contract is now grouped under "pagination" in both).

        // ListManager groups under 'pagination' (helper output).
        expect($listManager)->toContain("array_merge(\n            ['pagination' => [");

        // BaseController.sendResponse wraps under 'pagination' (envelope site).
        expect($baseController)->toContain("array_merge(['pagination' => \$this->extractPaginationMetadata");
    });
});
