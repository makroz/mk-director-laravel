<?php

declare(strict_types=1);

namespace Mk\Director\Push;

use Mk\Director\Auth\Models\AuthUser;

/**
 * La API que usa el consumer: `MkPush::to($member)->send(new PushMessage(...))`.
 *
 * Se envía siempre desde el servidor: las credenciales del servicio son
 * secretas y nunca viajan a la app.
 */
final class PushService
{
    /** @param AuthUser|iterable<AuthUser> $owners */
    public function to(AuthUser|iterable $owners): PendingPush
    {
        $owners = $owners instanceof AuthUser ? [$owners] : $owners;
        $refs = [];

        foreach ($owners as $owner) {
            // El morph class y no `::class`: es lo que `owner()->associate()`
            // escribió en `owner_type`. Con un morph map (RETO: 'member') el
            // nombre de la clase no matchearía ninguna fila.
            $refs[] = [$owner->getMorphClass(), (string) $owner->getKey()];
        }

        return new PendingPush($refs);
    }
}
