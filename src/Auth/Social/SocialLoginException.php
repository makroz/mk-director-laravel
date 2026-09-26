<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

use RuntimeException;

/**
 * Por qué no se pudo resolver un login con Google o Apple.
 *
 * Una sola clase con `reason` en vez de tres excepciones: el consumer que
 * llama al verificador desde su propia ruta ramifica por un string, igual que
 * el front ramifica por `__extraData.code`.
 *
 * 🔴 El `getMessage()` de `INVALID_TOKEN` dice QUÉ chequeo falló (audiencia,
 * emisor, firma…). Es para el log, no para la respuesta: `BaseAuthController`
 * contesta un mensaje genérico, porque detallar el motivo le enseña a quien
 * fabrica tokens qué parte ya le salió bien.
 */
final class SocialLoginException extends RuntimeException
{
    /** El proveedor no existe, está apagado o no tiene client ids configurados. */
    public const PROVIDER_DISABLED = 'provider_disabled';

    /** El token no pasó la verificación (firma, emisor, audiencia, vigencia, nonce). */
    public const INVALID_TOKEN = 'invalid_token';

    /** No se pudieron bajar las claves públicas del proveedor. Es un 503, no culpa del cliente. */
    public const KEYS_UNAVAILABLE = 'keys_unavailable';

    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function providerDisabled(string $provider): self
    {
        return new self(self::PROVIDER_DISABLED, "El proveedor '{$provider}' no está habilitado.");
    }

    public static function invalidToken(string $detail): self
    {
        return new self(self::INVALID_TOKEN, $detail);
    }

    public static function keysUnavailable(string $provider): self
    {
        return new self(self::KEYS_UNAVAILABLE, "No se pudieron obtener las claves públicas de '{$provider}'.");
    }
}
