<?php

declare(strict_types=1);

namespace Mk\Director\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * MkAuthMiddleware
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
