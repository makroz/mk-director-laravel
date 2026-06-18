<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Performance;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Controllers\OpenApiController;
use Mk\Director\ModuleLoader\ModuleProviderRegistry;
use Mk\Director\Services\OpenApiGeneratorService;
use Mk\Director\Tests\MkLaravelTestCase;
use Mockery;

/**
 * T5.1 (R4-005): OpenAPI spec cached for 24h, invalidated by mk:generate-docs.
 * T5.2 (R4-006 + R2-016): ModuleProviderRegistry with 1h cache + symlink rejection.
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Mockery::close();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

/**
 * T5.1 — OpenAPI cache
 */
test('OpenApiController::spec wraps the generator output in Cache::remember()', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Controllers/OpenApiController.php');

    expect($src)->toContain('Cache::remember');
    expect($src)->toContain("'mk_openapi_spec'");
});

test('OpenApiController reads the TTL from config (default 86400)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Controllers/OpenApiController.php');

    expect($src)->toContain('mk_director.openapi.cache_ttl');
    expect($src)->toContain('86400');
});

test('OpenApiController exposes the cache key as a public constant', function () {
    expect(OpenApiController::CACHE_KEY)->toBe('mk_openapi_spec');
});

test('GenerateDocsCommand invalidates the OpenAPI cache on success', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Console/Commands/GenerateDocsCommand.php');

    expect($src)->toContain('Cache::forget');
    expect($src)->toContain('OpenApiController::CACHE_KEY');
});

test('OpenApiController::spec returns JsonResponse wrapping the cached spec', function () {
    // Boot a fresh container with an array cache and a stub generator.
    $container = Container::getInstance();
    $container->singleton('cache', fn (): CacheRepository => new CacheRepository(new ArrayStore()));
    Facade::setFacadeApplication($container);

    $generator = Mockery::mock(OpenApiGeneratorService::class);
    $generator->shouldReceive('generate')->andReturn(['openapi' => '3.0.0', 'paths' => []]);

    $controller = new OpenApiController();
    $response = $controller->spec($generator);

    expect($response)->toBeInstanceOf(\Illuminate\Http\JsonResponse::class);
    $body = json_decode($response->getContent(), true);
    expect($body)->toBe(['openapi' => '3.0.0', 'paths' => []]);
});

test('OpenApiController::spec uses cached result on second call (no second generator invocation)', function () {
    $container = Container::getInstance();
    $container->singleton('cache', fn (): CacheRepository => new CacheRepository(new ArrayStore()));
    Facade::setFacadeApplication($container);

    $generator = Mockery::mock(OpenApiGeneratorService::class);
    // Generator should be invoked exactly ONCE — the second call must
    // come from cache.
    $generator->shouldReceive('generate')->once()->andReturn(['openapi' => '3.0.0']);

    $controller = new OpenApiController();
    $r1 = $controller->spec($generator);
    $r2 = $controller->spec($generator);

    expect($r1->getContent())->toBe($r2->getContent());
});

/**
 * T5.2 — ModuleProviderRegistry
 */
test('ModuleProviderRegistry exposes a discover() method and a flush() method', function () {
    $registry = new ModuleProviderRegistry();

    expect(method_exists($registry, 'discover'))->toBeTrue();
    expect(method_exists($registry, 'flush'))->toBeTrue();
    expect(method_exists($registry, 'scan'))->toBeTrue();
});

test('ModuleProviderRegistry source rejects symlinked module directories (R2-016)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/ModuleLoader/ModuleProviderRegistry.php');

    expect($src)->toContain('isLink()');
    expect($src)->toContain('realpath');
});

test('ModuleProviderRegistry source rejects a symlinked Modules directory itself', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/ModuleLoader/ModuleProviderRegistry.php');

    expect($src)->toContain('is_link($candidate)');
});

test('ModuleProviderRegistry uses Cache::remember with a TTL derived from config', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/ModuleLoader/ModuleProviderRegistry.php');

    expect($src)->toContain('Cache::remember');
    expect($src)->toContain('mk_director.modules.cache_ttl');
    expect($src)->toContain('3600');
});

test('ModuleProviderRegistry cache key is derived from the canonical modules path hash', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/ModuleLoader/ModuleProviderRegistry.php');

    expect($src)->toContain('CACHE_KEY_PREFIX');
    expect($src)->toContain('md5');
});

test('ModuleLoaderServiceProvider delegates discovery to the registry (R4-006)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/ModuleLoader/ModuleLoaderServiceProvider.php');

    expect($src)->toContain('ModuleProviderRegistry');
    expect($src)->toContain('$registry->discover()');
    // Old direct DirectoryIterator loop should be gone.
    expect($src)->not->toContain('new \\DirectoryIterator($modulesPath)');
});