<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * LAR-09 (2026-07-03 audit, MEDIUM) — `BaseController::sendError()`
 * EFECTIVIDAD runtime test (lado INTENCIÓN en
 * `tests/Unit/Controllers/BaseControllerSendErrorEnvelopeTest.php`).
 *
 * Per HALLAZGO-NEW-03, source-parsing alone is insufficient — runtime
 * tests prove the canonical shape is actually emitted. The package's
 * `MkLaravelTestCase` does not bind a full Laravel ResponseFactory
 * (the package is a library, not an app). We bind a minimal
 * `JsonResponse`-returning factory on the test container so
 * `response()->json($payload, $code)` returns a real `JsonResponse`
 * whose `getData(true)` we can decode and assert on.
 *
 * @see 04-mk-director-laravel.md#LAR-09 (2026-07-03 audit)
 * @see R-PKG-024 (single-level envelope)
 * @see R-PKG-044 (unified auth envelope)
 */
uses(MkLaravelTestCase::class);

/**
 * Anonymous BaseController subclass — the class is abstract so we need
 * a concrete one to invoke the protected sendError() method.
 */
class Lar09SendErrorTestController extends \Mk\Director\Controllers\BaseController
{
    public function callSendError(string $message, array $errors = [], int $code = 404, ?string $errorCode = null): JsonResponse
    {
        return $this->sendError($message, $errors, $code, $errorCode);
    }
}

beforeEach(function () {
    // Bind a minimal ResponseFactory that returns JsonResponse so sendError()
    // can use response()->json($payload, $code) without a full Laravel app.
    $container = Container::getInstance();
    $factory = \Mockery::mock(\Illuminate\Contracts\Routing\ResponseFactory::class);
    $factory->shouldReceive('json')->andReturnUsing(function ($data = [], $status = 200, array $headers = [], $options = 0) {
        return new JsonResponse($data, $status, $headers);
    });
    $container->instance('Illuminate\Contracts\Routing\ResponseFactory', $factory);
});

describe('LAR-09 EFECTIVIDAD — BaseController::sendError() runtime emits canonical envelope', function (): void {
    test('sendError() emits data:null in the JSON body', function () {
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError('Boom', [], 500);
        $body = $response->getData(true);

        expect($body)->toHaveKey('data');
        expect($body['data'])->toBeNull();
    });

    test('sendError() emits __extraData.code with the machine-readable code', function () {
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError('Boom', [], 500, 'ERR_INTERNAL');
        $body = $response->getData(true);

        expect($body)->toHaveKey('__extraData');
        expect($body['__extraData'])->toHaveKey('code');
        expect($body['__extraData']['code'])->toBe('ERR_INTERNAL');
    });

    test('sendError() synthesizes a default code when caller does not pass one', function () {
        // Default behavior: derive a sensible machine-readable code from
        // the HTTP status (e.g. ERR_500 → ERR_INTERNAL, 422 → ERR_VALIDATION,
        // 401 → ERR_UNAUTHENTICATED, 403 → ERR_FORBIDDEN, 404 → ERR_NOT_FOUND).
        // Pinning the shape, not every exact mapping — frontend just needs
        // SOME non-empty string.
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError('Boom', [], 500);
        $body = $response->getData(true);

        expect($body['__extraData'])->toHaveKey('code');
        expect($body['__extraData']['code'])->toBeString();
        expect($body['__extraData']['code'])->not->toBe('');
    });

    test('sendError() emits validation errors under __extraData.errors (not top-level)', function () {
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError(
            'Invalid',
            ['email' => ['required']],
            422,
            'ERR_VALIDATION',
        );
        $body = $response->getData(true);

        expect($body)->not->toHaveKey('errors');                  // ← top-level GONE
        expect($body['__extraData'])->toHaveKey('errors');        // ← nested under extra
        expect($body['__extraData']['errors'])->toBe(['email' => ['required']]);
    });

    test('sendError() emits success=false and the message at top-level', function () {
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError('Bad creds', [], 401, 'ERR_UNAUTHENTICATED');
        $body = $response->getData(true);

        expect($body['success'])->toBeFalse();
        expect($body['message'])->toBe('Bad creds');
    });

    test('sendError() emits debugMsg as array (not the legacy debugMsgs [])', function () {
        $controller = new Lar09SendErrorTestController();
        $response = $controller->callSendError('Boom', [], 500);
        $body = $response->getData(true);

        expect($body)->toHaveKey('debugMsg');
        expect($body['debugMsg'])->toBeArray();
    });
});