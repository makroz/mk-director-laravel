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
 *  4. Los chequeos extra del consumidor (ver abajo) pueden negar, nunca conceder.
 *
 * `is_active` se lee de los atributos YA cargados, no con `Schema::hasColumn()`:
 * esto corre en cada request autenticado y el chequeo de esquema es una query
 * por request. Login y Sanctum cargan la fila entera, así que la columna, si
 * existe, está en los atributos.
 *
 * Para cambiar qué estados autentican, se override `canAuthenticate()` en el
 * enum del scope — no acá: así las tres puertas siguen viendo la misma regla.
 *
 * ── 🔴 LOS CHEQUEOS EXTRA DEL CONSUMIDOR ────────────────────────────────
 *
 * `mk_director.auth.account_checks` es una lista de clases invocables
 * `(Authenticatable $user): bool`. Todas tienen que decir que sí.
 *
 * Existe porque "puede autenticarse" no siempre depende sólo de la fila del
 * usuario. El caso que lo pidió: un SaaS donde se suspende a la EMPRESA, y esa
 * suspensión tiene que cortarles a todos sus usuarios — el que está entrando y
 * el que ya tiene un token vivo. Sin este punto, el consumidor tenía que repetir
 * la misma condición en el login, en el refresh y en el middleware, y una
 * decisión repetida en tres lugares es una decisión que algún día va a estar
 * puesta en dos.
 *
 * Reglas del punto de extensión:
 *
 *  - Sólo pueden NEGAR. Un chequeo que devuelve `true` no rehabilita a un
 *    usuario que su propio `status` ya bloqueó: si alcanzara para conceder,
 *    agregar un chequeo podría abrirle la puerta a alguien bloqueado.
 *  - Corren DESPUÉS del estado propio, que no cuesta una sola query. A un
 *    usuario ya bloqueado no se le consulta la empresa.
 *  - Corren en CADA request autenticado. El chequeo que consulte la base
 *    debería cachear (registrarlo como singleton alcanza).
 *  - Si uno revienta, la excepción sube. No se atrapa a propósito: un chequeo
 *    de seguridad que falla en silencio es un chequeo que no está.
 *
 * ── EL MOTIVO DE LA NEGATIVA ────────────────────────────────────────────
 *
 * {@see denialReason()} dice POR QUÉ niega, para que el login se lo muestre a
 * quien ya probó la contraseña. El enum del estado y cada chequeo extra pueden
 * declarar un `denialMessage(): string` con su texto; sin él, sale
 * {@see DEFAULT_DENIAL}. El motivo NUNCA se muestra antes de verificar la
 * contraseña: ahí sería un oráculo para saber qué cuentas están bloqueadas.
 */
final class AccountStatus
{
    public const DEFAULT_DENIAL = 'Tu cuenta está deshabilitada.';

    public static function allowsAuthentication(Authenticatable $user): bool
    {
        return self::denialReason($user) === null;
    }

    /**
     * Por qué la cuenta no puede autenticarse, o `null` si puede.
     */
    public static function denialReason(Authenticatable $user): ?string
    {
        if (! self::ownStatusAllows($user)) {
            return self::messageOf($user->status ?? null);
        }

        foreach (self::extraChecks() as $check) {
            if (! $check($user)) {
                return self::messageOf($check);
            }
        }

        return null;
    }

    private static function messageOf(mixed $source): string
    {
        if (is_object($source) && method_exists($source, 'denialMessage')) {
            $message = $source->denialMessage();

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return self::DEFAULT_DENIAL;
    }

    /**
     * El estado de la propia fila del usuario: enum del scope, o la columna
     * legacy `is_active`, o nada (BC).
     */
    private static function ownStatusAllows(Authenticatable $user): bool
    {
        if (isset($user->status) && is_object($user->status) && method_exists($user->status, 'canAuthenticate')) {
            return (bool) $user->status->canAuthenticate();
        }

        $isActive = method_exists($user, 'getAttributes') ? ($user->getAttributes()['is_active'] ?? null) : null;

        return ! ($isActive === false || $isActive === 0 || $isActive === '0');
    }

    /**
     * Los chequeos configurados, ya resueltos del contenedor.
     *
     * Sin contenedor (un test unitario que no levantó Laravel) la lista es
     * vacía: el comportamiento es exactamente el de antes de que este punto
     * existiera.
     *
     * @return array<int, callable(Authenticatable): bool>
     */
    private static function extraChecks(): array
    {
        if (! function_exists('app') || ! function_exists('config')) {
            return [];
        }

        try {
            $configured = (array) config('mk_director.auth.account_checks', []);
        } catch (\Throwable) {
            return [];
        }

        $checks = [];

        foreach ($configured as $check) {
            if (is_callable($check)) {
                $checks[] = $check;

                continue;
            }

            if (is_string($check) && $check !== '') {
                $checks[] = app($check);
            }
        }

        return $checks;
    }
}
