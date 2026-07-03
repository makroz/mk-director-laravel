<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

/**
 * Excepción lanzada cuando un refresh token es inválido, expirado,
 * o no coincide con el scope esperado.
 *
 * R-PKG-014 BUG-07 fix.
 */
class InvalidRefreshTokenException extends \Illuminate\Auth\Access\AuthorizationException
{
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
}