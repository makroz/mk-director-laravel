<?php

declare(strict_types=1);

namespace Mk\Director\Push\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;

/**
 * FCM HTTP v1: un request por token (la v1 no tiene envío por lotes).
 *
 * Un token malo NO corta el envío a los demás: cuenta como inválido (se
 * borra) o como fallido (se loguea), y se sigue con el siguiente.
 *
 * ponytail: un request por token desde la cola. Un tema de 50.000 personas
 * son 50.000 requests; el camino para crecer son los temas nativos de FCM.
 */
final class FcmProvider implements PushProvider
{
    public function __construct(private readonly FcmAccessToken $token) {}

    public function name(): string
    {
        return 'fcm';
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        $url = 'https://fcm.googleapis.com/v1/projects/'.$this->token->projectId().'/messages:send';
        $accessToken = $this->token->get();
        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach ($addresses as $address) {
            try {
                $response = Http::withToken($accessToken)->acceptJson()
                    ->post($url, ['message' => $this->payload($message, $address)]);
            } catch (ConnectionException $e) {
                $failed++;
                Log::warning('[mk-director] push FCM: sin conexión', ['error' => $e->getMessage()]);

                continue;
            }

            if ($response->successful()) {
                $sent++;
            } elseif ($this->isInvalidToken($response)) {
                $invalid[] = $address;
            } else {
                $failed++;
                Log::warning('[mk-director] push FCM: falló un envío', [
                    'status' => $response->status(),
                    'error' => $response->json('error.status'),
                    'message' => $response->json('error.message'),
                ]);
            }
        }

        return new PushResult(sent: $sent, invalid: $invalid, failed: $failed);
    }

    /** @return array<string, mixed> */
    private function payload(PushMessage $message, string $address): array
    {
        $data = $message->data;
        if ($message->url !== null) {
            $data['url'] = $message->url;
        }

        $payload = [
            'token' => $address,
            'notification' => ['title' => $message->title, 'body' => $message->body],
            'android' => ['priority' => 'high'],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
        ];

        // FCM sólo acepta strings en `data`, y un `data` vacío viaja como `[]`
        // (lista, no mapa) y da 400: sin datos, la clave no va.
        if ($data !== []) {
            $payload['data'] = array_map(
                fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value),
                $data,
            );
        }

        return $payload;
    }

    /**
     * 🔴 Un 400 INVALID_ARGUMENT también sale por un payload mal armado: si se
     * contara como token inválido, un bug nuestro borraría TODOS los
     * teléfonos. Sólo cuenta cuando el error señala al token.
     */
    private function isInvalidToken(Response $response): bool
    {
        $unregistered = collect((array) $response->json('error.details', []))
            ->contains(fn (mixed $detail) => is_array($detail) && ($detail['errorCode'] ?? null) === 'UNREGISTERED');

        if ($unregistered) {
            return true;
        }

        return $response->status() === 400
            && $response->json('error.status') === 'INVALID_ARGUMENT'
            && str_contains((string) $response->json('error.message'), 'registration token');
    }
}
