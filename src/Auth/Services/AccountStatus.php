<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * La ÚNICA definición de "esta cuenta puede autenticarse".
 *
 * La usan las tres puertas: `BaseAuthController::login()` (y forgot/reset),
 * `TokenIssuer::rotateRefreshToken()` y el middleware `mk.auth`. Antes vivía
 * sólo en el controller y se consultaba sólo al emitir tokens: bloquear a un
 * usuario no cortaba su access token vivo ni su refresh token de 7 días.
 *
 * Regla:
 *  1. `status` casteado a un enum con `canAuthenticate()` (`ScopeStatus` o el
 *     wrapper del scope) → manda el enum.
 *  2. Columna legacy `is_active` → `false`/`0`/`'0'` bloquea; `true`/`null` pasa.
 *  3. Sin ninguna de las dos → pasa (BC para scopes sin estado).
 *
 * `is_active` se lee de los atributos YA cargados, no con `Schema::hasColumn()`:
 * esto corre en cada request autenticado y el chequeo de esquema es una query
 * por request. Login y Sanctum cargan la fila entera, así que la columna, si
 * existe, está en los atributos.
 *
 * Para cambiar qué estados autentican, se override `canAuthenticate()` en el
 * enum del scope — no acá: así las tres puertas siguen viendo la misma regla.
 */
final class AccountStatus
{
    public static function allowsAuthentication(Authenticatable $user): bool
    {
        if (isset($user->status) && is_object($user->status) && method_exists($user->status, 'canAuthenticate')) {
            return (bool) $user->status->canAuthenticate();
        }

        $isActive = method_exists($user, 'getAttributes') ? ($user->getAttributes()['is_active'] ?? null) : null;

        return ! ($isActive === false || $isActive === 0 || $isActive === '0');
    }
}
