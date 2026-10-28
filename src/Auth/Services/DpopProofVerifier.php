<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Mk\Director\Auth\Enums\DpopFailure;
use Mk\Director\Auth\Exceptions\DpopProofException;

/**
 * Verifica la prueba DPoP de un pedido (RFC 9449, adaptado; DEVELOPER_GUIDE
 * § 3.24) y devuelve la huella de su clave (RFC 7638).
 *
 * La prueba es un JWT que el cliente firma con una clave EC que nunca sale de
 * su dispositivo. Un token atado a esa huella (`dpop_jkt:` en sus abilities)
 * no sirve copiado: sin la clave privada no hay prueba.
 *
 * Se verifica a mano y no con `JWT::decode()`: `decode()` rechaza un `iat`
 * apenas en el futuro (su `$leeway` es estático y global), y la ventana acá es
 * simétrica. `firebase/php-jwt` se usa para la `jwk` (`JWK::parseKey`) y el
 * base64url.
 *
 * Desvíos del RFC, decididos:
 *  - `htu`: se compara SÓLO la ruta, no el esquema, el host ni la query. Detrás
 *    de un proxy (TLS terminado, otro host interno) la URL que ve el servidor
 *    no es la que firmó el cliente; la ruta sí se conserva. Un proxy que
 *    REESCRIBE la ruta (quita un prefijo) rompe la comparación.
 *  - El token sigue viajando como `Authorization: Bearer` (no `DPoP`): la
 *    exigencia sale de la ability del token, así que mandarlo como Bearer sin
 *    prueba no es un downgrade posible.
 *
 * Cada rechazo es un {@see DpopFailure} distinto.
 */
class DpopProofVerifier
{
    public const HEADER = 'DPoP';

    public const TYPE = 'dpop+jwt';

    public const ALGORITHM = 'ES256';

