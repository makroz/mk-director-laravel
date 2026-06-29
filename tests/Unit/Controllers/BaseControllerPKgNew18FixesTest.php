<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * PKG-NEW-18 source-parsing + e2e tests (RETO fase 12 feedback 2026-06-28).
 *
 * Source: `FEEDBACK-TO-MK-DIRECTOR-fase12.md` § PKG-NEW-18.
 *
 * What this sprint pines:
 *   - `BaseController::extractPaginationMetadata()` now emits the 5-key
 *     snake_case contract for `LengthAwarePaginator`:
 *     `current_page`, `last_page`, `per_page`, `total`, `has_more_pages`.
 *   - This brings the helper in line with `ListManager::getExtraData()`
 *     (which already emitted all 5 keys) and the canonical contract
 *     documented in the skill § R-PKG-024 + `@makroz/core`
 *     `MkListResponse<T>.__extraData` type.
 *
 * Pre-fix runtime symptom (RETO feedback):
 *   - `__extraData` returned only 4 keys; frontend infinite-scroll hooks
 *     that read `has_more_pages` saw `undefined` at runtime.
 *
 * This file pins INTENCIÓN (source-parsing) + EFECTIVIDAD (runtime
 * behavior on a real paginator) per HALLAZGO-NEW-03.
 *
 * Spec: R-PKG-031 PKG-NEW-18.
 *
 * @see BaseController::extractPaginationMetadata()
 */
uses(MkLaravelTestCase::class);

function readBaseControllerSourcePKgNew18(): string
{
    $fullPath = dirname(__DIR__, 3).'/src/Controllers/BaseController.php';
    expect(file_exists($fullPath))->toBeTrue("BaseController must exist at $fullPath");

    return file_get_contents($fullPath);
}

/**
 * Anonymous BaseController subclass — the class is abstract so we need a
 * concrete child to invoke the protected `extractPaginationMetadata()`.
 */
class PKgNew18TestController extends \Mk\Director\Controllers\BaseController
{
    public function callExtractPaginationMetadata($paginator): array
    {
        return $this->extractPaginationMetadata($paginator);
    }
}

// ──────────────────────────────────────────────────────────────────────
// INTENCIÓN side (HALLAZGO-NEW-03 source-parsing).
// ──────────────────────────────────────────────────────────────────────

describe('PKG-NEW-18 — BaseController::extractPaginationMetadata() emits 5-key snake_case contract', function (): void {
    $source = readBaseControllerSourcePKgNew18();

    test('helper declares the 5 keys in the @return phpdoc (contract pin)', function () use ($source): void {
        // The phpdoc now documents the 5-key contract including
        // has_more_pages. Drift trazable per R-G-032.

        expect($source)->toMatch('/@return array\{[^}]*has_more_pages\?:\s*bool/');
    });

    test('LengthAwarePaginator branch assigns all 5 keys (regression guard)', function () use ($source): void {
        // The fix added the 5th key. A future refactor that removes it
        // will be caught immediately.

        expect($source)->toContain("\$meta['current_page']");
        expect($source)->toContain("\$meta['last_page']");
        expect($source)->toContain("\$meta['per_page']");
        expect($source)->toContain("\$meta['total']");
        expect($source)->toContain("\$meta['has_more_pages']");
    });

    test('LengthAwarePaginator branch uses paginator->hasMorePages() to compute the value', function () use ($source): void {
        // The fix delegates to Laravel's `LengthAwarePaginator::hasMorePages()`
        // which is the canonical method for this calculation.

        expect($source)->toContain('$paginator->hasMorePages()');
    });

    test('PKG-NEW-18 reference is documented in source comments (drift trazable per R-G-032)', function () use ($source): void {
        // Drift trazable.

        expect($source)->toContain('PKG-NEW-18');
        expect($source)->toContain('R-PKG-031');
    });
});

// ──────────────────────────────────────────────────────────────────────
// EFECTIVIDAD side (HALLAZGO-NEW-03 runtime with real paginator).
// ──────────────────────────────────────────────────────────────────────

describe('PKG-NEW-18 — runtime EFECTIVIDAD on real LengthAwarePaginator', function (): void {
    test('LengthAwarePaginator: has_more_pages=true when current page < last page', function () {
        $controller = new PKgNew18TestController();

        $items = new Collection([
            (object) ['id' => 1],
            (object) ['id' => 2],
            (object) ['id' => 3],
        ]);
        // 3 items, 1 per page, page 1 → 3 pages total → has_more_pages=true.
        $paginator = new LengthAwarePaginator($items, 3, 1, 1);

        $meta = $controller->callExtractPaginationMetadata($paginator);

        expect($meta)->toHaveKeys(['current_page', 'last_page', 'per_page', 'total', 'has_more_pages']);
        expect($meta['current_page'])->toBe(1);
        expect($meta['last_page'])->toBe(3);
        expect($meta['per_page'])->toBe(1);
        expect($meta['total'])->toBe(3);
        expect($meta['has_more_pages'])->toBeTrue();
    });

    test('LengthAwarePaginator: has_more_pages=false on the last page', function () {
        $controller = new PKgNew18TestController();

        $items = new Collection([(object) ['id' => 1]]);
        // 3 items, 1 per page, page 3 (the last) → has_more_pages=false.
        $paginator = new LengthAwarePaginator($items, 3, 1, 3);

        $meta = $controller->callExtractPaginationMetadata($paginator);

        expect($meta['current_page'])->toBe(3);
        expect($meta['last_page'])->toBe(3);
        expect($meta['has_more_pages'])->toBeFalse();
    });

    test('LengthAwarePaginator: has_more_pages=false when only 1 page total', function () {
        $controller = new PKgNew18TestController();

        $items = new Collection([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);
        // 2 items, 10 per page, page 1 → fits in 1 page → has_more_pages=false.
        $paginator = new LengthAwarePaginator($items, 2, 10, 1);

        $meta = $controller->callExtractPaginationMetadata($paginator);

        expect($meta['last_page'])->toBe(1);
        expect($meta['has_more_pages'])->toBeFalse();
    });

    test('CursorPaginator: has_more_pages key is NOT emitted (only LengthAwarePaginator has it)', function () {
        // CursorPaginator does not have hasMorePages() — the contract
        // for cursors is next_cursor / prev_cursor. This test pins that
        // we don't accidentally add has_more_pages to the cursor branch.

        $controller = new PKgNew18TestController();

        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new CursorPaginator($items, 1);

        $meta = $controller->callExtractPaginationMetadata($paginator);

        expect($meta)->toHaveKeys(['per_page', 'next_cursor', 'prev_cursor']);
        expect($meta)->not->toHaveKey('has_more_pages');
    });

    test('5-key shape matches ListManager::getExtraData() shape (no drift between the 2 helpers)', function () {
        // The 2 helpers in the package (BaseController::extractPaginationMetadata
        // + ListManager::getExtraData) should emit the same 5 keys for the
        // same LengthAwarePaginator. This is the contract the skill + type
        // documentation describe.

        $controller = new PKgNew18TestController();

        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new LengthAwarePaginator($items, 5, 2, 1);

        $fromBaseController = $controller->callExtractPaginationMetadata($paginator);
        $fromListManager = \Mk\Director\Managers\ListManager::getExtraData($paginator);

        // Both must have the 5 keys.
        foreach (['current_page', 'last_page', 'per_page', 'total', 'has_more_pages'] as $key) {
            expect($fromBaseController)->toHaveKey($key);
            expect($fromListManager)->toHaveKey($key);
            // Values should be equal for the same paginator.
            expect($fromBaseController[$key])->toBe($fromListManager[$key]);
        }
    });
});
