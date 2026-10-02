<?php

declare(strict_types=1);

namespace Mk\Director\Push\Providers;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El token OAuth de FCM a partir de la cuenta de servicio de Firebase: un JWT
 * firmado RS256 con su clave privada se canjea en `token_uri` por un access
 * token de una hora. Se cachea 55 minutos: un envío no pide un token por
 * teléfono.
 *
 * 🔴 El JSON de la cuenta de servicio es SECRETO. Ningún mensaje de error de
 * esta clase incluye su contenido: sólo la ruta y el nombre del campo.
 */
final class FcmAccessToken
{
    public const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const CACHE_SECONDS = 55 * 60;

    /** @param array{project_id: string, client_email: string, private_key: string, token_uri: string} $account */
    public function __construct(private readonly array $account) {}

    /** Lee la cuenta de servicio de `mk_director.push.fcm.credentials` (una ruta). */
    public static function fromFile(?string $path): self
    {
        if ($path === null || $path === '') {
            throw new RuntimeException('[mk-director] El driver de push \'fcm\' necesita MK_PUSH_FCM_CREDENTIALS: la ruta al JSON de la cuenta de servicio de Firebase.');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("[mk-director] No se puede leer la cuenta de servicio de FCM en '{$path}' (MK_PUSH_FCM_CREDENTIALS).");
        }

        $account = json_decode((string) file_get_contents($path), true);

        foreach (['project_id', 'client_email', 'private_key', 'token_uri'] as $field) {
            if (! is_array($account) || ! is_string($account[$field] ?? null) || $account[$field] === '') {
                throw new RuntimeException("[mk-director] La cuenta de servicio de FCM en '{$path}' no es válida: falta '{$field}'.");
            }
        }

        return new self($account);
    }

    public function projectId(): string
    {
        return $this->account['project_id'];
    }

    public function get(): string
    {
        return Cache::remember(
            'mk_director:push:fcm_token:'.$this->account['project_id'],
            self::CACHE_SECONDS,
            fn (): string => $this->fetch(),
        );
    }

    private function fetch(): string
    {
        $now = time();
        $assertion = JWT::encode([
            'iss' => $this->account['client_email'],
            'scope' => self::SCOPE,
            'aud' => $this->account['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->account['private_key'], 'RS256');

        $response = Http::asForm()->post($this->account['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            // Lo que responde Google (`invalid_grant`, etc.), nunca la cuenta.
            throw new RuntimeException('[mk-director] FCM rechazó la cuenta de servicio: HTTP '.$response->status().' '.(string) $response->json('error', ''));
        }

        return $token;
    }
}
