<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Mk\Director\Controllers\BaseController;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE pineado (EFECTIVIDAD side).
 *
 * Per HALLAZGO-NEW-03, source-parsing alone is insufficient — runtime tests
 * prove that the canonical shape is actually emitted. This file verifies:
 *
 *   1. sendResponse() with a LengthAwarePaginator emits:
 *      { success: true, message: '', data: [items], __extraData: { current_page, last_page, per_page, total }, debugMsg: [] }
 *      — NO `data: { data: [...], links, meta }` nesting.
 *
 *   2. sendResponse() with a CursorPaginator emits:
 *      { success: true, data: [items], __extraData: { per_page, next_cursor, prev_cursor }, debugMsg: [] }
 *
 *   3. sendResponse() with a plain Collection emits:
 *      { success: true, data: [items], debugMsg: [] } — NO __extraData.
 *
 *   4. sendResponse() with a single Model emits:
 *      { success: true, data: {resource}, debugMsg: [] } — NO __extraData.
 *
 *   5. sendResponse() with a custom $extra emits:
 *      { success: true, data: {...}, __extraData: {...caller extras...}, debugMsg: [] }
 *      — top-level, not nested.
 *
 * The package's `MkLaravelTestCase` does not bind a full Laravel ResponseFactory
 * (the package is a library, not an app). We bind a minimal `JsonResponse`-returning
 * factory on the test container so `response()->json($payload, $code)` returns
 * a real `JsonResponse` whose `getContent()` we can decode and assert on.
 *
 * @see BaseControllerSingleLevelEnvelopeTest for the source-parsing INTENCIÓN side.
 * @see R-PKG-024 (binding rule, rules_orchestration.md)
 */
uses(MkLaravelTestCase::class);

/**
 * Anonymous BaseController subclass — the class is abstract so we need a concrete
 * child to invoke the protected sendResponse() method.
 */
class SingleLevelEnvelopeTestController extends BaseController
{
    public function callSendResponse($result, $message = '', $code = 200, array $extra = [])
    {
        return $this->sendResponse($result, $message, $code, $extra);
    }

    public function callExtractPaginationMetadata($paginator): array
    {
        return $this->extractPaginationMetadata($paginator);
    }
}

beforeEach(function () {
    // Bind a minimal ResponseFactory that returns JsonResponse so sendResponse()
    // can use response()->json($payload, $code) without a full Laravel app.
    // We use Mockery to mock the interface (ResponseFactory has 10+ methods,
    // mocking is simpler than implementing the whole interface).
    $container = Container::getInstance();
    $factory = \Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
    $factory->shouldReceive('json')->andReturnUsing(function ($data = [], $status = 200, array $headers = [], $options = 0) {
        return new JsonResponse($data, $status, $headers);
    });
    $container->instance('Illuminate\Contracts\Routing\ResponseFactory', $factory);

    // Reset config to defaults (no debug, no extra flags from other tests).
    config([
        'mk_director.debug' => false,
        'mk_director.response' => [],
    ]);
});

