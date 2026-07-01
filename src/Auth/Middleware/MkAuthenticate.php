<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scope-aware Sanctum middleware (R-PKG-024 + R-PKG-042 FASE17-02 envelope hardening).
 *
 * **Canonical auth middleware for mk-director**. Use this for any new route
 * that requires auth. The legacy scope-agnostic `MkAuthMiddleware` and the
 * `MkAbility` middleware were REMOVED in v2.0.0 — see the
 * `UPGRADE_2.0.md` migration guide in the package docs.
 *
 * Usage:
 *   Route::middleware(['mk.auth:admin'])->group(...);
 *
 * Validates the current Sanctum token and ensures its `auth_scope`
 * matches the parameter. Mismatches → 401 ScopeMismatchException.
 *
 * **Envelope (R-PKG-042 FASE17-02 fix)**: el response 401 ahora usa el
 * envelope canónico R-PKG-024 (`{success, message, data, debugMsg}`) cuando
 * `$request->expectsJson()` o `$request->is('api/*')`. Para web routes
 * (`!expectsJson() && !is('api/*')`), mantiene el comportamiento legacy
 * de `AuthenticationException` (Laravel redirect a `/login` via Handler::unauthenticated).
 *
 * **BC**: pre-R-PKG-042 el response era `AuthenticationException` puro (Laravel
 * default → 401 HTML en API). El nuevo shape envelope es estrictamente más
 * expresivo (más campos, mismo status code) y el frontend ya consume el
 * envelope canónico para todos los demás responses, así que el cambio es
 * additive. Si el consumer pineó un custom `Handler::render()` que matchea
 * `AuthenticationException`, ese override gana (Laravel render pipeline
 * respeta el custom).
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
            return $this->unauthorizedResponse($request, $scope);
        }

        \Illuminate\Support\Facades\Auth::shouldUse($scope);

        // The resolver validates the scope; throws on mismatch.
        try {
            $this->resolver->resolve($scope);
        } catch (AuthenticationException $e) {
            return $this->unauthorizedResponse($request, $scope, $e->getMessage());
        }

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

    /**
     * R-PKG-042 FASE17-02 fix: 401 response con envelope canónico R-PKG-024
     * para api/* + expectsJson. Web routes (no api, no JSON) mantienen el
     * legacy behavior (LANZAR `AuthenticationException` para que Laravel
     * redirija a /login via Handler::unauthenticated).
     *
     * **BC**: pre-R-PKG-042 el middleware siempre lanzaba `AuthenticationException`.
     * El nuevo path API retorna `JsonResponse` con envelope. El path web
     * sigue lanzando la exception — CERO BC break para consumers que
     * tienen `Handler::unauthenticated()` con redirect a /login.
     *
     * **Razón del split**: los consumers web (no API) ya tienen un flow
     * de redirect a /login que pinearon en el Handler::unauthenticated
     * (típico de Laravel 11+). Romper eso sería BC break innecesario.
     * El cambio SOLO aplica a API routes, que es donde el envelope
     * canónico se consume.
     */
    private function unauthorizedResponse(Request $request, string $scope, ?string $message = null): JsonResponse
    {
        // API request: retornar JsonResponse con envelope canónico.
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'message' => $message ?? 'Unauthenticated.',
                'data' => null,
                '__extraData' => [
                    'auth_scope' => $scope,
                    'code' => 'ERR_UNAUTHENTICATED',
                ],
                'debugMsg' => [],
            ], 401);
        }

        // Web request: LANZAR AuthenticationException (legacy BC).
        // Laravel Handler::unauthenticated() la captura y redirige a /login.
        // Lanzamos desde acá en vez de retornar para mantener el contrato
        // original del middleware (el caller espera que handle tire en el
        // path no-auth, no que retorne una exception).
        throw new AuthenticationException($message ?? 'Unauthenticated.', [$scope]);
    }
}
