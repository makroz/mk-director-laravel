<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

/**
 * Helper para parsear tokens Sanctum v4 formato `<id>|<plaintext>`.
 *
 * Sanctum v4 ya NO expone `Sanctum::findToken()` para buscar tokens por
 * plaintext (porque no se puede hashear el plaintext en DB — está hasheado
 * con `Hash::make()`). Hay que separar manualmente el prefijo `id|`.
 *
 * R-PKG-014 BUG-07 fix: usado por `TokenIssuer::rotateRefreshToken()`
 * y por el AuthController generado.
 *
 * @see https://laravel.com/docs/11.x/sanctum#hashing-tokens
 */
class RefreshTokenParser
{
    /**
     * Separa un refresh_token en sus componentes `<id>` y `<plaintext>`.
     *
     * @return array{int, string}  [token_id, plaintext]
     *
     * @throws InvalidRefreshTokenException Si el formato es inválido.
     */
    public function parse(string $token): array
    {
        if (! str_contains($token, '|')) {
            throw InvalidRefreshTokenException::malformed();
        }

        $parts = explode('|', $token, 2);
        if (count($parts) !== 2) {
            throw InvalidRefreshTokenException::malformed();
        }

        [$id, $plaintext] = $parts;

        if (! ctype_digit($id) || (int) $id <= 0) {
            throw InvalidRefreshTokenException::malformed();
        }

        if ($plaintext === '' || strlen($plaintext) < 16) {
            throw InvalidRefreshTokenException::malformed();
        }

        return [(int) $id, $plaintext];
    }
}