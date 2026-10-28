<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Firebase\JWT\JWT;
use Illuminate\Support\Str;

/**
 * Una clave EC P-256 de cliente y las pruebas DPoP que firma con ella, como lo
 * haría el navegador (DEVELOPER_GUIDE § 3.24). La huella se calcula ACÁ, a
 * mano según RFC 7638, y no con `DpopProofVerifier::thumbprint()`: así el test
 * no comparte el defecto que tendría que detectar.
 */
final class DpopTestKey
{
    public readonly \OpenSSLAsymmetricKey $private;

    /** @var array{kty: string, crv: string, x: string, y: string} */
    public readonly array $jwk;

    public function __construct()
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($private instanceof \OpenSSLAsymmetricKey);
        $this->private = $private;

        // OpenSSL entrega x/y sin los ceros de la izquierda: el JWK los quiere de 32 bytes.
        $ec = openssl_pkey_get_details($private)['ec'];
        $this->jwk = [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => JWT::urlsafeB64Encode(str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT)),
            'y' => JWT::urlsafeB64Encode(str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT)),
        ];
    }

    public function thumbprint(): string
    {
        $canonical = '{"crv":"P-256","kty":"EC","x":"'.$this->jwk['x'].'","y":"'.$this->jwk['y'].'"}';

        return JWT::urlsafeB64Encode(hash('sha256', $canonical, true));
    }

    /**
     * Una prueba firmada. `$claims` y `$header` pisan los de una prueba buena,
     * para armar cada rechazo cambiando una sola cosa.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header
     */
    public function proof(string $method, string $url, ?string $accessToken = null, array $claims = [], array $header = []): string
    {
        $payload = array_merge([
            'htm' => $method,
            'htu' => $url,
            'iat' => now()->getTimestamp(),
            'jti' => (string) Str::uuid(),
        ], $accessToken !== null ? ['ath' => JWT::urlsafeB64Encode(hash('sha256', $accessToken, true))] : [], $claims);

        return JWT::encode($payload, $this->private, 'ES256', null, array_merge(['typ' => 'dpop+jwt', 'jwk' => $this->jwk], $header));
    }
}
