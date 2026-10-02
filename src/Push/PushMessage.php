<?php

declare(strict_types=1);

namespace Mk\Director\Push;

use InvalidArgumentException;

/**
 * El mensaje de un push. Inmutable: viaja serializado dentro del job.
 *
 * `channel`, `image`, `sound`, `icon` y `color` son opcionales. Lo que el
 * mensaje no dice lo completa su canal de `mk_director.push.channels`
 * ({@see resolvedWith()}, una sola vez en el job, antes del proveedor).
 *
 * El sonido y el ícono son NOMBRES de recursos que vienen dentro de la app
 * (las plataformas no los bajan de una URL). En Android 8+ el sonido es del
 * canal: una vez creado en el teléfono no cambia, y otro sonido es otro canal.
 */
final readonly class PushMessage
{
    /** Android small-icon / raw resource names. */
    public const RESOURCE_PATTERN = '/^[a-z0-9_]+\z/';

    /** A bundled sound: a resource name, optionally with its extension, or 'default'. */
    public const SOUND_PATTERN = '/^[a-z0-9_]+(\.(wav|mp3|ogg))?\z/';

    public const COLOR_PATTERN = '/^#[0-9A-Fa-f]{6}\z/';

    /** @param array<string, string> $data FCM data values must be strings */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $url = null,      // in-app route; travels as data.url
        public ?string $channel = null,  // group: inherits sound/icon/color from config
        public ?string $image = null,    // https URL, big picture
        public ?string $sound = null,    // bundled sound file name, 'default', or null = channel's
        public ?string $icon = null,     // Android small-icon resource name; ignored on iOS
        public ?string $color = null,    // '#RRGGBB', Android only
    ) {
        // El mensaje de error NO repite el valor: puede venir de un input.
        if ($image !== null && (! str_starts_with($image, 'https://') || filter_var($image, FILTER_VALIDATE_URL) === false)) {
            throw new InvalidArgumentException("PushMessage: 'image' tiene que ser una URL https.");
        }
        if ($sound !== null && $sound !== 'default' && preg_match(self::SOUND_PATTERN, $sound) !== 1) {
            throw new InvalidArgumentException("PushMessage: 'sound' tiene que ser 'default' o el nombre de un sonido de la app ([a-z0-9_], con .wav, .mp3 u .ogg opcional).");
        }
        if ($icon !== null && preg_match(self::RESOURCE_PATTERN, $icon) !== 1) {
            throw new InvalidArgumentException("PushMessage: 'icon' tiene que ser el nombre de un recurso de la app ([a-z0-9_]).");
        }
        if ($color !== null && preg_match(self::COLOR_PATTERN, $color) !== 1) {
            throw new InvalidArgumentException("PushMessage: 'color' tiene que ser #RRGGBB.");
        }
    }

    /**
     * The message with its channel resolved: message > channel config > default.
     *
     * 🔴 Lee con `??`: un mensaje que la cola serializó antes del corte 6 se
     * deserializa SIN estas propiedades (el constructor no corre), y leerlas
     * directo tira «must not be accessed before initialization». El mensaje
     * que devuelve sí las tiene todas.
     *
     * @param  array<string, array{name?: string, sound?: string, importance?: string, icon?: string, color?: string}>  $channels  mk_director.push.channels
     */
    public function resolvedWith(array $channels, ?string $defaultChannel = null): self
    {
        $channel = $this->channel ?? $defaultChannel;
        $config = $channel !== null ? ($channels[$channel] ?? []) : [];

        return new self(
            $this->title,
            $this->body,
            $this->data,
            $this->url,
            channel: $channel,
            image: $this->image ?? null,
            sound: $this->sound ?? $config['sound'] ?? null,
            icon: $this->icon ?? $config['icon'] ?? null,
            color: $this->color ?? $config['color'] ?? null,
        );
    }
}
