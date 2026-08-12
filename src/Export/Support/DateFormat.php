<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Carbon\Carbon;
use Throwable;

/**
 * DateFormat — el ÚNICO lugar que sabe cómo se ve una fecha en un reporte.
 *
 * Los patrones no están acá: salen de `mk_director.export.date_formats`, así
 * que cambiar a año/mes/día es una línea de config —o una variable de
 * entorno— y no tocar ningún reporte.
 *
 * ## Por qué esto tiene que estar en UN solo lugar
 *
 * 🔴 En el motor original había **tres** definiendo formatos de fecha —esta
 * clase, `ColumnDefinition::formatValue()` y el mapa de celdas del generador
 * de XLSX— y se habían separado, que es lo que pasa siempre. El docblock lo
 * decía desde el día uno y nunca se resolvió: `date` daba `Y-m-d` de un lado
 * y `d/m/Y` del otro, con dos nombres más para el mismo formato. El usuario
 * veía "5 de agosto del 2026" en pantalla y "2026-08-05" en el PDF.
 *
 * ## 🔴 Y la zona horaria se convierte POR DEFECTO
 *
 * La base guarda en UTC. Sin convertir no corre sólo la hora: corre el DÍA.
 * Medido en Condaty (UTC-4), **629 de 10169 pagos y 11 de 70 alertas** caen en
 * una fecha distinta según se lean en UTC o en la zona local — un pago de las
 * 21:00 aparecía al día siguiente.
 *
 * Por eso el default del parámetro NO es "crudo": olvidarse de pasar la zona
 * era la falla, así que ya no hay nada que recordar. Quien necesite el valor
 * tal como está guardado lo pide con {@see SIN_CONVERTIR}, y queda a la vista
 * en el llamado.
 */
final class DateFormat
{
    /**
     * Sentinela para pedir el valor CRUDO, tal como está guardado.
     *
     * Hace falta porque `null` significa "usá el default configurado", no "no
     * conviertas".
     */
    public const SIN_CONVERTIR = 'sin-convertir';

    /**
     * Zona horaria de visualización. `null` en la config usa la de la app.
     */
    public static function displayTimezone(): string
    {
        return (string) (
            config('mk_director.export.display_timezone')
            ?? config('app.timezone', 'UTC')
        );
    }

    /**
     * @param  string|null  $timezone  `null` usa la configurada;
     *                                 {@see SIN_CONVERTIR} devuelve el valor
     *                                 tal como está guardado.
     */
    public static function format(mixed $value, string $format, ?string $timezone = null): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        // ⚠️ Un valor basura en UNA fila no puede tumbar un export de miles:
        // esa fila muestra vacío y el archivo se genera igual.
        try {
            $carbon = $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }

        if ($timezone !== self::SIN_CONVERTIR) {
            $carbon = $carbon->timezone($timezone ?? self::displayTimezone());
        }

        // `iso` y `timestamp` no son formatos de lectura: son para máquinas y
        // por eso no salen de la config.
        return match ($format) {
            'iso' => $carbon->toIso8601String(),
            'timestamp' => $carbon->getTimestamp(),
            default => $carbon->format(self::patron($format)),
        };
    }

    /**
     * El patrón de `date()` configurado para este formato.
     *
     * ⚠️ Un nombre desconocido cae en `date` en vez de reventar: un reporte
     * con una fecha en otro formato es un problema chico; un export de miles
     * de filas que muere a la mitad, no.
     */
    public static function patron(string $format): string
    {
        $configurados = (array) config('mk_director.export.date_formats', []);

        return (string) ($configurados[$format] ?? $configurados['date'] ?? 'd/m/Y');
    }

    /**
     * Los nombres de formato de fecha que entiende el motor.
     *
     * Sale de la config a propósito: si estuviera escrita a mano habría que
     * acordarse de sumar cada formato nuevo en los dos lados —PDF y XLSX— o
     * uno sale con la fecha bien y el otro con el timestamp crudo.
     *
     * @return string[]
     */
    public static function nombresValidos(): array
    {
        return array_keys((array) config('mk_director.export.date_formats', []));
    }

    /**
     * El formato de celda de Excel equivalente, o `null` si este nombre no es
     * una fecha.
     */
    public static function formatoDeCelda(string $format): ?string
    {
        $configurados = (array) config('mk_director.export.xlsx_cell_formats', []);

        return isset($configurados[$format]) ? (string) $configurados[$format] : null;
    }
}
