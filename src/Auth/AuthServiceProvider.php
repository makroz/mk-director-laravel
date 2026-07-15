<?php

declare(strict_types=1);

namespace Mk\Director\Auth;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Mk\Director\Auth\Middleware\MkAbility;
use Mk\Director\Auth\Middleware\MkAuthenticate;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mk\Director\Auth\Services\EmailOtpService;
use Mk\Director\Auth\Services\TokenIssuer;

/**
 * Auth subsystem service provider.
 *
 * Registers:
 *  - TokenIssuer (singleton) — issues and revokes Sanctum tokens.
 *  - EmailOtpService (singleton) — generates/verifies email-OTP codes
 *    (2026-07-15-profile-edit-password-otp, ADR-2).
 *  - AuthScopeResolver — validates that the current token's scope matches
 *    the expected one.
 *  - `mk.auth` and `mk.ability` middleware aliases.
 */
class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TokenIssuer::class);
        $this->app->singleton(EmailOtpService::class);
        $this->app->bind(AuthScopeResolver::class, function ($app) {
            return new AuthScopeResolver($app['request']);
        });
    }

    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('mk.auth', MkAuthenticate::class);
        $router->aliasMiddleware('mk.ability', MkAbility::class);
    }
}
