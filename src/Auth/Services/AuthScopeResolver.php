<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Exceptions\ScopeMismatchException;

/**
 * AuthScopeResolver — valida que el token Sanctum actual pertenece al
 * scope esperado.
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.4 / MK-LAR-1.0.6 (Capa 3).
 *
 * Defensa-en-profundidad: aunque la ruta use el guard correcto, el
 * resolver chequea `auth_scope` desde la ability `auth_scope:X` que
 * viene en el payload del access token. Si no matchea, lanza
 * ScopeMismatchException (401) y loggea intento.
 */
class AuthScopeResolver
{
    public function __construct(
        private readonly ?Request $request = null,
    ) {
    }

    /**
     * @throws ScopeMismatchException
     */
    public function resolve(string $expectedScope): ?Authenticatable
    {
        $user = Auth::user();

        if (! $user instanceof Authenticatable) {
            $this->logMismatch($expectedScope, null, 'no_user');
            throw new ScopeMismatchException(
                expectedScope: $expectedScope,
                actualScope: null,
            );
        }

        $currentToken = $user->currentAccessToken();

        if (! $currentToken instanceof PersonalAccessToken) {
            $this->logMismatch($expectedScope, $this->safeScope($user), 'no_token');
            throw new ScopeMismatchException(
                expectedScope: $expectedScope,
                actualScope: $this->safeScope($user),
            );
        }

        $actualScope = TokenIssuer::extractScopeFromAbilities($currentToken->abilities);

        if ($actualScope === null) {
            $this->logMismatch($expectedScope, null, 'no_scope_ability');
            throw new ScopeMismatchException(
                expectedScope: $expectedScope,
                actualScope: null,
            );
        }

        if (! hash_equals($expectedScope, $actualScope)) {
            $this->logMismatch($expectedScope, $actualScope, 'mismatch');
            throw new ScopeMismatchException(
                expectedScope: $expectedScope,
                actualScope: $actualScope,
            );
        }

        return $user;
    }

    private function safeScope(Authenticatable $user): ?string
    {
        if (method_exists($user, 'getAuthScope')) {
            $scope = $user->getAuthScope();
            return is_string($scope) ? $scope : null;
        }

        return null;
    }

    private function logMismatch(string $expected, ?string $actual, string $reason): void
    {
        try {
            if (class_exists(Log::class) && function_exists('app') && app()->bound('log')) {
                Log::warning('auth.scope_mismatch', [
                    'expected_scope' => $expected,
                    'actual_scope' => $actual,
                    'reason' => $reason,
                    'user_id' => Auth::id(),
                ]);
            }
        } catch (\Throwable) {
            // En unit tests sin app, swallow log para no romper.
        }
    }
}
