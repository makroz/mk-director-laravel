<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/**
 * La clave de «Sign in with Apple» de la cuenta de desarrollador: con ella se
 * firma el `client_secret` que Apple pide para canjear un código y para
 * revocar un token.
 *
 * 🔴 `privateKey` es el contenido del `.p8` (`-----BEGIN PRIVATE KEY-----`):
 * un secreto. No se loguea ni se devuelve en ninguna respuesta.
 */
final readonly class AppleSigningKey
{
    public function __construct(
        public string $teamId,
        public string $keyId,
        public string $privateKey,
    ) {}
}
