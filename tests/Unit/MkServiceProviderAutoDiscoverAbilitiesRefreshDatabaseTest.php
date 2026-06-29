<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\MkServiceProvider;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE14-01 source-parsing tests — feedback RETO fase 14 (2026-06-29).
 *
 * Source: `FEEDBACK-TO-MK-DIRECTOR-fase14.md` § HALLAZGO-NEW-FASE14-01.
 *
 * What this sprint pines:
 *   The `registerAutoDiscoverAbilities()` boot hook in `MkServiceProvider`
 *   now SKIPS auto-discover when the abilities table doesn't exist yet.
 *   This is critical for testing setups with `RefreshDatabase`, where
 *   package migrations from `loadMigrationsFrom` run AFTER the ServiceProvider
 *   boots, leaving the table unavailable when auto-discover fires.
 *
 * Pre-fix runtime symptom (RETO feedback):
 *   - `MK_AUTO_DISCOVER_ABILITIES=true` in `.env` + Pest/PHPUnit suite
 *     using `RefreshDatabase` → every test fails on boot with
 *     `RuntimeException: Ninguna tabla de abilities existe. Esperaba
 *     'admins_abilities' o 'abilities'. ¿Corriste php artisan migrate
 *     después de scaffoldear?`
 *
 * Post-fix runtime behavior (v1.8.1+):
 *   - Production: `Schema::hasTable($abilitiesTable)` returns true
 *     (migrations ran before boot) → guard is no-op, auto-discover
 *     runs as before.
 *   - Testing: `Schema::hasTable($abilitiesTable)` returns false
 *     (migrations run AFTER boot via RefreshDatabase) → auto-discover
 *     is skipped with `Log::debug(...)`, no RuntimeException.
 *
 * HALLAZGO-NEW-03 (cross-project) — source-parsing INTENCIÓN (this file) +
 * e2e EFECTIVIDAD validated by the consumer (RETO regenerates Admin module
 * from 0 on v1.8.1+ and runs the full suite — if `MK_AUTO_DISCOVER_ABILITIES=true`
 * boots clean, the fix is verified). The package-only test env
 * (`MkLaravelTestCase`) cannot run a full `RefreshDatabase` lifecycle
 * (Capsule without active connection).
 *
 * Spec: HALLAZGO-NEW-FASE14-01, R-PKG-007 D4.
 *
 * @see MkServiceProvider::registerAutoDiscoverAbilities()
 */
uses(MkLaravelTestCase::class);

