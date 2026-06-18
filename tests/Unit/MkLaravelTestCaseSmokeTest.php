<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Smoke test for the MkLaravelTestCase base.
 *
 * Verifies that the base TestCase boots a minimal Laravel Container with
 * the bindings required by the existing test suite:
 *  - `config` facade returns a Repository
 *  - `Cache::put()` does not throw BindingResolutionException
 *  - `DB` facade resolves a Capsule without throwing
 *  - Facades can be swapped (Schema::shouldReceive, Auth::shouldReceive)
 *
 * Each test instantiates a fresh MkLaravelTestCase subclass via
 * {@see MkLaravelTestCase::bootContainer()} (a static helper).
 *
 * @see audit-2026-06-17-R3-009, audit-2026-06-17-R3-010, audit-2026-06-17-R3-011
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
    Container::setInstance(null);
});

test('MkLaravelTestCase::bootContainer returns a Container with config binding', function () {
    $container = MkLaravelTestCase::bootContainer();

    expect($container)->toBeInstanceOf(Container::class);
    expect($container->bound('config'))->toBeTrue();
    expect($container->make('config'))->toBeInstanceOf(ConfigRepository::class);
});

test('MkLaravelTestCase config binding is mutable via config() helper', function () {
    $container = MkLaravelTestCase::bootContainer();
    Facade::setFacadeApplication($container);

    config(['mk_director.test_value' => 'alpha']);

    expect(config('mk_director.test_value'))->toBe('alpha');
});

test('MkLaravelTestCase Cache facade resolves without BindingResolutionException', function () {
    $container = MkLaravelTestCase::bootContainer();
    Facade::setFacadeApplication($container);

    \Illuminate\Support\Facades\Cache::put('mk_smoke_key', 'smoke', 60);

    expect(\Illuminate\Support\Facades\Cache::get('mk_smoke_key'))->toBe('smoke');
});

test('MkLaravelTestCase DB facade resolves without BindingResolutionException', function () {
    $container = MkLaravelTestCase::bootContainer();
    Facade::setFacadeApplication($container);

    // We don't actually run a query (no DB driver configured in unit context);
    // we just verify the DatabaseManager binding exists.
    expect($container->bound('db'))->toBeTrue();
    expect($container->make('db'))->toBeInstanceOf(\Illuminate\Database\Capsule\Manager::class);
});

test('MkLaravelTestCase allows Facade mocking (Config::shouldReceive)', function () {
    $container = MkLaravelTestCase::bootContainer();
    Facade::setFacadeApplication($container);

    // Pick a facade that does not require a live DB connection so the mock
    // is purely an in-memory swap. The Schema facade is exercised by the
    // migration tests with their own setup.
    \Illuminate\Support\Facades\Config::shouldReceive('get')
        ->once()
        ->with('mk_director.mocked', null)
        ->andReturn('mocked-value');

    expect(config('mk_director.mocked'))->toBe('mocked-value');
});

test('MkLaravelTestCase does NOT depend on orchestra/testbench', function () {
    expect(class_exists(\Orchestra\Testbench\TestCase::class))->toBeFalse();
});