<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * BUG-NEW-auto-discover-serve source-parsing tests (RETO fase 12
 * feedback 2026-06-28).
 *
 * Source: `FEEDBACK-TO-MK-DIRECTOR-fase12.md` § BUG-NEW-auto-discover-serve.
 *
 * What this sprint pines:
 *   1. The auto-discover-abilities boot hook SKIPS when `$_SERVER['argv']`
 *      includes any of the long-running CLI commands (`serve`, `octane:start`,
 *      `horizon`, `queue:work|listen`, `schedule:work|run`). Previously
 *      `runningInConsole()` returned `true` for these contexts and the
 *      auto-discover would run on the boot of the HTTP server.
 *   2. The auto-discover uses `Artisan::call('mk:discover-abilities', ...)`
 *      instead of `$this->app->call(DiscoverAbilitiesCommand::class, ...)`.
 *      The previous call signature was malformed (treating the FQCN as a
 *      callable) and bricked any dev with `MK_AUTO_DISCOVER_ABILITIES=true`
 *      in `.env` on `php artisan serve`.
 *
 * Pre-fix runtime symptom (RETO feedback):
 *   - `php artisan serve` with `MK_AUTO_DISCOVER_ABILITIES=true` → first
 *     HTTP request returned HTTP 500 with
 *     `Call to undefined function DiscoverAbilitiesCommand()`.
 *
 * HALLAZGO-NEW-03 — source-parsing INTENCIÓN (this file) + e2e EFECTIVIDAD
 * (`apps/sandbox-laravel` + RETO fase 13 consumer rebuild). The e2e requires
 * a full Laravel app with a real Console Kernel, which the package-only
 * test env (MkLaravelTestCase) does not provide. The package can pin
 * INTENCIÓN (the code structure); the consumer (RETO) validates
 * EFECTIVIDAD at runtime.
 *
 * Spec: BUG-NEW-auto-discover-serve.
 *
 * @see MkServiceProvider::registerAutoDiscoverAbilities()
 */
uses(MkLaravelTestCase::class);

function readMkServiceProviderSourceAutoDiscover(): string
{
    $fullPath = dirname(__DIR__, 2).'/src/MkServiceProvider.php';
    expect(file_exists($fullPath))->toBeTrue("MkServiceProvider must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('BUG-NEW-auto-discover-serve — registerAutoDiscoverAbilities() source contract', function (): void {
    $source = readMkServiceProviderSourceAutoDiscover();

    test('method declares the $skipArgs allowlist for long-running CLI contexts', function () use ($source): void {
        // The fix introduces a $skipArgs array that lists all long-running
        // CLI commands that should NOT trigger auto-discover on boot.

        expect($source)->toContain('$skipArgs');
        expect($source)->toContain("'serve'");
        expect($source)->toContain("'octane:start'");
        expect($source)->toContain("'horizon'");
        expect($source)->toContain("'queue:work'");
        expect($source)->toContain("'schedule:run'");
    });

    test('method has a foreach loop over $_SERVER[\'argv\'] that returns early on match', function () use ($source): void {
        // The fix uses a foreach loop to check each argv token against
        // the $skipArgs allowlist, returning early if any token matches.

        // The arg is also referenced via a check for queue:listen and
        // schedule:work — pin those too.
        expect($source)->toContain("'queue:listen'");
        expect($source)->toContain("'schedule:work'");

        // Pin the loop structure: foreach over argv, in_array check, return.
        expect($source)->toMatch('/foreach\s*\(\s*\$_SERVER\[.argv.\]\s+as\s+\$arg\s*\)\s*\{/');
        expect($source)->toMatch('/if\s*\(\s*in_array\(\s*\$arg\s*,\s*\$skipArgs\s*,\s*true\s*\)\s*\)\s*\{/');
    });

    test('method uses Artisan::call() (NOT the malformed $this->app->call() with FQCN)', function () use ($source): void {
        // The fix replaces the malformed call (assigning the FQCN of the
        // command class as the first arg of $this->app->call) with
        // `Artisan::call('mk:discover-abilities', ...)`. The former treated
        // the FQCN as a callable and failed with `Call to undefined function`.

        expect($source)->toContain("Artisan::call('mk:discover-abilities'");

        // The malformed pattern (assigning the FQCN to $this->app->call as
        // the first arg) must NOT appear as CODE anymore. It MAY appear in
        // a docblock comment that documents the bug, but NOT as a runtime
        // call. We check by counting occurrences that look like an actual
        // method call (indented, semicolon at end).
        $occurrences = preg_match_all(
            '/^\s*\\\$this->app->call\(\s*DiscoverAbilitiesCommand/m',
            $source
        );
        expect($occurrences)->toBe(0);
    });

    test('method still has the re-entry guard for argv=mk:discover-abilities', function () use ($source): void {
        // Pre-existing guard (was already in v1.7.0): if argv[1] starts with
        // `mk:discover-abilities`, skip auto-discover to avoid infinite
        // recursion when devs run the command interactively. The fix
        // preserves this guard.

        expect($source)->toContain("str_starts_with((string) \$_SERVER['argv'][1], 'mk:discover-abilities')");
    });

    test('BUG-NEW-auto-discover-serve reference is documented in source comments (drift trazable per R-G-032)', function () use ($source): void {
        // Drift trazable.

        expect($source)->toContain('BUG-NEW-auto-discover-serve');
    });
});

describe('BUG-NEW-auto-discover-serve — e2e runtime validation deferred to consumer (HALLAZGO-NEW-03)', function (): void {
    test('package provides a concrete class to override in the consumer app for e2e testing', function () {
        // The package is a library, not an app. The full e2e runtime
        // (with a real Console Kernel + `php artisan serve` boot) is
        // validated in the consumer (RETO fase 13 rebuild on v1.7.1-rc1).
        //
        // What this test pins: the MkServiceProvider class exists, is
        // concrete enough to be bootable, and the method is callable.
        // The method is `protected` so we test the class metadata only.

        $reflection = new \ReflectionClass(MkServiceProvider::class);

        expect($reflection->isInstantiable())->toBeTrue();
        expect($reflection->hasMethod('registerAutoDiscoverAbilities'))->toBeTrue();
        expect($reflection->getMethod('registerAutoDiscoverAbilities')->isProtected())->toBeTrue();
    });

    test('NOTE: e2e runtime validation of the skip behavior is documented in the changelog', function () {
        // The actual e2e test (argv=serve + first HTTP request should NOT
        // return 500) lives in:
        //   - `apps/sandbox-laravel` (package monorepo): integration test
        //     booting a real PHP server and curling `/api/admin/auth/login`
        //     to verify no boot crash.
        //   - `mariogfos/reto-api` (consumer): RETO fase 13 rebuild on
        //     v1.7.1-rc1 + `php artisan serve` + curl `/api/admin/auth/login`
        //     to verify the same.
        //
        // The package cannot run `php artisan serve` in its test env
        // (no full Laravel app, no Console Kernel, no HTTP kernel). The
        // source-parsing tests in this file pin INTENCIÓN; the consumer
        // validates EFECTIVIDAD at runtime.
        expect(true)->toBeTrue();
    });
});