    /**
     * ¿El scope EXIGE la prueba? (`mk_director.auth.dpop.scopes`). Un token ya
     * atado la exige siempre: eso lo decide quien lo lee, no esta lista.
     */
    public static function requiredFor(string $scope): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('config')) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        return in_array($scope, (array) config('mk_director.auth.dpop.scopes', []), true);
    }

    /**
     * @param  string|null  $accessToken  el token TAL CUAL vino en el
     *                                    `Authorization`; con él se exige `ath`.
     * @param  string|null  $expectedJkt  la huella que tiene atada el token;
     *                                    con ella, otra clave es `KeyMismatch`.
     * @return string la huella (RFC 7638) de la clave de la prueba
     *
     * @throws DpopProofException
     */
    public function verify(Request $request, ?string $accessToken = null, ?string $expectedJkt = null): string
    {
        $proofs = $request->headers->all(strtolower(self::HEADER));
        if ($proofs === []) {
            throw new DpopProofException(DpopFailure::Missing, 'DPoP proof required.');
        }
        if (count($proofs) !== 1) {
            throw new DpopProofException(DpopFailure::Multiple, 'Exactly one DPoP proof is allowed.');
        }

        $parts = explode('.', (string) $proofs[0]);
        if (count($parts) !== 3) {
            throw new DpopProofException(DpopFailure::Malformed, 'DPoP proof is not a JWT.');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $claims = json_decode(JWT::urlsafeB64Decode($parts[1]), true, 16, JSON_THROW_ON_ERROR);
            $signature = JWT::urlsafeB64Decode($parts[2]);
        } catch (\Throwable) {
            throw new DpopProofException(DpopFailure::Malformed, 'DPoP proof is not a JWT.');
        }
        if (! is_array($header) || ! is_array($claims)) {
            throw new DpopProofException(DpopFailure::Malformed, 'DPoP proof is not a JWT.');
        }

        if (($header['typ'] ?? null) !== self::TYPE) {
            throw new DpopProofException(DpopFailure::Type, 'DPoP proof typ must be dpop+jwt.');
        }
        if (($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new DpopProofException(DpopFailure::Algorithm, 'DPoP proof alg must be ES256.');
        }

        $jwk = $header['jwk'] ?? null;
        if (is_array($jwk) && array_key_exists('d', $jwk)) {
            throw new DpopProofException(DpopFailure::PrivateKey, 'DPoP proof jwk must not contain a private key.');
        }
        $publicKey = is_array($jwk) ? $this->publicKey($jwk) : null;
        if ($publicKey === null) {
            throw new DpopProofException(DpopFailure::Key, 'DPoP proof jwk must be a public EC P-256 key.');
        }

        if (strlen($signature) !== 64
            || openssl_verify($parts[0].'.'.$parts[1], self::derSignature($signature), $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new DpopProofException(DpopFailure::Signature, 'DPoP proof signature is invalid.');
        }

        if (($claims['htm'] ?? null) !== $request->getRealMethod()) {
            throw new DpopProofException(DpopFailure::Method, 'DPoP proof htm does not match the request method.');
        }

        $htu = $claims['htu'] ?? null;
        if (! is_string($htu)
            || self::normalizePath(parse_url($htu, PHP_URL_PATH)) !== self::normalizePath(parse_url($request->getRequestUri(), PHP_URL_PATH))) {
            throw new DpopProofException(DpopFailure::Path, 'DPoP proof htu does not match the request path.');
        }

        $iat = $claims['iat'] ?? null;
        if ((! is_int($iat) && ! is_float($iat)) || abs(now()->getTimestamp() - $iat) > $this->maxAge()) {
            throw new DpopProofException(DpopFailure::IssuedAt, 'DPoP proof iat is outside the accepted window.');
        }

        if ($accessToken !== null) {
            $ath = $claims['ath'] ?? null;
            if (! is_string($ath) || ! hash_equals(JWT::urlsafeB64Encode(hash('sha256', $accessToken, true)), $ath)) {
                throw new DpopProofException(DpopFailure::AccessTokenHash, 'DPoP proof ath does not match the access token.');
            }
        }

        $thumbprint = self::thumbprint($jwk);
        if ($expectedJkt !== null && ! hash_equals($expectedJkt, $thumbprint)) {
            throw new DpopProofException(DpopFailure::KeyMismatch, 'DPoP proof key does not match the token binding.');
        }

        // El `jti` se anota al final: sólo una prueba aceptada lo consume.
        $jti = $claims['jti'] ?? null;
        if (! is_string($jti) || $jti === '') {
            throw new DpopProofException(DpopFailure::Malformed, 'DPoP proof jti is required.');
        }
        $fresh = Cache::store(config('mk_director.auth.dpop.cache_store'))
            ->add('mk:dpop:jti:'.hash('sha256', $jti), true, $this->maxAge() * 2);
        if (! $fresh) {
            throw new DpopProofException(DpopFailure::Replayed, 'DPoP proof was already used.');
        }

        return $thumbprint;
    }

    /**
     * Huella RFC 7638 de una clave EC: SHA-256 de `{"crv","kty","x","y"}` en
     * orden lexicográfico y sin espacios, en base64url.
     *
     * @param  array<string, mixed>  $jwk
     */
    public static function thumbprint(array $jwk): string
    {
        $canonical = json_encode(
            ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        return JWT::urlsafeB64Encode(hash('sha256', $canonical, true));
    }

    /** @param  array<string, mixed>  $jwk */
    private function publicKey(array $jwk): ?\OpenSSLAsymmetricKey
    {
        if (($jwk['kty'] ?? null) !== 'EC' || ($jwk['crv'] ?? null) !== 'P-256'
            || ! is_string($jwk['x'] ?? null) || ! is_string($jwk['y'] ?? null)) {
            return null;
        }

        try {
            $key = JWK::parseKey(['kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y']], self::ALGORITHM);
            $material = $key?->getKeyMaterial();

            return is_string($material) ? (openssl_pkey_get_public($material) ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** La firma JOSE (r‖s, 32 + 32 bytes) a la secuencia DER que pide OpenSSL. */
    private static function derSignature(string $raw): string
    {
        $integer = static function (string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes;
            }

            return "\x02".chr(strlen($bytes)).$bytes;
        };
        $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));

        return "\x30".chr(strlen($sequence)).$sequence;
    }

    private static function normalizePath(string|false|null $path): string
    {
        return '/'.trim(rawurldecode((string) $path), '/');
    }

    private function maxAge(): int
    {
        return max(1, (int) config('mk_director.auth.dpop.max_age_seconds', 60));
    }
}