function readMkServiceProviderSourceRefreshDatabase(): string
{
    $fullPath = dirname(__DIR__, 2).'/src/MkServiceProvider.php';
    expect(file_exists($fullPath))->toBeTrue("MkServiceProvider must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('HALLAZGO-NEW-FASE14-01 — registerAutoDiscoverAbilities() RefreshDatabase guard', function (): void {
    $source = readMkServiceProviderSourceRefreshDatabase();

    test('method has Schema::hasTable() check before the Artisan::call() block', function () use ($source): void {
        // The fix introduces a Schema::hasTable check before auto-discover
        // is invoked. Without it, the discover-abilities command would
        // RuntimeException on testing environments where the abilities
        // table hasn't been migrated yet (common in RefreshDatabase setups).

        expect($source)->toContain('Schema::hasTable');
        // The default table name (matching `mk_director.auth.tables.abilities`
        // config default or the explicit fallback to 'abilities').
        expect($source)->toContain("'abilities'");
    });

    test('method uses config() for the abilities table name (NOT a hardcoded literal)', function () use ($source): void {
        // The fix respects the consumer's config override via
        // `config('mk_director.auth.tables.abilities', 'abilities')`.
        // Hardcoding 'abilities' would break consumers that use a
        // per-scope table like `admins_abilities` (RETO case).

        expect($source)->toContain("config('mk_director.auth.tables.abilities', 'abilities')");
    });

    test('method uses Log::debug (NOT warning/error) when skipping due to missing table', function () use ($source): void {
        // The fix logs at debug level (not warning/error). The skip is
        // an EXPECTED case in testing environments — not an error worth
        // filling consumer logs with warnings.

        // Pin the debug log call present (substring assertions — Pest's
        // toMatch() doesn't accept PCRE flags, and string-based checks
        // are sufficient here).
        expect($source)->toContain('Log::debug');
        expect($source)->toContain('skip auto-discover-abilities');

        // And ensure the skip log is NOT at warning level. We check this
        // by looking for `Log::warning` followed by `skip auto-discover-abilities`
        // on the same line (using [^\n]* instead of .* — avoids the PCRE
        // /s flag that Pest's toMatch() doesn't accept).
        expect(preg_match('/Log::warning[^\n]*skip auto-discover-abilities/', $source))->toBe(0);
        expect(preg_match('/Log::error[^\n]*skip auto-discover-abilities/', $source))->toBe(0);
    });

    test('method returns early after the Schema::hasTable skip (does NOT fall through to Artisan::call)', function () use ($source): void {
        // The skip pattern is:
        //   if (! Schema::hasTable(...)) {
        //       Log::debug(...);
        //       return;
        //   }
        //
        // Without the `return;`, the code would fall through to the
        // Artisan::call() and trigger the RuntimeException anyway.

        // Pin the return statement within the Schema::hasTable block.
        expect($source)->toMatch('/Schema::hasTable.*?Log::debug.*?return;/s');
    });

    test('HALLAZGO-NEW-FASE14-01 reference is documented in source comments (drift trazable per R-G-032)', function () use ($source): void {
        // Drift trazable per R-G-032 — the fix must be discoverable via
        // grep for the HALLAZGO ID in source comments.

        expect($source)->toContain('HALLAZGO-NEW-FASE14-01');
    });

    test('fix comment explains WHY (RefreshDatabase testing pattern) not just WHAT', function () use ($source): void {
        // R-G-032 feedback loop: comments must explain the rationale
        // (RefreshDatabase lifecycle), not just describe the code.

        expect($source)->toContain('RefreshDatabase');
        expect($source)->toContain('loadMigrationsFrom');
    });

    test('the Schema::hasTable guard runs AFTER the long-running skip and the mk:discover-abilities re-entry guard', function () use ($source): void {
        // Order matters — the existing skips must still fire BEFORE the
        // new Schema::hasTable check (otherwise the Schema check would
        // query the DB even on `php artisan serve` boot, which is wasteful).

        // Find the position of each guard.
        $serveSkipPos = strpos($source, "'serve'");
        $argvRecursionGuardPos = strpos($source, "str_starts_with((string) \$_SERVER['argv'][1], 'mk:discover-abilities')");
        $schemaCheckPos = strpos($source, 'Schema::hasTable');

        expect($serveSkipPos)->toBeGreaterThan(0);
        expect($argvRecursionGuardPos)->toBeGreaterThan($serveSkipPos);
        expect($schemaCheckPos)->toBeGreaterThan($argvRecursionGuardPos);
    });
});

describe('HALLAZGO-NEW-FASE14-01 — pre-existing guards still present (no regression)', function (): void {
    $source = readMkServiceProviderSourceRefreshDatabase();

    test('long-running CLI skip (BUG-NEW-auto-discover-serve) is still present', function () use ($source): void {
        // The pre-existing fix for BUG-NEW-auto-discover-serve (skip
        // long-running CLI contexts like `serve`, `octane:start`, etc.)
        // must still work after the HALLAZGO-NEW-FASE14-01 fix.

        expect($source)->toContain('$skipArgs');
        expect($source)->toContain("'serve'");
        expect($source)->toContain("'octane:start'");
    });

    test('mk:discover-abilities re-entry guard is still present', function () use ($source): void {
        // Pre-existing guard (v1.7.0+): if argv[1] starts with
        // `mk:discover-abilities`, skip auto-discover to avoid infinite
        // recursion when devs run the command interactively.

        expect($source)->toContain("str_starts_with((string) \$_SERVER['argv'][1], 'mk:discover-abilities')");
    });

    test('Artisan::call() with command name (NOT the malformed FQCN call) is still present', function () use ($source): void {
        // Pre-existing fix from BUG-NEW-auto-discover-serve: use
        // `Artisan::call('mk:discover-abilities', ...)` instead of
        // the malformed `$this->app->call(DiscoverAbilitiesCommand::class, ...)`.

        expect($source)->toContain("Artisan::call('mk:discover-abilities'");
    });
});
