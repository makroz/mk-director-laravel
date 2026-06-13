<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scope-aware Sanctum middleware.
 *
 * Usage:
 *   Route::middleware(['mk.auth:admin'])->group(...);
 *
 * Validates the current Sanctum token and ensures its `auth_scope`
 * matches the parameter. Mismatches → 401 ScopeMismatchException.
 */
class MkAuthenticate
{
    public function __construct(
        private readonly AuthScopeResolver $resolver,
    ) {
    }

    public function handle(Request $request, Closure $next, string $scope = 'admin'): Response
    {
        // Resolve the current user via Sanctum.
        // If no token, abort 401.
        $user = \Illuminate\Support\Facades\Auth::guard($scope)->user();
        if ($user === null) {
            throw new MissingAbilityException('Unauthenticated.');
        }

        \Illuminate\Support\Facades\Auth::shouldUse($scope);

        // The resolver validates the scope; throws on mismatch.
        $this->resolver->resolve($scope);

        return $next($request);
    }
}
