<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Exceptions;

use Mk\Director\Auth\Enums\DpopFailure;
use RuntimeException;

/**
 * Una prueba DPoP rechazada (o ausente). Siempre es un 401: la autenticación
 * no vale para este pedido. `BaseAuthController` y `mk.auth` la traducen al
 * sobre canónico con `__extraData.code` = {@see DpopFailure::errorCode()} y
 * `__extraData` += {@see self::extraData()}.
 */
class DpopProofException extends RuntimeException
{
    public readonly int $serverTime;

    public function __construct(public readonly DpopFailure $reason, string $message)
    {
        parent::__construct($message);
        $this->serverTime = now()->getTimestamp();
    }

    public function errorCode(): string
    {
        return $this->reason->errorCode();
    }

    /**
     * `reason` siempre; `server_time` (unix, segundos) cuando falló el `iat`,
     * para que el cliente corrija el desfase de su reloj y reintente UNA vez.
     *
     * @return array<string, int|string>
     */
    public function extraData(): array
    {
        $extra = ['reason' => $this->reason->value];
        if ($this->reason === DpopFailure::IssuedAt) {
            $extra['server_time'] = $this->serverTime;
        }

        return $extra;
    }
}
