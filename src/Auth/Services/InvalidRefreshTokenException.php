<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Excepción lanzada cuando un refresh token es inválido, expirado,
 * o no coincide con el scope esperado.
 *
 * R-PKG-014 BUG-07 fix.
 *
 * `$errorCode` es el `__extraData.code` que devuelve `BaseAuthController::refresh()`.
 */
class InvalidRefreshTokenException extends AuthorizationException
{
    public string $errorCode = 'ERR_UNAUTHENTICATED';

    public static function malformed(): self
    {
        return new self('Refresh token malformed (expected `<id>|<plaintext>` format).');
    }

    public static function notFound(): self
    {
        return new self('Refresh token not found.');
    }

    public static function hashMismatch(): self
    {
        return new self('Refresh token hash mismatch.');
    }

    public static function expired(): self
    {
        return new self('Refresh token expired.');
    }

    public static function scopeMismatch(string $expected, string $actual): self
    {
        return new self("Refresh token scope mismatch: expected `{$expected}`, got `{$actual}`.");
    }

    /** Un access token (u otro token sin la ability `refresh`) no refresca. */
    public static function notARefreshToken(): self
    {
        return new self('Token is not a refresh token.');
    }

    /**
     * La cuenta ya no puede autenticarse (bloqueada, inactiva, pendiente).
     * `$reason` es el de `AccountStatus::denialReason()`: el front lo muestra
     * en el login al cortar la sesión.
     */
    public static function accountDisabled(string $reason = AccountStatus::DEFAULT_DENIAL): self
    {
        $e = new self($reason);
        $e->errorCode = 'ERR_ACCOUNT_DISABLED';

        return $e;
    }
}
