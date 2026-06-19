<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ability check middleware.
 *
 * Usage:
 *   Route::middleware(['mk.ability:users.edit,users.delete'])->group(...);  // OR semantics
 *   Route::middleware(['mk.ability:users.edit', 'mk.ability:users.delete']); // AND
 *
 * Resolution priority (R2-002):
 *   1. Sanctum token's `tokenCan($ability)` — short-circuits before any
 *      DB-backed authz check. Lets consumers encode granular abilities
 *      into the access token and avoid the role/direct-grants path.
 *   2. User's `canMk($ability)` — the package's RBAC-aware ability check
 *      (HasAbilities trait, now cached + invalidated via AbilityResolver).
 *   3. Laravel's stock `$user->can($ability)` — fallback for users that
 *      do not use the trait.
 *
 * Empty-abilities defense (R2-003):
 *   If the middleware is invoked with NO abilities (e.g. someone
 *   registered `Route::middleware(['mk.ability:'])`, an empty string,
 *   or only commas), the request is rejected with HTTP 500 + error
 *   code `ERR_MIDDLEWARE_MISCONFIGURED`. Allowing an empty-abilities
 *   check to silently pass is a privilege-escalation trap: the route
 *   would be unguarded while looking configured.
 */
class MkAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): mixed
    {
        // R2-003: reject empty ability lists. Splitting on comma handles
        // both the variadic case ('users.edit,users.delete' → 2 args) and
        // the case where someone passes an empty string by mistake.
        $normalized = [];
        foreach ($abilities as $raw) {
            foreach (explode(',', $raw) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $normalized[] = $piece;
                }
            }
        }

        if ($normalized === []) {
            return new JsonResponse([
                'success' => false,
                'error' => 'ERR_MIDDLEWARE_MISCONFIGURED',
                'message' => 'MkAbility middleware requires at least one ability.',
            ], 500);
        }

        $user = $request->user();

        if ($user === null) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // OR semantics: any of the listed abilities is enough.
        foreach ($normalized as $ability) {
            // R2-002: Sanctum token short-circuit. PersonalAccessToken
            // exposes can(string): bool via the HasApiTokens contract.
            if (method_exists($user, 'currentAccessToken')) {
                $token = $user->currentAccessToken();
                if ($token !== null && method_exists($token, 'can')) {
                    try {
                        if ((bool) $token->can($ability) === true) {
                            return $next($request);
                        }
                    } catch (\Throwable) {
                        // Token without a working can() — fall through to
                        // the role/direct-grants path.
                    }
                }
            }

            // canMk() handles the role + direct-grants path (now via the
            // AbilityResolver cache). is_callable() so Mockery users work.
            $granted = is_callable([$user, 'canMk'])
                ? (bool) $user->canMk($ability)
                : (method_exists($user, 'can') ? (bool) $user->can($ability) : false);

            if ($granted) {
                return $next($request);
            }
        }

        return new JsonResponse([
            'success' => false,
            'message' => 'Forbidden.',
        ], 403);
    }
}