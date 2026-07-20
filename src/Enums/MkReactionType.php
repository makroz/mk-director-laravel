<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

use Mk\Director\Auth\Enums\ScopeStatus;

/**
 * MkReactionType — qué reacción dejó un autor sobre un contenido.
 *
 * Spec: Comunicaciones Fase 1, PR 2.
 *
 * Int-backed desde 1, regla de la agencia. Ver el docblock de
 * {@see ScopeStatus} para la historia completa del revert.
 *
 * POR QUÉ EL SET COMPLETO Y NO SÓLO `Like`
 * ----------------------------------------
 * El diseño dice "arranca sólo like, deja la puerta abierta". La puerta se
 * deja abierta ACÁ y no en la tabla: agregar un case a un enum int-backed no
 * toca la base, mientras que descubrir después que la columna era un boolean
 * `liked` sí obliga a migrar datos. La UI de v1 puede exponer únicamente
 * `Like` sin que eso condicione el esquema.
 *
 * 🔴 UN AUTOR TIENE A LO SUMO UNA REACCIÓN POR CONTENIDO.
 * El UNIQUE de la tabla es (reactable, author) y NO incluye `type`. Es
 * semántica de Facebook: pasar de `Like` a `Love` REEMPLAZA, no acumula. Si
 * `type` estuviera en el UNIQUE, el mismo usuario podría dejar las seis
 * reacciones a la vez y el contador diría 6 por una sola persona.
 */
enum MkReactionType: int
{
    case Like = 1;
    case Love = 2;
    case Haha = 3;
    case Wow = 4;
    case Sad = 5;
    case Angry = 6;

    /**
     * Lista de values int del enum (en orden de declaración).
     * Útil para validación: `Rule::in(MkReactionType::values())`.
     *
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    /**
     * Mapa `value => label` listo para poblar un select.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * La reacción por defecto cuando el consumer no especifica ninguna.
     *
     * Existe para que `$post->react($user)` sea legible en el caso del 99%
     * sin obligar a importar el enum en cada llamada.
     */
    public static function default(): self
    {
        return self::Like;
    }

    /**
     * Label human-readable para UI / responses (español default).
     */
    public function label(): string
    {
        return match ($this) {
            self::Like => 'Me gusta',
            self::Love => 'Me encanta',
            self::Haha => 'Me divierte',
            self::Wow => 'Me asombra',
            self::Sad => 'Me entristece',
            self::Angry => 'Me enoja',
        };
    }
}
