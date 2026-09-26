<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Social;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifica un ID token (OIDC) de Google o de Apple y devuelve la identidad que
 * prueba. NO hace el flujo OAuth: el front consigue el token con Google
 * Identity Services / Sign in with Apple (JS o SDK nativo) y lo manda acá.
 *
 * Qué se chequea, en orden, y qué rechazo evita cada uno:
 *
 *  1. `alg` del header = RS256. Rechaza `none` y `HS*`: con HS256 el atacante
 *     firma con la clave PÚBLICA como si fuera un secreto compartido.
 *  2. `kid` presente en el JWKS del proveedor. Si no está, se vuelve a bajar
 *     el JWKS UNA vez (el proveedor rota claves), con un freno para que una
 *     ráfaga de `kid` inventados no se convierta en una ráfaga de requests.
 *  3. Firma RSA contra esa clave (firebase/php-jwt).
 *  4. `exp` obligatorio y `iat`/`nbf` con {@see LEEWAY_SECONDS} de tolerancia.
 *  5. `iss` del proveedor.
 *  6. `aud` dentro de los client ids que el consumer aceptó. Sin esto, un
 *     token emitido para CUALQUIER otra app con el mismo proveedor entra.
 *  7. `sub` presente: es la identidad que se vincula.
 *  8. Apple + el cliente mandó `nonce`: el claim `nonce` tiene que ser el
 *     SHA-256 (hex) de ese nonce, como pide Apple. Así un token robado no se
 *     puede re-presentar sin conocer el nonce crudo, que nunca viajó en él.
 *
 * El JWKS se cachea con el `Cache` de la app respetando el `max-age` de la
 * respuesta (acotado a [{@see MIN_TTL}, {@see MAX_TTL}]).
 */
class IdTokenVerifier
{
    public const LEEWAY_SECONDS = 60;

    public const MIN_TTL = 60;

    public const MAX_TTL = 86400;

    /** Sin `Cache-Control`, una hora. */
    public const DEFAULT_TTL = 3600;

    /** Mínimo entre dos re-descargas forzadas por un `kid` desconocido. */
    public const REFETCH_COOLDOWN = 60;

    /**
     * @var array<string, array{jwks: string, issuers: list<string>}>
     */
    public const PROVIDERS = [
        'google' => [
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        ],
        'apple' => [
            'jwks' => 'https://appleid.apple.com/auth/keys',
            'issuers' => ['https://appleid.apple.com'],
        ],
    ];

    /**
     * @param  list<string>  $audiences  client ids aceptados (de {@see SocialProviderConfigResolver})
     * @param  string|null  $nonce  el nonce CRUDO que generó el cliente (sólo Apple)
     *
     * @throws SocialLoginException
     */
    public function verify(string $provider, string $idToken, array $audiences, ?string $nonce = null): SocialIdentity
    {
        $spec = self::PROVIDERS[$provider] ?? null;
        if ($spec === null || $audiences === []) {
            throw SocialLoginException::providerDisabled($provider);
        }

        $header = $this->header($idToken);

        if (($header['alg'] ?? null) !== 'RS256') {
            throw SocialLoginException::invalidToken('alg no permitido: '.json_encode($header['alg'] ?? null));
        }

        $kid = $header['kid'] ?? null;
        if (! is_string($kid) || $kid === '') {
            throw SocialLoginException::invalidToken('el header no trae kid');
        }

        $claims = $this->decode($idToken, $this->key($provider, $spec['jwks'], $kid));

        if (! isset($claims['exp'])) {
            throw SocialLoginException::invalidToken('el token no trae exp');
        }

        if (! in_array($claims['iss'] ?? null, $spec['issuers'], true)) {
            throw SocialLoginException::invalidToken('emisor inesperado');
        }

        // `aud` puede venir como string o como array (RFC 7519 § 4.1.3).
        $tokenAudiences = (array) ($claims['aud'] ?? []);
        if (array_intersect($tokenAudiences, $audiences) === []) {
            throw SocialLoginException::invalidToken('audiencia no aceptada');
        }

        $subject = $claims['sub'] ?? null;
        if (! is_string($subject) || $subject === '') {
            throw SocialLoginException::invalidToken('el token no trae sub');
        }

        if ($provider === 'apple' && $nonce !== null && $nonce !== '') {
            $claimed = $claims['nonce'] ?? null;
            if (! is_string($claimed) || ! hash_equals(hash('sha256', $nonce), $claimed)) {
                throw SocialLoginException::invalidToken('nonce no coincide');
            }
        }

        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;

        return new SocialIdentity(
            provider: $provider,
            subject: $subject,
            email: $email,
            // Apple lo manda como string ("true") y Google como booleano.
            emailVerified: $email !== null && filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            claims: $claims,
        );
    }

