<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lo que Apple pide para revocar el acceso al borrar una cuenta: canjear el
 * `authorization_code` del login por un refresh token, y revocarlo después.
 * https://developer.apple.com/documentation/sign_in_with_apple/revoke_tokens
 *
 * Las dos llamadas se autentican con un `client_secret`: un JWT ES256 firmado
 * con la `.p8`, del Team ID, para el client_id que emitió el token.
 *
 * Cualquier respuesta que no sea un éxito es una {@see RuntimeException}: quien
 * llama decide si eso frena algo (el login no lo frena; el borrado tampoco).
 */
class AppleTokenClient
{
    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    /** El `aud` del `client_secret`: siempre Apple. */
    private const AUDIENCE = 'https://appleid.apple.com';

    /** Vida del `client_secret`: Apple acepta hasta seis meses; acá es de un solo uso. */
    private const SECRET_TTL_SECONDS = 300;

    /** El refresh token que Apple da por el código. */
    public function exchangeCode(AppleSigningKey $key, string $clientId, string $code): string
    {
        $response = Http::asForm()->timeout(5)->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $this->clientSecret($key, $clientId),
            'code' => $code,
            'grant_type' => 'authorization_code',
        ]);

        $token = $response->successful() ? $response->json('refresh_token') : null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException("Apple no canjeó el código (HTTP {$response->status()}).");
        }

        return $token;
    }

    public function revoke(AppleSigningKey $key, string $clientId, string $refreshToken): void
    {
        $response = Http::asForm()->timeout(5)->post(self::REVOKE_URL, [
            'client_id' => $clientId,
            'client_secret' => $this->clientSecret($key, $clientId),
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Apple no revocó el token (HTTP {$response->status()}).");
        }
    }

    public function clientSecret(AppleSigningKey $key, string $clientId): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $key->teamId,
            'iat' => $now,
            'exp' => $now + self::SECRET_TTL_SECONDS,
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ], $key->privateKey, 'ES256', $key->keyId);
    }
}
