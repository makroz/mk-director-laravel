<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK (bulk) — `CRUDSmart::store()` detects an array payload and performs
 * a transactional bulk insert (pairs with the frontend
 * `useMkCrud().createMany(items[])`). All-or-nothing: any failing item rolls
 * back the batch.
 *
 * The package does not boot a full Laravel app for CRUD in unit tests
 * (see MkLaravelTestCase docblock), so the contract is pinned by parsing the
 * trait source — same strategy as CRUDSmartTenantIsolationTest.
 */
uses(MkLaravelTestCase::class);

function crudSmartBulkSource(): string
{
    $path = __DIR__.'/../../src/Traits/CRUDSmart.php';
    expect(file_exists($path))->toBeTrue("CRUDSmart.php must exist at $path");

    return (string) file_get_contents($path);
}

test('store() short-circuits to storeMany when the payload is a bulk list', function () {
    $src = crudSmartBulkSource();

    expect($src)->toContain('if ($this->isBulkPayload($input)) {');
    expect($src)->toContain('return $this->storeMany($request, $input);');

    // The bulk branch must run BEFORE the single-create path.
    $bulkPos = strpos($src, '$this->isBulkPayload($input)');
    $singleCreatePos = strpos($src, '$modelClass::create($input)');
    expect($bulkPos)->not->toBeFalse();
    expect($singleCreatePos)->not->toBeFalse();
    expect($bulkPos)->toBeLessThan($singleCreatePos);
});

test('isBulkPayload() uses array_is_list and rejects assoc/single objects', function () {
    $src = crudSmartBulkSource();

    expect($src)->toContain('protected function isBulkPayload(array $input): bool');
    expect($src)->toContain('array_is_list($input)');
});

test('storeMany() wraps the inserts in a single DB transaction', function () {
    $src = crudSmartBulkSource();

    expect($src)->toContain('protected function storeMany(Request $request, array $items)');
    expect($src)->toContain('DB::transaction(');

    // Each item must still go through the per-record pipeline (service +
    // plugins + DTO validation + fillable filter) so invariants hold.
    $body = substr($src, (int) strpos($src, 'function storeMany('));
    $body = substr($body, 0, (int) strpos($body, "\n    /**")); // until next docblock
    expect($body)->toContain('applyDTOValidation(');
    expect($body)->toContain('fireBeforeSave(');
    expect($body)->toContain('::create($data)');
});
