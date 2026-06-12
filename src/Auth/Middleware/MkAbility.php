<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ability check middleware.
 *
 * Usage:
 *   Route::middleware(['mk.ability:users.edit,users.delete'])->group(...);  // OR semantics
 *   Route::middleware(['mk.ability:users.edit', 'mk.ability:users.delete']); // AND
 */
class MkAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // OR semantics: any of the listed abilities is enough.
        foreach ($abilities as $ability) {
            // is_callable (no method_exists) para soportar Mockery en tests.
            $granted = is_callable([$user, 'canMk'])
                ? (bool) $user->canMk($ability)
                : (method_exists($user, 'can') ? (bool) $user->can($ability) : false);

            if ($granted) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
