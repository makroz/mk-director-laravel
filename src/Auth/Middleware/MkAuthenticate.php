<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
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
            throw new AuthenticationException('Unauthenticated.', [$scope]);
        }

        \Illuminate\Support\Facades\Auth::shouldUse($scope);

        // The resolver validates the scope; throws on mismatch.
        $this->resolver->resolve($scope);

        // Eager-load the relationship graph used by every downstream authz
        // check (canMk, policies, ability middleware). Without this, each
        // check re-queries the same roles/abilities pivot and triggers
        // an N+1 (audit R4-002).
        //
        // loadMissing() is a no-op when the relations are already loaded,
        // so this is safe to call even if the resolver already pre-loaded.
        if (method_exists($user, 'loadMissing')) {
            $user->loadMissing(['roles.abilities', 'directAbilities']);
        }

        return $next($request);
    }
}
