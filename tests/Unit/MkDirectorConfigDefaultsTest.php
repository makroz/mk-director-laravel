<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that the package's default `config/mk_director.php` does NOT
 * hardcode broken defaults in the `auth` block.
 *
 * Background (sprint 2026-06-24, fix):
 *   The previous default had:
 *     'user_model' => \App\Models\User::class,
 *     'default_user_type' => env('MK_AUTH_DEFAULT_USER_TYPE', 'App\\Modules\\Admin\\Models\\Admin'),
 *
 *   Both broke DDD:
 *     - `\App\Models\User` is the Laravel default User model. Per
 *       R-MK-001 (MME) the package must not know about consumer
 *       models living under `App\Models\*`; consumer models live
 *       under `App\Modules\<Scope>\Models\<Scope>`.
 *     - `'App\\Modules\\Admin\\Models\\Admin'` assumes the consumer
 *       has an Admin module. The package must be agnostic.
 *
 *   The fix: both keys read from env() and fall back to null.
 *   The consumer sets them in their own `config/mk_director.php`
 *   (after `php artisan vendor:publish --tag=mk-config`).
 *
 * @see MakeAuthUserCommand (the consumer
 *      no longer needs to update the package's config to wire up a
 *      new scope — they paste the printed snippets into config/auth.php
 *      and define `auth.user_model` in their own published config).
 */
uses(MkLaravelTestCase::class);

function configSource(): string
{
    $path = dirname(__DIR__, 2).'/config/mk_director.php';
    expect(file_exists($path))->toBeTrue("config/mk_director.php must exist at $path");

    return (string) file_get_contents($path);
}

test('mk_director.auth.user_model is env-driven, not hardcoded to Laravel default User', function () {
    $source = configSource();

    // The user_model key reads from env so each consumer can override.
    expect($source)->toContain("'user_model'");
    expect($source)->toContain("env('MK_AUTH_USER_MODEL')");

    // The previous broken default `\App\Models\User` must be gone.
    expect($source)->not->toContain('App\\Models\\User::class');
    expect($source)->not->toContain("'\\App\\Models\\User'");
});

test('mk_director.auth.default_user_type is env-driven, not hardcoded to Admin module', function () {
    $source = configSource();

    expect($source)->toContain("'default_user_type'");
    expect($source)->toContain("env('MK_AUTH_DEFAULT_USER_TYPE')");

    // The previous broken default `App\\Modules\\Admin\\Models\\Admin`
    // must be gone (the package must not assume any specific module).
    expect($source)->not->toContain('App\\\\Modules\\\\Admin\\\\Models\\\\Admin');
    expect($source)->not->toContain("'App\\\\Modules\\\\Admin\\\\Models\\\\Admin'");
});

test('mk_director.auth block no longer carries the Experimental label', function () {
    // The auth block is no longer experimental — the command exists,
    // AUTH.md documents it, and the security model is consolidated.
    $source = configSource();

    expect($source)->not->toContain('Auth Scope (Experimental)');
    expect($source)->toContain('Auth Scope');
});

test('mk_director.auth block has a comment that names the offending anti-patterns', function () {
    // Future contributors must be able to read the WHY in the config
    // itself, not just in the changelog or PR description.
    $source = configSource();

    expect($source)->toContain('MME');
    expect($source)->toContain('rompe DDD');
    expect($source)->toContain('mk:make:auth-user');
});

test('R-PKG-023 (rc12) response.top_level_extra_data flag exists with env-driven default false', function () {
    // R-PKG-023 introduces an opt-in flag for the top-level __extraData
    // response shape. The flag MUST be env-driven (so consumers can flip
    // it via .env without re-publishing the config) and MUST default to
    // `false` in rc12 (legacy nested shape is the BC default).
    $source = configSource();

    // Block exists.
    expect($source)->toContain("'response'");
    expect($source)->toContain("'top_level_extra_data'");

    // Reads from env, not hardcoded.
    expect($source)->toContain("env('MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA'");
    expect($source)->toContain(', false)');  // default is false

    // NOT hardcoded to `true` (which would be a BC break at GA only).
    expect($source)->not->toMatch("/'top_level_extra_data'\s*=>\s*env\([^,]+,\s*true\s*\)/");
});

test('R-PKG-024 (rc13) cache.allow_full_clear flag exists with env-driven default false', function () {
    // R-PKG-024 introduces a gate for CacheManager::flush() fallback path
    // (nuke all cache when no tags support). MUST be env-driven and MUST
    // default to `false` (safe: no accidental nuke in production).
    $source = configSource();

    // Block + key exists.
    expect($source)->toContain("'allow_full_clear'");

    // Reads from env.
    expect($source)->toContain("env('MK_CACHE_ALLOW_FULL_CLEAR'");
    expect($source)->toContain(', false)');

    // NOT hardcoded to `true`.
    expect($source)->not->toMatch("/'allow_full_clear'\s*=>\s*env\([^,]+,\s*true\s*\)/");
});

test('R-PKG-024 (rc13) debug.explain_enabled flag exists with env-driven default false', function () {
    // R-PKG-024 (T13) introduces a gate for the optional EXPLAIN in
    // BaseController::getDebugData(). Default `false` (safe: no SQL
    // interpolation, no DB::select("EXPLAIN ...") at runtime).
    $source = configSource();

    // The `debug` block was refactored from a flat bool to a nested
    // array (preserves BC for `mk_director.debug` being truthy).
    expect($source)->toContain("'explain_enabled'");

    // Reads from env.
    expect($source)->toContain("env('MK_DIRECTOR_DEBUG_EXPLAIN_ENABLED'");
    expect($source)->toContain(', false)');

    // NOT hardcoded to `true`.
    expect($source)->not->toMatch("/'explain_enabled'\s*=>\s*env\([^,]+,\s*true\s*\)/");
});
