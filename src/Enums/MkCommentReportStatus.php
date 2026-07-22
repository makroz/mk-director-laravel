<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

use Mk\Director\Auth\Enums\ScopeStatus;

/**
 * MkCommentReportStatus — el ciclo de vida de un reporte.
 *
 * Spec: Comunicaciones Fase 2, PR 1 (moderación de comentarios).
 *
 * Int-backed desde 1, regla de la agencia. Ver el docblock de
 * {@see ScopeStatus} para la historia completa del revert.
 *
 * EL STATUS ES EL RESULTADO, NO HAY UN CAMPO `resolution` APARTE
 * -------------------------------------------------------------
 * Un reporte nace `Pending` y muere en uno de dos estados terminales: el admin
 * lo `Dismissed` (el comentario estaba bien, la denuncia no procede) o lo
 * `Actioned` (ocultó/borró el comentario). Modelar esto como status + un
 * segundo campo `resolution` sería redundante: el desenlace ES el estado. Con
 * un solo enum, la cola de admin filtra "pendientes" con un `where` y el
 * histórico queda auditado sin cruzar dos columnas que podrían contradecirse.
 */
enum MkCommentReportStatus: int
{
    case Pending = 1;
    case Dismissed = 2;
    case Actioned = 3;

    /**
     * Lista de values int del enum (en orden de declaración).
     *
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    /**
     * ¿Es un estado terminal (ya resuelto)? Lo contrario de "pendiente".
     */
    public function isResolved(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Label human-readable para UI / responses (español default).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Dismissed => 'Descartado',
            self::Actioned => 'Accionado',
        };
    }
}
