<?php

declare(strict_types=1);

namespace Mk\Director\Push;

/**
 * El mensaje de un push. Inmutable: viaja serializado dentro del job.
 */
final readonly class PushMessage
{
    /** @param array<string, string> $data FCM data values must be strings */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?string $url = null,   // in-app route; travels as data.url
    ) {}
}
