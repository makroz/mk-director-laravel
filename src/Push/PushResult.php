<?php

declare(strict_types=1);

namespace Mk\Director\Push;

/**
 * Lo que devolvió el servicio: cuántos salieron, y qué direcciones rechazó
 * para siempre (el teléfono desinstaló la app, el token venció), para borrarlas.
 */
final readonly class PushResult
{
    /** @param list<string> $invalid addresses the service rejected for good — pruned */
    public function __construct(public int $sent, public array $invalid = [], public int $failed = 0) {}
}
