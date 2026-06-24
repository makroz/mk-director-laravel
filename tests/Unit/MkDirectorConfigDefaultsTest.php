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