describe('R-PKG-024 EFECTIVIDAD — sendResponse() emits single-level envelope for paginator', function (): void {
    test('LengthAwarePaginator: data is array of items, __extraData has pagination metadata top-level (no data.data)', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $items = new Collection([new \stdClass(), new \stdClass()]);
        $paginator = new LengthAwarePaginator($items, 100, 20, 1);

        $response = $controller->callSendResponse($paginator, 'Lista de items', 200);

        expect($response->getStatusCode())->toBe(200);

        $body = json_decode($response->getContent(), true);

        // Single-level envelope — `data` is an array of items, NOT a paginator object.
        expect($body['data'])->toBeArray();
        expect($body['data'])->not->toHaveKey('data'); // ❌ no nested data.data
        expect($body['data'])->not->toHaveKey('links');
        expect($body['data'])->not->toHaveKey('meta');

        // __extraData is TOP-LEVEL (sibling of data), NOT nested inside data.
        expect($body)->toHaveKey('__extraData');
        expect($body['data'])->not->toHaveKey('__extraData');

        // Pagination metadata is snake_case (frontend useMkInfiniteList consumes these).
        expect($body['__extraData'])->toHaveKey('current_page');
        expect($body['__extraData'])->toHaveKey('last_page');
        expect($body['__extraData'])->toHaveKey('per_page');
        expect($body['__extraData'])->toHaveKey('total');
        expect($body['__extraData']['current_page'])->toBe(1);
        expect($body['__extraData']['last_page'])->toBe(5); // ceil(100/20)
        expect($body['__extraData']['per_page'])->toBe(20);
        expect($body['__extraData']['total'])->toBe(100);

        // No camelCase keys (legacy ListManager::getExtraData had `lastPage` etc).
        expect($body['__extraData'])->not->toHaveKey('lastPage');
        expect($body['__extraData'])->not->toHaveKey('perPage');
        expect($body['__extraData'])->not->toHaveKey('hasMorePages');
    });

    test('CursorPaginator: data is array of items, __extraData has cursor metadata top-level (no data.data)', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $items = new Collection([new \stdClass()]);
        $paginator = new CursorPaginator($items, 20);

        $response = $controller->callSendResponse($paginator, '', 200);
        $body = json_decode($response->getContent(), true);

        expect($body['data'])->toBeArray();
        expect($body['data'])->not->toHaveKey('data');
        expect($body)->toHaveKey('__extraData');

        // CursorPaginator emits per_page + cursors (NOT current_page / last_page / total).
        expect($body['__extraData'])->toHaveKey('per_page');
        expect($body['__extraData'])->toHaveKey('next_cursor');
        expect($body['__extraData'])->toHaveKey('prev_cursor');
        expect($body['__extraData'])->not->toHaveKey('current_page');
        expect($body['__extraData'])->not->toHaveKey('last_page');
    });

    test('caller-supplied $extra wins on key conflict with pagination metadata (merge order: pagination defaults, then caller)', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $items = new Collection([new \stdClass()]);
        $paginator = new LengthAwarePaginator($items, 100, 20, 1);

        // Caller passes a custom total that should win.
        $response = $controller->callSendResponse($paginator, '', 200, [
            'total' => 999, // caller's value wins
            'custom_field' => 'caller_value', // new field preserved
        ]);
        $body = json_decode($response->getContent(), true);

        expect($body['__extraData']['total'])->toBe(999); // caller wins
        expect($body['__extraData']['custom_field'])->toBe('caller_value'); // caller adds
        // Pagination defaults still present where caller didn't override.
        expect($body['__extraData']['current_page'])->toBe(1);
        expect($body['__extraData']['last_page'])->toBe(5);
        expect($body['__extraData']['per_page'])->toBe(20);
    });
});

describe('R-PKG-024 EFECTIVIDAD — sendResponse() emits single-level envelope for non-paginator payloads', function (): void {
    test('Plain Collection (no paginator): data is array of items, no __extraData when no $extra', function () {
        $controller = new SingleLevelEnvelopeTestController();
        $items = new Collection([new \stdClass(), new \stdClass()]);

        $response = $controller->callSendResponse($items, '', 200);
        $body = json_decode($response->getContent(), true);

        expect($body['data'])->toBeArray();
        expect($body['data'])->toHaveCount(2);
        expect($body)->not->toHaveKey('__extraData');
    });

    test('Caller $extra for non-paginator payload: __extraData is top-level (not nested in data)', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $response = $controller->callSendResponse(
            ['key' => 'value'],
            'OK',
            200,
            ['audit_checked' => true, 'request_id' => 'abc-123']
        );
        $body = json_decode($response->getContent(), true);

        expect($body['success'])->toBeTrue();
        expect($body['data'])->toBe(['key' => 'value']);
        expect($body['__extraData'])->toBe([
            'audit_checked' => true,
            'request_id' => 'abc-123',
        ]);
        // No data.data.
        expect($body['data'])->not->toHaveKey('data');
        expect($body['data'])->not->toHaveKey('__extraData');
    });

    test('Scalar payload: data is scalar value, no data.data', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $response = $controller->callSendResponse('simple-string-value', 'OK', 200);
        $body = json_decode($response->getContent(), true);

        expect($body['success'])->toBeTrue();
        expect($body['data'])->toBe('simple-string-value');
        expect($body['data'])->not->toHaveKey('data');
        expect($body)->not->toHaveKey('__extraData');
    });
});

describe('R-PKG-024 EFECTIVIDAD — extractPaginationMetadata() helper emits snake_case keys', function (): void {
    test('LengthAwarePaginator extracts snake_case pagination keys (current_page / last_page / per_page / total)', function () {
        $controller = new SingleLevelEnvelopeTestController();

        $paginator = new LengthAwarePaginator(new Collection([new \stdClass()]), 100, 20, 3);

        $meta = $controller->callExtractPaginationMetadata($paginator);

        expect($meta)->toHaveKey('current_page');
        expect($meta)->toHaveKey('last_page');
        expect($meta)->toHaveKey('per_page');
        expect($meta)->toHaveKey('total');
        expect($meta['current_page'])->toBe(3);
        expect($meta['last_page'])->toBe(5);
        expect($meta['per_page'])->toBe(20);
        expect($meta['total'])->toBe(100);

        // NO camelCase (legacy ListManager::getExtraData had `lastPage` etc).
        expect($meta)->not->toHaveKey('lastPage');
        expect($meta)->not->toHaveKey('perPage');
        expect($meta)->not->toHaveKey('hasMorePages');
    });
});