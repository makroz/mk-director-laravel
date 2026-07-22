<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

use Mk\Director\Auth\Enums\ScopeStatus;

/**
 * MkCommentReportReason — por qué se reporta (o se oculta) un comentario.
 *
 * Spec: Comunicaciones Fase 2, PR 1 (moderación de comentarios).
 *
 * Int-backed desde 1, regla de la agencia. Ver el docblock de
 * {@see ScopeStatus} para la historia completa del revert.
 *
 * UN SOLO ENUM PARA DOS MOMENTOS, A PROPÓSITO
 * -------------------------------------------
 * La misma taxonomía sirve para el que REPORTA ("esto es spam") y para el admin
 * que RESUELVE ("lo oculté por spam"). Son la misma categoría vista desde dos
 * lados, no dos vocabularios distintos: unificarlos deja la cola de moderación
 * filtrable por una sola dimensión y evita que "acoso" del reporte no matchee
 * con "hostigamiento" de la resolución. La razón que ve el autor en la lápida
 * sale de este mismo enum.
 *
 * `Other` existe para no forzar la categoría equivocada: cuando ninguna encaja,
 * el `note` de texto libre lleva el detalle. Sin un cajón "otro", la gente
 * elige la que menos mal le queda y ensucia las métricas.
 */
enum MkCommentReportReason: int
{
    case Spam = 1;
    case Offensive = 2;
    case Harassment = 3;
    case Other = 4;

    /**
     * Lista de values int del enum (en orden de declaración).
     * Útil para validación: `Rule::in(MkCommentReportReason::values())`.
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
     * Label human-readable para UI / responses (español default).
     */
    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam o publicidad',
            self::Offensive => 'Contenido ofensivo',
            self::Harassment => 'Acoso o ataque personal',
            self::Other => 'Otro motivo',
        };
    }
}
