<?php

declare(strict_types=1);

namespace Mk\Director\Push;

use Mk\Director\Push\Jobs\SendPushJob;

/**
 * El destinatario ya elegido, que espera su `send()`.
 */
final class PendingPush
{
    /** @param list<array{0: string, 1: string}> $owners [morph class, key] */
    public function __construct(private readonly array $owners) {}

    /**
     * Queues SendPushJob after the current transaction commits.
     *
     * `afterCommit` porque el push se llama en medio de la operación que
     * avisa (aprobar un retiro): si esa transacción se revierte, el aviso de
     * algo que no pasó no puede salir.
     */
    public function send(PushMessage $message): void
    {
        SendPushJob::dispatch($message, $this->owners)
            ->afterCommit()
            ->onQueue(config('mk_director.push.queue'));
    }
}
