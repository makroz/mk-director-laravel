<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Database\Eloquent\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Tests for R-PKG-022 BUG-NEW-32 + HALLAZGO-NEW-05 — verify that
 * `MkBelongsToMany::from()` does NOT emit deprecation warnings.
 *
 * Pre-R-PKG-022: the method called `setAccessible(true)` on
 * ReflectionProperty instances, which is deprecated since PHP 8.1
 * and emits a warning since PHP 8.5.
 *
 * Post-R-PKG-022: `setAccessible()` calls were removed because the
 * paquete requires PHP 8.4+, and since PHP 8.1 properties are
 * accessible by default (no-op call).
 *
 * Strategy: instantiate a `BelongsToMany` with minimal stubs,
 * call `MkBelongsToMany::from()`, and capture deprecation warnings
 * via a custom error handler. The test fails if any warning with
 * the `E_DEPRECATED` level is raised.
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: build a minimal `BelongsToMany` instance for testing.
 *
 * We can't use the full Eloquent stack without a DB connection,
 * so we use ReflectionClass to create an instance without invoking
 * the constructor (the test only cares about `from()`'s property
 * copy logic).
 */
function buildMinimalBelongsToMany(): BelongsToMany
{
    // `newInstanceWithoutConstructor()` is fine because MkBelongsToMany::from()
    // copies properties via reflection — it never calls methods that depend on
    // constructor initialization.
    $reflection = new \ReflectionClass(BelongsToMany::class);
    /** @var BelongsToMany $instance */
    $instance = $reflection->newInstanceWithoutConstructor();

    return $instance;
}

/**
 * Run a closure with a custom error handler that captures any
 * deprecation warnings raised during execution.
 *
 * Returns the captured warnings as an array of strings.
 *
 * @return array<int, string>
 */
function captureDeprecationWarnings(callable $fn): array
{
    $warnings = [];

    set_error_handler(function ($errno, $errstr) use (&$warnings) {
        if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
            $warnings[] = $errstr;
        }

        // Return true to prevent PHP's default error handler from running,
        // so the captured warning doesn't pollute test output.
        return true;
    });

    try {
        $fn();
    } finally {
        restore_error_handler();
    }

    return $warnings;
}

test('BUG-NEW-32: MkBelongsToMany::from() does not emit deprecation warnings (regression guard)', function () {
    $source = buildMinimalBelongsToMany();

    $warnings = captureDeprecationWarnings(function () use ($source) {
        // Suppress Eloquent strict mode that may complain about missing DB.
        // We're only testing reflection-based property copy.
        try {
            MkBelongsToMany::from($source);
        } catch (\Throwable $e) {
            // Some internal state (e.g. related model) may not be initialized,
            // but that doesn't affect the warning capture.
        }
    });

    // Filter out unrelated deprecations (e.g. from PHPUnit or Pest).
    $ourWarnings = array_filter($warnings, function ($msg) {
        return str_contains($msg, 'setAccessible')
            || str_contains($msg, 'MkBelongsToMany')
            || str_contains($msg, 'ReflectionProperty');
    });

    expect($ourWarnings)->toBeEmpty(
        'MkBelongsToMany::from() must not emit any setAccessible/ReflectionProperty deprecation warnings. Captured: '
        .implode(' | ', $ourWarnings)
    );
});

test('BUG-NEW-32: MkBelongsToMany::from() source code does not call setAccessible (static check)', function () {
    // `__DIR__` = tests/Unit/Database/Eloquent/Relations/
    // Necesitamos ir 5 niveles arriba para llegar a la raíz del paquete.
    $src = (string) file_get_contents(
        dirname(__DIR__, 5).'/src/Database/Eloquent/Relations/MkBelongsToMany.php'
    );

    // Pineamos el código activo (`->setAccessible(` con flecha) — los comments
    // que documentan el fix pueden mencionar la palabra sin ser invocaciones.
    expect($src)->not->toContain('->setAccessible(');
});

test('BUG-NEW-32: MkBelongsToMany::from() copies properties successfully (smoke test)', function () {
    $source = buildMinimalBelongsToMany();

    $result = MkBelongsToMany::from($source);

    expect($result)->toBeInstanceOf(MkBelongsToMany::class);
    // Verify late static binding: `from()` uses `static::class`, so subclassing
    // is preserved.
    expect(get_class($result))->toBe(MkBelongsToMany::class);
});

test('BUG-NEW-32: MkBelongsToMany::from() returns an instance whose class matches `static::class` (LSB preserved)', function () {
    $source = buildMinimalBelongsToMany();

    // Verify with explicit class reference.
    $result = MkBelongsToMany::from($source);

    expect($result)->toBeInstanceOf(MkBelongsToMany::class);
});