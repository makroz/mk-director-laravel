<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mk\Director\Middleware\MkAuthMiddleware;
use Mockery;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('middleware returns json response on unauthenticated API request', function () {
    $middleware = new MkAuthMiddleware();
    $request = Request::create('/api/v1/users', 'GET');
    $request->headers->set('Accept', 'application/json');

    Auth::shouldReceive('guard')->andReturnSelf();
    Auth::shouldReceive('check')->andReturn(false);

    $response = $middleware->handle($request, function () {});

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(401);

    $data = json_decode($response->getContent(), true);
    expect($data)->toHaveKey('success', false);
    expect($data)->toHaveKey('message', 'Unauthenticated.');
});
