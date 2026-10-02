<?php

declare(strict_types=1);

namespace Mk\Director\Push\Providers;

use Illuminate\Support\Facades\Log;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;

/**
 * Escribe el envío en el log en vez de mandarlo. Para desarrollo: se ve qué
 * saldría y a qué teléfonos, sin credenciales de ningún servicio.
 */
final class LogProvider implements PushProvider
{
    public function name(): string
    {
        return 'log';
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        Log::info('[mk-director] push: '.$message->title, [
            'body' => $message->body,
            'data' => $message->data,
            'url' => $message->url,
            'style' => array_filter([
                'channel' => $message->channel,
                'image' => $message->image,
                'sound' => $message->sound,
                'icon' => $message->icon,
                'color' => $message->color,
            ], fn (?string $value): bool => $value !== null),
            'addresses' => $addresses,
        ]);

        return new PushResult(sent: count($addresses));
    }
}
