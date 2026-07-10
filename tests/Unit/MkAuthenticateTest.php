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
        ->with('admin')
        ->andReturnSelf();

    Auth::shouldReceive('user')
        ->andReturn(null);

    // R-PKG-046 F9-B11: cuando $user es null, el middleware ahora también
    // intenta con `Auth::guard()` (default, scope-agnostic) para detectar
    // tokens de OTRO scope. Mockeamos que el default también retorna null
    // (genuinamente no autenticado). Mockery: `withNoArgs()` para capturar
    // la llamada sin argumentos.
    Auth::shouldReceive('guard')
        ->withNoArgs()
        ->andReturnSelf();

    expect(fn () => $middleware->handle($request, fn () => null, 'admin'))
        ->toThrow(AuthenticationException::class);
});
