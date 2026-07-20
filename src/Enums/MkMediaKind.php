<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

use Mk\Director\Auth\Enums\ScopeStatus;

/**
 * MkMediaKind — qué clase de media es una fila de `mk_media`.
 *
 * Spec: Comunicaciones Fase 1, PR 1.
 *
 * Int-backed desde 1, regla de la agencia. Ver el docblock de
 * {@see ScopeStatus} para por qué el paquete había
 * quedado en string y por qué se revirtió: el drift que motivó R-PKG-047 D4
 * lo causaba el flag `--status-values` configurable, no el backing type.
 *
 * Las tres variantes NO son intercambiables en la fila:
 *  - Image / Video → viven en un disk (`disk` + `path` obligatorios).
 *  - Embed         → NO tiene archivo propio (`path` null); lo que se guarda
 *                    es `provider` + `provider_id` + `source_url`, y el
 *                    thumbnail es una URL remota.
 * Esa distinción es la que el legacy no hacía: su columna `url` guardaba
 * 'webp', o una extensión de documento, o una URL de YouTube, según el
 * `type` — imposible de indexar y de validar.
 */
enum MkMediaKind: int
{
    case Image = 1;
    case Video = 2;
    case Embed = 3;

    /**
     * Lista de values int del enum (en orden de declaración).
     * Útil para reglas de validación: `Rule::in(MkMediaKind::values())`.
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
     * ¿Esta variante guarda un archivo propio en un disk?
     *
     * `false` para Embed: un embed apunta a un recurso de un tercero, así que
     * `disk`/`path` quedan null y borrar la fila NO debe intentar borrar
     * ningún archivo.
     */
    public function hasStoredFile(): bool
    {
        return $this !== self::Embed;
    }

    /**
     * Label human-readable para UI / responses (español default).
     */
    public function label(): string
    {
        return match ($this) {
            self::Image => 'Imagen',
            self::Video => 'Video',
            self::Embed => 'Enlace externo',
        };
    }
}
