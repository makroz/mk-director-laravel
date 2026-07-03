<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mk\Director\Auth\Exceptions\ScopeMismatchException;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mk\Director\Tests\TestCase;
use Mockery;

uses(TestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * F1.2 (LAR-03 HIGH) — MkAuthenticate MUST catch ScopeMismatchException thrown
 * from AuthScopeResolver and translate it to a 401 with the canonical R-PKG-024
 * envelope (data:null, __extraData.code). Pre-fix, the middleware only caught
 * AuthenticationException, which ScopeMismatchException does NOT extend
 * (it extends RuntimeException), so the exception bubbled up as a generic
 * 500 from the Laravel exception handler — leaking the mismatch as a server
 * failure and bypassing the documented contract.
 */
test('MkAuthenticate source catches ScopeMismatchException from the resolver', function () {
    $source = (string) file_get_contents(__DIR__.'/../../src/Auth/Middleware/MkAuthenticate.php');

    expect($source)->toContain('use Mk\\Director\\Auth\\Exceptions\\ScopeMismatchException');
    expect($source)->toMatch('/catch\s*\(\s*ScopeMismatchException\b/');
    expect($source)->toContain('ERR_SCOPE_MISMATCH');
});

test('MkAuthenticate source exposes actual_scope in __extraData for ScopeMismatch', function () {
    // Defense-in-depth: el envelope debe distinguir scope mismatch de no-auth
    // vía `__extraData.actual_scope`, así el frontend puede mostrar "iniciá
    // sesión como admin" en vez de un error genérico.
    $source = (string) file_get_contents(__DIR__.'/../../src/Auth/Middleware/MkAuthenticate.php');

    expect($source)->toContain("'actual_scope'");
    expect($source)->toContain('$e->actualScope');
});

test('MkAuthenticate returns 401 canonical envelope when token scope does not match (api/*)', function () {
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->with('admin')
        ->andThrow(new ScopeMismatchException(
            expectedScope: 'admin',
            actualScope: 'member',
        ));

    $middleware = new MkAuthenticate($resolver);

    // /api/* → expectsJson()/is('api/*') path → envelope, no throw.
    $request = Request::create('/api/admin/dashboard', 'GET');
    $request->headers->set('Accept', 'application/json');

    Auth::shouldReceive('guard')->with('admin')->andReturnSelf();
    Auth::shouldReceive('user')->andReturn((object) ['id' => 1]);
    Auth::shouldReceive('shouldUse')->with('admin')->andReturnNull();

    $response = $middleware->handle($request, fn () => null, 'admin');

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(401);

    $body = json_decode($response->getContent(), true);
    expect($body['success'])->toBeFalse();
    expect($body['data'])->toBeNull();
    expect($body['message'])->toContain('admin');
    expect($body['message'])->toContain('member');
    expect($body['__extraData']['code'])->toBe('ERR_SCOPE_MISMATCH');
    expect($body['__extraData']['auth_scope'])->toBe('admin');
    expect($body['__extraData'])->toHaveKey('actual_scope');
    expect($body['__extraData']['actual_scope'])->toBe('member');
    expect($body['debugMsg'])->toBe([]);
});

test('MkAuthenticate returns 401 with ERR_SCOPE_MISMATCH when token has no auth_scope ability (no_scope_ability)', function () {
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->with('admin')
        ->andThrow(new ScopeMismatchException(
            expectedScope: 'admin',
            actualScope: null,
        ));

    $middleware = new MkAuthenticate($resolver);
    $request = Request::create('/api/admin/dashboard', 'GET');
    $request->headers->set('Accept', 'application/json');

    Auth::shouldReceive('guard')->with('admin')->andReturnSelf();
    Auth::shouldReceive('user')->andReturn((object) ['id' => 1]);
    Auth::shouldReceive('shouldUse')->with('admin')->andReturnNull();

    $response = $middleware->handle($request, fn () => null, 'admin');

    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body['__extraData']['code'])->toBe('ERR_SCOPE_MISMATCH');
    expect($body['__extraData']['actual_scope'])->toBeNull();
});

test('MkAuthenticate returns 401 with ERR_SCOPE_MISMATCH when user has no token (no_token)', function () {
    // El path "no_token" (currentAccessToken() no es PersonalAccessToken)
    // está cubierto en AuthScopeResolver::resolve() y también lanza
    // ScopeMismatchException. Este test pinea que el middleware lo
    // transforma igual, sin filtrar la RuntimeException original.
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->with('admin')
        ->andThrow(new ScopeMismatchException(
            expectedScope: 'admin',
            actualScope: null,
        ));

    $middleware = new MkAuthenticate($resolver);
    $request = Request::create('/api/admin/dashboard', 'GET');
    $request->headers->set('Accept', 'application/json');

    Auth::shouldReceive('guard')->with('admin')->andReturnSelf();
    Auth::shouldReceive('user')->andReturn((object) ['id' => 1]);
    Auth::shouldReceive('shouldUse')->with('admin')->andReturnNull();

    $response = $middleware->handle($request, fn () => null, 'admin');

    expect($response->getStatusCode())->toBe(401);
    $body = json_decode($response->getContent(), true);
    expect($body['__extraData']['code'])->toBe('ERR_SCOPE_MISMATCH');
});

test('MkAuthenticate web (non-api, non-json) still throws AuthenticationException on scope mismatch (BC)', function () {
    // BC: pre-fix, cuando un usuario tenía token de otro scope y entraba
    // a una ruta web (no api/*, no JSON), Laravel's Handler::unauthenticated()
    // redirigía a /login. Para preservar eso, el path web debe seguir
    // lanzando AuthenticationException en vez de retornar JsonResponse
    // (la pipeline de Laravel la captura vía Handler::unauthenticated).
    $resolver = Mockery::mock(AuthScopeResolver::class);
    $resolver->shouldReceive('resolve')
        ->once()
        ->with('admin')
        ->andThrow(new ScopeMismatchException(
            expectedScope: 'admin',
            actualScope: 'member',
        ));

    $middleware = new MkAuthenticate($resolver);
    $request = Request::create('/admin/dashboard', 'GET');

    Auth::shouldReceive('guard')->with('admin')->andReturnSelf();
    Auth::shouldReceive('user')->andReturn((object) ['id' => 1]);
    Auth::shouldReceive('shouldUse')->with('admin')->andReturnNull();

    expect(fn () => $middleware->handle($request, fn () => null, 'admin'))
        ->toThrow(AuthenticationException::class);
});
