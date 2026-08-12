<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * Texto de celda: marcador de vacío y unión de valores repetidos.
 *
 * En un reporte una celda vacía NO se deja vacía — se marca, para que quien
 * lo lee distinga "no hay dato" de "se perdió la columna".
 *
 * ⚠️ Marcar es SÓLO para leer. El XLSX y el CSV dejan la celda vacía a
 * propósito: son formatos para calcular, y un "-/-" en una columna de importes
 * rompe cualquier `SUM`. La decisión vive en `ColumnDefinition`: `formatValue`
 * marca, `rawValue` no.
 */
final class TextFormat
{
    public const PLACEHOLDER = '-/-';

    /** Separador de valores múltiples dentro de una celda. */
    public const JOIN_SEPARATOR = ' · ';

    /** Valor como texto recortado, o el marcador de vacío. */
    public static function orPlaceholder(mixed $value, string $placeholder = self::PLACEHOLDER): string
    {
        $texto = trim((string) ($value ?? ''));

        return $texto !== '' ? $texto : $placeholder;
    }

    /**
     * Une valores en una celda, sin repetidos y conservando el orden de
     * aparición — un pago con tres detalles de la misma categoría no debe
     * mostrarla tres veces.
     *
     * @param  array<int, string>  $values
     */
    public static function joinUnique(
        array $values,
        string $separator = self::JOIN_SEPARATOR,
        string $placeholder = self::PLACEHOLDER,
    ): string {
        $unicos = array_values(array_unique(array_filter(
            $values,
            fn (string $v) => trim($v) !== '' && $v !== $placeholder
        )));

        return $unicos === [] ? $placeholder : implode($separator, $unicos);
    }
}
