<?php

declare(strict_types=1);

namespace Mk\Director\Push\Contracts;

use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;

/**
 * El contrato que implementa cada servicio de push. El paquete lo registra con
 * `bindIf` según `mk_director.push.driver`, así que un consumer puede bindear
 * el suyo en su provider sin desregistrar nada (igual que `ReportHeaderProvider`).
 */
interface PushProvider
{
    /** 'fcm' | 'onesignal' | 'log' | 'null' — matches mk_push_devices.provider */
    public function name(): string;

    /** @param list<string> $addresses */
    public function send(PushMessage $message, array $addresses): PushResult;
}
