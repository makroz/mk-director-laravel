<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Enums;

/**
 * Por qué se rechazó una prueba DPoP (DEVELOPER_GUIDE § 3.24). El valor viaja
 * en `__extraData.reason` del 401; el código (`__extraData.code`) lo da
 * {@see self::errorCode()}.
 */
enum DpopFailure: string
{
    /** No vino el header `DPoP` y hacía falta. */
    case Missing = 'missing';

    /** Vino más de un header `DPoP`. */
    case Multiple = 'multiple';

    /** No es un JWT de tres partes con JSON adentro, o le falta el `jti`. */
    case Malformed = 'malformed';

    /** `typ` distinto de `dpop+jwt`. */
    case Type = 'typ';

    /** `alg` distinto de `ES256` (también `none` y `HS256`). */
    case Algorithm = 'alg';

    /** Sin `jwk`, o no es una clave pública EC P-256 válida. */
    case Key = 'jwk';

    /** La `jwk` trae la parte privada (`d`). */
    case PrivateKey = 'jwk_private';

    /** La firma no verifica con la `jwk` del header. */
    case Signature = 'signature';

    /** `htm` distinto del método del pedido. */
    case Method = 'htm';

    /** La ruta de `htu` distinta de la del pedido. */
    case Path = 'htu';

    /** `iat` fuera de la ventana (en los dos sentidos): el 401 lleva `server_time`. */
    case IssuedAt = 'iat';

    /** `ath` ausente o distinto del hash del access token. */
    case AccessTokenHash = 'ath';

    /** La prueba es buena pero de OTRA clave que la que tiene atada el token. */
    case KeyMismatch = 'key_mismatch';

    /** El `jti` ya se usó: es una prueba repetida. */
    case Replayed = 'replayed';

    /** El scope exige DPoP y el token no está atado a ninguna clave. */
    case Unbound = 'unbound';

    public function errorCode(): string
    {
        return match ($this) {
            self::Missing => 'ERR_DPOP_REQUIRED',
            self::Unbound => 'ERR_DPOP_UNBOUND',
            default => 'ERR_DPOP_INVALID',
        };
    }
}
