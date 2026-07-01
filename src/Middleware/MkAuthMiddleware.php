<?php

declare(strict_types=1);

namespace Mk\Director\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MkAuthMiddleware (DEPRECATED — use MkAuthenticate instead).
 *
 * **@deprecated** since v1.9.0 (R-PKG-042 FASE17-02). Use
 * `Mk\Director\Auth\Middleware\MkAuthenticate` (scope-aware, envelope
 * canónico R-PKG-024) en su lugar.
 *
 * **Por qué se deprecó**: este middleware es scope-agnostic (no valida
 * el `auth_scope` del token) y no pine el envelope canónico R-PKG-024.
 * El nuevo canónico (`MkAuthenticate`) es scope-aware, pinea envelope
 * consistente, y es el único que los scaffolders nuevos pinean. Este
 * queda BC para consumers legacy hasta v2.0.0.
 *
 * **Migration path**:
 *   1. Buscar todos los call sites de `MkAuthMiddleware` en routes/api.php
 *      del consumer: `grep -r "MkAuthMiddleware" app/Modules/`.
 *   2. Reemplazar por `MkAuthenticate:{scope}` (scope explícito):
 *      ```php
 *      // Antes:
 *      Route::middleware([MkAuthMiddleware::class])->group(...)
 *
 *      // Después:
 *      Route::middleware(['mk.auth:{scope}'])->group(...)
 *      ```
 *   3. Si el consumer pineó un custom `Handler::render()` que matchea
 *      `AuthenticationException`, no necesita cambios — el response 401
 *      del nuevo middleware sigue disparando la exception para web routes.
 *
 * **Slated for removal**: v2.0.0 (Q1 2027). Después de eso, este archivo
 * se mueve a `src/_deprecated/` y se publica con un warning en CHANGELOG.
 *
 * Middleware base para la autenticación en MK-Director.
 * Retorna respuestas JSON nativas de Laravel si no hay sesión activa.
 */
class MkAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|null  ...$guards
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                // Autenticado
                return $next($request);
            }
        }

        // Si es una petición API o espera JSON, retornar formato estándar MK-API
        if ($request->expectsJson() || $request->is('api/*')) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unauthenticated.',
                'code'    => 'ERR_UNAUTHENTICATED'
            ], 401);
        }

        // De lo contrario redirigir al login (configurable por aplicación)
        return redirect()->route('login');
    }
}
