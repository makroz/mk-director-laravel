<?php

declare(strict_types=1);

namespace Mk\Director\Push\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;
use RuntimeException;

/**
 * OneSignal REST: `POST https://api.onesignal.com/notifications` con
 * `include_subscription_ids`, hasta 20.000 por request. La dirección de un
 * teléfono es su subscription id de OneSignal.
 *
 * - Auth: `Authorization: Key <api key>` (las claves `os_v2_app_…`; las
 *   viejas con `Basic` ya no se pueden crear).
 * - `contents.en` es OBLIGATORIO: sin él responde 400. Es el texto por
 *   defecto para todos los idiomas, así que el castellano va ahí.
 * - La ruta viaja en `data.url`, NUNCA en `url` de OneSignal: ése abre el
 *   navegador en vez de la app.
 * - Las inválidas vuelven con 200 en `errors.invalid_player_ids` (el nombre
 *   viejo, también para subscription ids). Si NINGUNA está suscripta, `id`
 *   vuelve vacío y `errors` es una LISTA de textos: ahí no se sabe cuál es
 *   cuál y no se poda nada.
 */
final class OneSignalProvider implements PushProvider
{
    public const URL = 'https://api.onesignal.com/notifications';

    /** OneSignal's per-request cap for include_subscription_ids. */
    public const MAX_PER_REQUEST = 20000;

    /** A channel created in the OneSignal dashboard is addressed by its UUID. */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    public function __construct(private readonly string $appId, private readonly string $apiKey) {}

    public function name(): string
    {
        return 'onesignal';
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach (array_chunk($addresses, self::MAX_PER_REQUEST) as $chunk) {
            try {
                $response = Http::withHeaders(['Authorization' => 'Key '.$this->apiKey])->acceptJson()
                    ->post(self::URL, $this->payload($message, $chunk));
            } catch (ConnectionException $e) {
                $failed += count($chunk);
                Log::warning('[mk-director] push OneSignal: sin conexión', ['error' => $e->getMessage()]);

                continue;
            }

            // 🔴 Una clave mala o de otra app falla IGUAL para todos: se tira
            // para que el job quede fallido a la vista, como la cuenta de
            // servicio rechazada de FCM. El mensaje no repite la clave.
            if (in_array($response->status(), [401, 403], true)) {
                throw new RuntimeException('[mk-director] OneSignal rechazó la clave (HTTP '.$response->status().'): revisá MK_PUSH_ONESIGNAL_APP_ID y MK_PUSH_ONESIGNAL_API_KEY.');
            }

            if (! $response->successful()) {
                $failed += count($chunk);
                Log::warning('[mk-director] push OneSignal: falló un envío', [
                    'status' => $response->status(),
                    'errors' => $response->json('errors'),
                ]);

                continue;
            }

            $rejected = array_values(array_filter(
                (array) $response->json('errors.invalid_player_ids', []),
                fn (mixed $id): bool => is_string($id),
            ));
            $invalid = [...$invalid, ...$rejected];
            $rest = count($chunk) - count($rejected);

            if ((string) $response->json('id') === '') {
                $failed += $rest;
                Log::warning('[mk-director] push OneSignal: no salió a nadie', ['errors' => $response->json('errors')]);
            } else {
                $sent += $rest;
            }
        }

        return new PushResult(sent: $sent, invalid: $invalid, failed: $failed);
    }

    /**
     * @param  list<string>  $addresses
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message, array $addresses): array
    {
        $data = $message->data;
        if ($message->url !== null) {
            $data['url'] = $message->url;
        }

        $payload = [
            'app_id' => $this->appId,
            'include_subscription_ids' => $addresses,
            'headings' => ['en' => $message->title],
            'contents' => ['en' => $message->body],
        ];

        // Los mismos strings que FCM: la app lee `data` como Record<string, string>.
        if ($data !== []) {
            $payload['data'] = array_map(
                fn (mixed $value): string => is_string($value) ? $value : (string) json_encode($value),
                $data,
            );
        }

        // El canal: `android_channel_id` es el UUID de un canal creado en el
        // panel de OneSignal; un id propio ('payments') va como
        // `existing_android_channel_id`, el canal que creó la app. Si el
        // teléfono no lo tiene, el SDK cae a su canal por defecto (medido en
        // `NotificationChannelManager` del SDK de Android): no se pierde.
        // En Android 8+ el sonido es del canal, por eso no va `android_sound`.
        if ($message->channel !== null) {
            $key = preg_match(self::UUID_PATTERN, $message->channel) === 1 ? 'android_channel_id' : 'existing_android_channel_id';
            $payload[$key] = $message->channel;
            $payload['thread_id'] = $message->channel;
        }

        return $payload + array_filter([
            'small_icon' => $message->icon,
            // OneSignal pide ARGB: '#16A34A' → 'FF16A34A'.
            'android_accent_color' => $message->color !== null ? 'FF'.strtoupper(substr($message->color, 1)) : null,
            'big_picture' => $message->image,
            'ios_attachments' => $message->image !== null ? ['id' => $message->image] : null,
            'ios_sound' => $message->sound,
        ], fn (mixed $value): bool => $value !== null);
    }
}
