<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantMembershipGate;
use Mk\Director\Tests\TestCase;
use Mockery;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Gate de tenancy con la feature APAGADA — que es el escenario de estos tests:
 * miden autenticación y scope, no aislamiento multi-tenant.
 *
 * 🔴 El gate es una dependencia REQUERIDA de `MkAuthenticate`, no opcional con
 * fallback al container. La fuga de aislamiento que motivó este cableado fue
 * justo eso: una validación que no corría y nadie notaba. Un parámetro
 * obligatorio falla fuerte en la construcción; un `?Gate = null` con `?? app()`
 * falla en silencio y vuelve al mismo agujero.
 */
function gateDeTenancyApagado(): TenantMembershipGate
{
    return new TenantMembershipGate(
        new TenantContext,
        Container::getInstance()->make('config'),
    );
}

test('throws AuthenticationException when user is unauthenticated', function () {
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $middleware = new MkAuthenticate($resolver, gateDeTenancyApagado());

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
