<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Managers;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Mk\Director\Managers\ListManager;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-032 (v1.8.0 MAJOR) — ListManager::getExtraData() emits grouped pagination.
 *
 * Hybrid: source-parsing INTENCIÓN + runtime EFECTIVIDAD on a real paginator
 * (per HALLAZGO-NEW-03).
 *
 * Spec:
 *   - `getExtraData($paginator)` returns `['pagination' => [5 keys], ...$extras]`.
 *   - The 5 inner keys are `current_page`, `last_page`, `per_page`, `total`,
 *     `has_more_pages` — same snake_case contract as `extractPaginationMetadata()`.
 *   - Custom keys passed via `$extras` are merged FLAT (not inside `pagination`).
 *   - Caller-passed `'pagination' => [...]` overrides the helper's grouping
 *     entirely (merge order: pagination defaults, then $extras wins).
 *
 * @see R-PKG-032 (binding rule, rules_orchestration.md)
 * @see \Mk\Director\Controllers\BaseController::extractPaginationMetadata()
 */
uses(MkLaravelTestCase::class);

describe('R-PKG-032 — ListManager::getExtraData() emits grouped pagination (runtime EFECTIVIDAD)', function (): void {
    test('returns "pagination" sub-object with 5 snake_case keys for LengthAwarePaginator', function () {
        $items = new Collection([
            (object) ['id' => 1],
            (object) ['id' => 2],
        ]);
        $paginator = new LengthAwarePaginator($items, 7, 1, 1);

        $extra = ListManager::getExtraData($paginator);

        // Grouped under 'pagination' (R-PKG-032).
        expect($extra)->toHaveKey('pagination');
        expect($extra)->not->toHaveKey('current_page'); // ❌ not flat anymore
        expect($extra)->not->toHaveKey('last_page');
        expect($extra)->not->toHaveKey('per_page');
        expect($extra)->not->toHaveKey('total');
        expect($extra)->not->toHaveKey('has_more_pages');

        // Inner pagination object has the 5 snake_case keys.
        expect($extra['pagination'])->toHaveKeys([
            'current_page', 'last_page', 'per_page', 'total', 'has_more_pages',
        ]);
        expect($extra['pagination']['current_page'])->toBe(1);
        expect($extra['pagination']['last_page'])->toBe(7);
        expect($extra['pagination']['per_page'])->toBe(1);
        expect($extra['pagination']['total'])->toBe(7);
        expect($extra['pagination']['has_more_pages'])->toBeTrue();
    });

    test('merges custom $extras flat at top-level (not inside pagination)', function () {
        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new LengthAwarePaginator($items, 3, 1, 1);

        $extra = ListManager::getExtraData($paginator, [
            'audit_checked' => true,
            'request_id'    => 'req-abc-123',
        ]);

        // Grouped pagination preserved.
        expect($extra)->toHaveKey('pagination');
        expect($extra['pagination'])->toHaveKeys(['current_page', 'last_page', 'per_page', 'total', 'has_more_pages']);

        // Custom keys live FLAT, NOT inside pagination.
        expect($extra)->toHaveKey('audit_checked');
        expect($extra)->toHaveKey('request_id');
        expect($extra['audit_checked'])->toBeTrue();
        expect($extra['request_id'])->toBe('req-abc-123');
        expect($extra['pagination'])->not->toHaveKey('audit_checked');
        expect($extra['pagination'])->not->toHaveKey('request_id');
    });

    test('caller-supplied "pagination" key overrides helper grouping entirely (array_merge semantics: caller wins on key collision)', function () {
        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new LengthAwarePaginator($items, 5, 2, 1);

        // Caller passes a custom pagination object entirely.
        $extra = ListManager::getExtraData($paginator, [
            'pagination' => ['total' => 999, 'custom_key' => 'foo'],
        ]);

        // Caller's pagination object REPLACES the helper's default grouping
        // (array_merge semantics: on key collision, the LAST value wins —
        // the caller's `pagination` sub-object entirely replaces the helper's
        // default pagination sub-object). This matches BaseController's
        // sendResponse() merge order (pagination defaults first, then $extra wins).
        expect($extra)->toHaveKey('pagination');
        expect($extra['pagination'])->toBe([
            'total' => 999,
            'custom_key' => 'foo',
        ]);
        // Helper defaults are NOT merged in — replaced entirely.
        expect($extra['pagination'])->not->toHaveKey('current_page');
        expect($extra['pagination'])->not->toHaveKey('last_page');
        expect($extra['pagination'])->not->toHaveKey('per_page');
        expect($extra['pagination'])->not->toHaveKey('has_more_pages');
    });

    test('NO camelCase keys anywhere (regression guard)', function () {
        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new LengthAwarePaginator($items, 1, 10, 1);

        $extra = ListManager::getExtraData($paginator);

        // The helper previously had camelCase (`lastPage`, `perPage`, etc).
        // R-PKG-024 (v1.7.0) removed those. R-PKG-032 (v1.8.0) keeps them out.
        expect($extra)->not->toHaveKey('lastPage');
        expect($extra)->not->toHaveKey('perPage');
        expect($extra)->not->toHaveKey('hasMorePages');
        expect($extra['pagination'])->not->toHaveKey('lastPage');
        expect($extra['pagination'])->not->toHaveKey('perPage');
        expect($extra['pagination'])->not->toHaveKey('hasMorePages');
    });
});

describe('R-PKG-032 — Cross-helper consistency: ListManager::getExtraData matches BaseController::extractPaginationMetadata', function (): void {
    test('inner pagination object of getExtraData() equals output of extractPaginationMetadata()', function () {
        // The 2 helpers in the package must emit identical pagination metadata
        // for the same paginator (R-PKG-031 PKG-NEW-18 closed the previous
        // 4-key vs 5-key drift; R-PKG-032 keeps the 5-key contract grouped
        // identically). Drift trazable per R-G-032.

        $items = new Collection([(object) ['id' => 1]]);
        $paginator = new LengthAwarePaginator($items, 12, 3, 2);

        // From ListManager (grouped).
        $fromListManager = ListManager::getExtraData($paginator);
        // From BaseController (extracted via reflection of the helper).
        $controller = new class extends \Mk\Director\Controllers\BaseController {
            public function callExtract(\Illuminate\Pagination\AbstractPaginator|\Illuminate\Pagination\CursorPaginator $p): array
            {
                return $this->extractPaginationMetadata($p);
            }
        };
        $fromBaseController = $controller->callExtract($paginator);

        // Both must expose the 5 keys with equal values.
        foreach (['current_page', 'last_page', 'per_page', 'total', 'has_more_pages'] as $key) {
            expect($fromListManager['pagination'])->toHaveKey($key);
            expect($fromBaseController)->toHaveKey($key);
            expect($fromListManager['pagination'][$key])->toBe($fromBaseController[$key]);
        }
    });
});
