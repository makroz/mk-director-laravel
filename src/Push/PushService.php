<?php

declare(strict_types=1);

namespace Mk\Director\Push;

use InvalidArgumentException;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Push\Models\MkPushTopicSubscription;

/**
 * La API que usa el consumer: `MkPush::to($member)->send(new PushMessage(...))`.
 *
 * Se envía siempre desde el servidor: las credenciales del servicio son
 * secretas y nunca viajan a la app.
 */
final class PushService
{
    /**
     * Un slug: minúsculas, dígitos y `. - _ :`. El tema viaja a la cola y a
     * un `where`, nunca a un servicio, pero igual se acota: un nombre libre
     * («Novedades », «novedades») partiría el mismo tema en dos sin avisar. `\z` y no `$`: `$`
     * acepta un salto de línea al final.
     */
    public const TOPIC_PATTERN = '/^[a-z0-9._:-]{1,100}\z/';

    /**
     * Una persona o un grupo: un array, una Collection o el resultado de una
     * consulta, con dueños de tipos distintos mezclados (miembros y admins).
     * Un dueño repetido cuenta una vez. Un grupo vacío no manda nada — nunca
     * «a todos».
     *
     * @param  AuthUser|iterable<AuthUser>  $owners
     */
    public function to(AuthUser|iterable $owners): PendingPush
    {
        $owners = $owners instanceof AuthUser ? [$owners] : $owners;
        $refs = [];

        foreach ($owners as $owner) {
            // El morph class y no `::class`: es lo que `owner()->associate()`
            // escribió en `owner_type`. Con un morph map (RETO: 'member') el
            // nombre de la clase no matchearía ninguna fila.
            $ref = [$owner->getMorphClass(), (string) $owner->getKey()];
            $refs[$ref[0]."\0".$ref[1]] = $ref;
        }

        return new PendingPush(owners: array_values($refs));
    }

    /** Todos los suscriptos al tema, leídos cuando el job corre. */
    public function topic(string $topic): PendingPush
    {
        return new PendingPush(topic: self::assertTopic($topic));
    }

    /**
     * Idempotente: suscribirse dos veces no duplica la fila ni los envíos (el
     * índice único `(owner_type, owner_id, topic)` ignora la segunda).
     */
    public function subscribe(AuthUser $owner, string $topic): void
    {
        MkPushTopicSubscription::query()->fillAndInsertOrIgnore([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'topic' => self::assertTopic($topic),
        ]);
    }

    public function unsubscribe(AuthUser $owner, string $topic): void
    {
        MkPushTopicSubscription::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', (string) $owner->getKey())
            ->where('topic', self::assertTopic($topic))
            ->delete();
    }

    private static function assertTopic(string $topic): string
    {
        if (preg_match(self::TOPIC_PATTERN, $topic) !== 1) {
            throw new InvalidArgumentException(
                // Sin el valor en el mensaje: viene de afuera y terminaría en el log.
                '[mk-director] Tema de push inválido: sólo minúsculas, dígitos y . - _ : (de 1 a 100 caracteres).'
            );
        }

        return $topic;
    }
}
