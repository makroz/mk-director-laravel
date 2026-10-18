<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

/**
 * Por qué una cuenta no puede autenticarse: el texto, el código y —si quien
 * negó lo pide— el estado HTTP.
 *
 * Lo arma {@see AccountStatus::denial()}. `status` en `null` deja que cada
 * puerta use el suyo: 403 en el login (la contraseña ya se probó), 401 en un
 * request y en el refresh.
 */
final class AccountDenial
{
    public function __construct(
        public readonly string $message,
        public readonly string $code,
        public readonly ?int $status = null,
    ) {}
}
