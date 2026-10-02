<?php

declare(strict_types=1);

namespace Mk\Director\Push;

use Mk\Director\Push\Jobs\SendPushJob;

/**
 * El destinatario ya elegido, que espera su `send()`.
 */
final class PendingPush
{
    /**
     * O dueños o un tema: lo arma {@see PushService}.
     *
     * @param  list<array{0: string, 1: string}>  $owners  [morph class, key]
     */
    public function __construct(private readonly array $owners = [], private readonly ?string $topic = null) {}

    /**
     * Queues SendPushJob after the current transaction commits.
     *
     * `afterCommit` porque el push se llama en medio de la operación que
     * avisa (aprobar un retiro): si esa transacción se revierte, el aviso de
     * algo que no pasó no puede salir.
     */
    public function send(PushMessage $message): void
    {
        SendPushJob::dispatch($message, $this->owners, $this->topic)
            ->afterCommit()
            ->onQueue(config('mk_director.push.queue'));
    }
}
