<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mk\Director\Auth\Exceptions\ScopeMismatchException;
use Mk\Director\Auth\Services\AuthScopeResolver;
use Mk\Director\Tenancy\TenantMembershipGate;
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
        private readonly TenantMembershipGate $tenantGate,
    ) {}

    public function handle(Request $request, Closure $next, string $scope = 'admin'): Response
    {
        // Resolve the current user via Sanctum.
        //
        // R-PKG-046 F9-B11 fix — Cross-scope attack detection:
        //
        // Pre-fix, el path era:
        //   1. `$user = Auth::guard($scope)->user();` → null si el token
        //      pertenece a OTRO scope (e.g. admin token atacando `/api/member/*`).
        //   2. `return $this->unauthorizedResponse(...)` → `ERR_UNAUTHENTICATED`.
        //
        // El problema: el cliente no podía distinguir "no estoy autenticado"
        // de "estoy autenticado con el scope equivocado". El spec de FEEDBACK7
        // y `docs/AUTH.md` promete `ERR_SCOPE_MISMATCH` cuando el token pertenece
        // a otro scope. La razón del bug es que `Auth::guard('member')->user()`
        // retornaba null (el admin user no existe en la tabla `members`), entonces
        // el middleware rechazaba con `ERR_UNAUTHENTICATED` ANTES de validar el
        // scope del token.
        //
        // Post-fix: si `Auth::guard($scope)->user()` retorna null, intentar
        // con `Auth::guard()` (default guard, scope-agnostic). Si el default
        // retorna un user, comparar su `auth_scope` con el scope pedido:
        //   - match → `ERR_SCOPE_MISMATCH` con `actual_scope`.
        //   - mismatch (otro scope) → `ERR_SCOPE_MISMATCH` con `actual_scope`.
        // Si el default también retorna null → `ERR_UNAUTHENTICATED` (BC:
        // genuinamente no autenticado).
        $user = Auth::guard($scope)->user();

        if ($user === null) {
            // Defense-in-depth: re-intentar con el guard default scope-agnostic
            // para detectar tokens de OTRO scope antes de descartar como
            // "unauthenticated".
            $defaultUser = Auth::guard()->user();
            if ($defaultUser !== null && method_exists($defaultUser, 'getAuthScope')) {
                $actualScope = $defaultUser->getAuthScope();

                // El user existe pero pertenece a OTRO scope → scope mismatch
                // attack (e.g. admin token atacando `/api/member/*`).
                return $this->unauthorizedResponse(
                    $request,
                    $scope,
                    message: "Token belongs to scope `{$actualScope}`, but this route requires `{$scope}`.",
                    code: 'ERR_SCOPE_MISMATCH',
                    extraData: ['actual_scope' => $actualScope],
                );
            }

            // Genuinamente no autenticado (token inválido o no presente).
            return $this->unauthorizedResponse($request, $scope);
        }

        Auth::shouldUse($scope);

        // The resolver validates the scope; throws on mismatch.
        //
        // F1.2 (LAR-03 HIGH): ScopeMismatchException (token de otro scope /
        // no_token / no_scope_ability) DEBE traducirse a 401 envelope canónico
        // acá. Pre-fix, solo se catcheaba AuthenticationException, pero
        // ScopeMismatchException extiende RuntimeException (no AuthenticationException),
        // así que la exception burbujeaba como 500 genérico de Laravel y
        // rompía el contrato del package ("Mismatches → 401 ScopeMismatchException").
        try {
            $this->resolver->resolve($scope);
        } catch (ScopeMismatchException $e) {
            return $this->unauthorizedResponse(
                $request,
                $scope,
                $e->getMessage(),
                'ERR_SCOPE_MISMATCH',
                ['actual_scope' => $e->actualScope],
            );
        } catch (AuthenticationException $e) {
            return $this->unauthorizedResponse($request, $scope, $e->getMessage());
        }

        // 🔴 AISLAMIENTO MULTI-TENANT — acá y no en otro lado.
        //
        // `TenantResolver` va en el grupo `api`, y el middleware de GRUPO corre
        // ANTES que el de RUTA. Cuando el resolver validaba la membresía,
        // Sanctum todavía no había resuelto el token: `$request->user()` salía
        // por el guard default (`web`) y devolvía null, así que el chequeo
        // entero —que vivía dentro de un `if ($user !== null)`— no se ejecutaba
        // NUNCA. Un admin del tenant A mandaba `X-Tenant-ID: <B>` y recibía 200
        // con los datos de B.
        //
        // Llamar al gate desde acá es una garantía ESTRUCTURAL, no una
        // convención: corre en la misma función que produce el `$user`, así que
        // ningún orden de middleware puede saltearlo. Y corre ANTES de
        // `$next($request)`: un 403 emitido después llegaría tarde, porque un
        // POST ya habría escrito en el tenant ajeno.
        //
        // Con `mk_director.tenant.enabled = false` el gate devuelve null en la
        // primera línea — las apps single-tenant no pagan nada.
        if ($denied = $this->tenantGate->check($request, $user)) {
            return $denied;
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
    /**
     * @param  array<string,mixed>  $extraData  extras opcionales que se mergean dentro
     *                                          de `__extraData` (ej: actual_scope para
     *                                          distinguir scope mismatch de no-auth).
     */
    private function unauthorizedResponse(
        Request $request,
        string $scope,
        ?string $message = null,
        string $code = 'ERR_UNAUTHENTICATED',
        array $extraData = [],
    ): JsonResponse {
        // API request: retornar JsonResponse con envelope canónico.
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'message' => $message ?? 'Unauthenticated.',
                'data' => null,
                '__extraData' => array_merge(
                    [
                        'auth_scope' => $scope,
                        'code' => $code,
                    ],
                    $extraData,
                ),
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
