<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * Formateo de números para reportes.
 *
 * Reemplaza el `number_format((float) $value, 2, '.', ',')` duplicado en cada
 * reporte.
 *
 * ⚠️ `ColumnDefinition` sabe hacer esto por su cuenta para las columnas
 * declarativas. Esta clase existe para uso suelto: un hook `beforeExport()`
 * que pre-formatea valores, o un `CustomReport` que arma su propia tabla.
 */
final class NumberFormat
{
    public static function format(mixed $value, string $format, ?string $currencyPrefix = null): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($format) {
            'integer' => (int) $value,
            'currency' => ($currencyPrefix ? $currencyPrefix.' ' : '').number_format((float) $value, 2, '.', ','),
            'percentage' => number_format((float) $value * 100, 2, '.', ',').'%',
            default => self::decimales($value, $format),
        };
    }

    /**
     * `decimal:N` con N decimales. Cualquier otro nombre devuelve el valor sin
     * tocar — un formato desconocido no puede tumbar el export.
     */
    private static function decimales(mixed $value, string $format): string
    {
        if (str_starts_with($format, 'decimal:')) {
            $decimales = (int) substr($format, strlen('decimal:'));

            return number_format((float) $value, $decimales, '.', ',');
        }

        return (string) $value;
    }
}
