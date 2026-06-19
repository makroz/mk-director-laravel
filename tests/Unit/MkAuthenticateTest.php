<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mockery;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
});

test('throws AuthenticationException when user is unauthenticated', function () {
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $middleware = new MkAuthenticate($resolver);
    
    $request = Request::create('/test', 'GET');
    
    Auth::shouldReceive('guard')
        ->once()
        ->with('admin')
        ->andReturnSelf();
        
    Auth::shouldReceive('user')
        ->once()
        ->andReturn(null);

    expect(fn () => $middleware->handle($request, fn () => null, 'admin'))
        ->toThrow(AuthenticationException::class);
});
