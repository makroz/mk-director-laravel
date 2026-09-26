<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

/**
 * Lo que un ID token de Google o Apple prueba sobre quién lo presenta, YA
 * verificado por {@see IdTokenVerifier}: firma, emisor, audiencia y vigencia.
 *
 * `subject` (el `sub` del token) es la identidad estable del proveedor. El
 * email NO lo es: el usuario lo puede cambiar, y Apple puede entregar una
 * dirección de relay privada. Por eso el vínculo se guarda por
 * `(provider, subject)` y el email viaja sólo como dato.
 *
 * 🔴 `emailVerified` es lo único que separa «el proveedor probó que esta
 * persona controla ese buzón» de «alguien escribió ese email en su cuenta».
 * Vincular por email sin mirarlo es entregarle la cuenta a quien registre el
 * email ajeno en el proveedor (ver `BaseAuthController::socialAutoLinkByEmail()`).
 */
final readonly class SocialIdentity
{
    /**
     * @param  array<string, mixed>  $claims  todos los claims del token, por si el consumer necesita otro (`name`, `picture`, `is_private_email`…)
     */
    public function __construct(
        public string $provider,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public array $claims,
    ) {}
}