    /** @return array<string, mixed> */
    private function header(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw SocialLoginException::invalidToken('no es un JWT');
        }

        $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true);
        if (! is_array($header)) {
            throw SocialLoginException::invalidToken('header ilegible');
        }

        return $header;
    }

    /** @return array<string, mixed> */
    private function decode(string $idToken, Key $key): array
    {
        // firebase/php-jwt mide el tiempo con estáticos globales: se ponen para
        // esta llamada y se restauran, para no cambiarle la tolerancia a otro
        // código del consumer que use la misma librería. `now()` respeta el
        // reloj congelado de los tests.
        $previousLeeway = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway = self::LEEWAY_SECONDS;
        JWT::$timestamp = now()->getTimestamp();

        try {
            return (array) json_decode((string) json_encode(JWT::decode($idToken, $key)), true);
        } catch (Throwable $e) {
            throw SocialLoginException::invalidToken($e->getMessage());
        } finally {
            JWT::$leeway = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }
    }

    private function key(string $provider, string $url, string $kid): Key
    {
        $cacheKey = "mk_director:social:jwks:{$provider}";

        $jwks = Cache::get($cacheKey);
        if (! is_array($jwks) || ! $this->hasKid($jwks, $kid)) {
            // Primera vez, o el proveedor rotó la clave. `Cache::add` es el
            // freno: sólo el primero dentro de la ventana re-descarga; un `kid`
            // inventado repetido no multiplica las requests al proveedor.
            if (! is_array($jwks) || Cache::add("{$cacheKey}:refetch", true, self::REFETCH_COOLDOWN)) {
                $jwks = $this->fetch($provider, $url, $cacheKey);
            }
        }

        if (! $this->hasKid($jwks, $kid)) {
            throw SocialLoginException::invalidToken('kid desconocido');
        }

        try {
            $keys = JWK::parseKeySet(['keys' => array_values(array_filter(
                $jwks['keys'],
                fn ($jwk) => is_array($jwk) && ($jwk['kid'] ?? null) === $kid,
            ))], 'RS256');
        } catch (Throwable $e) {
            throw SocialLoginException::invalidToken('clave inválida: '.$e->getMessage());
        }

        $key = $keys[$kid] ?? null;
        if (! $key instanceof Key || $key->getAlgorithm() !== 'RS256') {
            throw SocialLoginException::invalidToken('la clave no es RS256');
        }

        return $key;
    }

    /** @return array{keys: list<array<string, mixed>>} */
    private function fetch(string $provider, string $url, string $cacheKey): array
    {
        try {
            $response = Http::timeout(5)->acceptJson()->get($url);
        } catch (Throwable) {
            throw SocialLoginException::keysUnavailable($provider);
        }

        $jwks = $response->json();
        if (! $response->successful() || ! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw SocialLoginException::keysUnavailable($provider);
        }

        Cache::put($cacheKey, $jwks, $this->ttl((string) $response->header('Cache-Control')));

        return $jwks;
    }

    private function ttl(string $cacheControl): int
    {
        if (preg_match('/max-age=(\d+)/i', $cacheControl, $m) !== 1) {
            return self::DEFAULT_TTL;
        }

        return max(self::MIN_TTL, min(self::MAX_TTL, (int) $m[1]));
    }

    /** @param  array<string, mixed>|null  $jwks */
    private function hasKid(?array $jwks, string $kid): bool
    {
        foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
            if (is_array($jwk) && ($jwk['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }
}
