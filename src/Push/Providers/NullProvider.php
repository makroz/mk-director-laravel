<?php

declare(strict_types=1);

namespace Mk\Director\Push\Providers;

use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;

/**
 * No manda nada. Es el default: un proyecto que instala el paquete y no
 * configuró push no puede empezar a mandar avisos, ni a llenar el log.
 */
final class NullProvider implements PushProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        return new PushResult(sent: 0);
    }
}
