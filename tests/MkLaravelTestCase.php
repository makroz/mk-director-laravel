<?php

declare(strict_types=1);

namespace Mk\Director\Tests;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * MkLaravelTestCase — minimal Laravel-aware TestCase for the mk-director-laravel package.
 *
 * Boots a minimal Laravel Container with the bindings required by the existing
 * Unit test suite:
 *  - `config` → Illuminate\Config\Repository (mutable via `config()` helper)
 *  - `cache` → Illuminate\Cache\Repository backed by ArrayStore
 *  - `db`    → Illuminate\Database\Capsule\Manager (without an active connection)
 *  - `files` → Illuminate\Filesystem\Filesystem
 *
 * The package does NOT include a full Laravel application (it is a library
 * that ships `illuminate/support`, `illuminate/database`, `illuminate/http`),
 * so we deliberately avoid `Orchestra\Testbench\TestCase` which would pull in
 * the entire application kernel.
 *
 * Tests that need a full HTTP kernel, sessions, or queues should live in
 * `apps/sandbox-laravel/tests/Feature`. Tests that need only the Container
 * + facades (which is the bulk of the package's Unit tests) extend this
 * base class.
 *
 * @see audit-2026-06-17-R3-006, audit-2026-06-17-R3-009, audit-2026-06-17-R3-010, audit-2026-06-17-R3-011
 */
abstract class MkLaravelTestCase extends BaseTestCase
{
    /**
     * Cached Container instance, keyed by spl_object_id of the test case.
     * Each test gets a fresh container in {@see setUp()}.
     */
    protected ?Container $bootedContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootedContainer = $this->bootContainer();
    }

    protected function tearDown(): void
    {
        // Reset facade state so each test starts clean.
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    /**
     * Build and return a minimal Laravel Container wired with the bindings
     * required by MkDirector tests. Exposed for direct assertion in
     * MkLaravelTestCaseSmokeTest.
     */
    public static function bootContainer(): Container
    {
        $container = new Container();
        Container::setInstance($container);

        // Config (mutable Repository).
        $container->instance('config', new ConfigRepository([
            'app' => [
                'name' => 'MkDirectorTest',
                'env' => 'testing',
                'key' => 'base64:' . base64_encode(random_bytes(32)),
                'debug' => false,
            ],
            'database' => [
                'default' => 'testing',
                'connections' => [
                    'testing' => [
                        'driver' => 'sqlite',
                        'database' => ':memory:',
                        'prefix' => '',
                    ],
                ],
            ],
            'mk_director' => [
                'tenant' => [
                    'enabled' => false,
                    'column' => 'client_id',
                ],
                'auth' => [
                    'default_user_type' => 'Mk\\Director\\Auth\\Models\\AuthUser',
                ],
                'list' => [
                    'max_per_page' => 100,
                    'default_per_page' => 25,
                ],
                'features' => [
                    'auto_cache' => false,
                ],
                'plugins' => [],
                'debug' => false,
            ],
        ]));

        // Cache (ArrayStore-backed Repository).
        $container->singleton('cache', function (): CacheRepository {
            return new CacheRepository(new ArrayStore());
        });

        // Database Capsule without connecting (so unit tests can mock Schema, etc.).
        $container->singleton('db', function (Container $app): Capsule {
            $capsule = new Capsule($app);
            // Do NOT addConnection here — unit tests mock the Schema facade
            // and do not require an active connection.
            return $capsule;
        });

        // Schema facade binding. Schema::shouldReceive('create') calls
        // getMockableClass() which goes through resolveFacadeInstance()
        // before Mockery has had a chance to install the mock — so we
        // pre-bind a chainable stub that lets the mock be installed
        // later via Facade::swap(). Once Mockery swaps the facade root,
        // calls go through the mock.
        $container->bind('db.schema', function (): object {
            return new class {
                public function __call(string $method, array $args): mixed
                {
                    return $this;
                }
            };
        });

        // Filesystem.
        $container->singleton('files', fn (): Filesystem => new Filesystem());

        // Auth facade chainable stub. The real AuthManager needs a
        // Laravel-booted kernel + user provider; in unit tests we just
        // want `Auth::shouldReceive(...)` to swap the facade root.
        // Mockery sets Facade::$resolvedInstance when shouldReceive()
        // is called, but the Facade::resolveFacadeInstance() flow will
        // still touch the container — we pre-bind a chainable stub so
        // tests that forget to mock a specific call do not blow up
        // with BindingResolutionException.
        $container->bind('auth', function (): object {
            return new class {
                public function __call(string $method, array $args): mixed
                {
                    return $this;
                }
            };
        });

        // Auth driver manager (used when the real AuthManager resolves
        // a guard). Same chainable stub strategy.
        $container->bind('auth.driver', function (): object {
            return new class {
                public function __call(string $method, array $args): mixed
                {
                    return $this;
                }
            };
        });

        // Wire facades to this Container.
        Facade::setFacadeApplication($container);

        return $container;
    }
}