<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantResolver;

uses(\Mk\Director\Tests\TestCase::class);

afterEach(function () {
    Mockery::close();
    Container::setInstance(null);
});

/**
 * Helper — set up a Container with a Config repository and a
 * fresh TenantContext, then return the middleware and context.
 *
 * @return array{0: TenantResolver, 1: TenantContext, 2: \Illuminate\Container\Container}
 */
function bootMiddlewareWithConfig(array $configValues): array
{
    $app = new Container();
    Container::setInstance($app);

    $config = Mockery::mock(Config::class);
    foreach ($configValues as $key => $value) {
        $config->shouldReceive('get')->with($key, Mockery::any())->andReturn($value);
    }
    // Also allow unexpected keys to return null without crashing.
    $config->shouldReceive('get')->andReturn(null);
    $app->instance('config', $config);

    $context = new TenantContext();
    $app->instance(TenantContext::class, $context);

    $resolver = new TenantResolver($context, $config);

    return [$resolver, $context, $app];
}

test('TenantResolver reads the X-Tenant-ID header and writes it to the context', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.header_name' => 'X-Tenant-ID',
        'mk_director.tenant.strict' => true,
    ]);

    $request = Request::create('/api/anything', 'GET');
    $request->headers->set('X-Tenant-ID', '7');

    $response = $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($context->current())->toBe(7)
        ->and($response->getStatusCode())->toBe(200)
        ->and((string) $response->getContent())->toBe('ok');
});

test('TenantResolver reads a custom header name from config', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.header_name' => 'X-Org-ID',
        'mk_director.tenant.strict' => true,
    ]);

    $request = Request::create('/api/anything', 'GET');
    $request->headers->set('X-Org-ID', 'acme');

    $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($context->current())->toBe('acme');
});

test('TenantResolver returns 400 when strict and the header is missing', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.header_name' => 'X-Tenant-ID',
        'mk_director.tenant.strict' => true,
    ]);

    $request = Request::create('/api/anything', 'GET');

    $response = $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($response->getStatusCode())->toBe(400)
        ->and($context->current())->toBeNull();

    $payload = json_decode((string) $response->getContent(), true);
    expect($payload)->toBeArray()
        ->and($payload['error'])->toBe('ERR_TENANT_MISSING');
});

test('TenantResolver passes through (no scope) when non-strict and header missing', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.header_name' => 'X-Tenant-ID',
        'mk_director.tenant.strict' => false,
    ]);

    $request = Request::create('/api/anything', 'GET');

    $response = $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($context->current())->toBeNull();
});

test('TenantResolver normalizes a numeric header value to int', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.header_name' => 'X-Tenant-ID',
        'mk_director.tenant.strict' => true,
    ]);

    $request = Request::create('/api/anything', 'GET');
    $request->headers->set('X-Tenant-ID', '99');

    $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($context->current())->toBe(99);
});

test('TenantResolver from path resolver requires a tenant model in config', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        'mk_director.tenant.enabled' => true,
        'mk_director.tenant.resolver' => 'path',
        'mk_director.tenant.strict' => false,
        // mk_director.tenant.model is null → slug lookup is skipped → null returned.
    ]);

    $request = Request::create('/acme/api/x', 'GET');

    $response = $resolver->handle($request, fn ($r) => new Response('ok'));

    // Non-strict + no model → pass through with no tenant set.
    expect($response->getStatusCode())->toBe(200)
        ->and($context->current())->toBeNull();
});

test('TenantResolver is a pass-through when tenant.enabled is false (opt-in)', function () {
    [$resolver, $context] = bootMiddlewareWithConfig([
        // mk_director.tenant.enabled defaults to false; not set
        // here on purpose. The middleware must not touch the
        // context or block the request.
        'mk_director.tenant.resolver' => 'header',
        'mk_director.tenant.strict' => true,
    ]);

    $request = Request::create('/api/anything', 'GET');
    // No X-Tenant-ID header.

    $response = $resolver->handle($request, fn ($r) => new Response('ok'));

    expect($response->getStatusCode())->toBe(200)
        ->and($context->current())->toBeNull()
        ->and((string) $response->getContent())->toBe('ok');
});
