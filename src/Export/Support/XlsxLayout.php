<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Geometría de una hoja de Excel: anchos de columna y letras de columna.
 *
 * Vive en `Support` porque no es de ningún módulo: cualquier reporte —de lista
 * o custom— que escriba un XLSX necesita las dos cosas, y en el motor original
 * cada uno traía su propia copia.
 */
final class XlsxLayout
{
    /**
     * 🔴 **Excel mide los anchos en CARACTERES, no en píxeles.**
     *
     * Ésta es la escala para traducir un ancho declarado en px —que es como
     * piensa el front y como se declara un catálogo de columnas— a lo que
     * espera PhpSpreadsheet.
     */
    public const PIXELS_PER_CHAR = 7.2;

    /** Debajo de esto el encabezado queda cortado y la columna es ilegible. */
    public const MIN_WIDTH = 10.0;

    /**
     * Tope duro (~288 px).
     *
     * 🔴 Existe por un bug real: con `setAutoSize(true)` Excel mide el valor
     * MÁS LARGO de la columna y no tiene techo. Medido en un reporte de
     * egresos, la columna "Subcategoría" salió de 97 caracteres (~700 px)
     * porque UNA fila concatenaba dos nombres largos. El usuario abre la
     * planilla y tiene que scrollear en horizontal para ver las columnas
     * siguientes.
     */
    public const MAX_WIDTH = 40.0;

    /** Píxeles → unidades de ancho de Excel (caracteres), acotado. */
    public static function columnWidth(int $pixels): float
    {
        return max(
            self::MIN_WIDTH,
            min(self::MAX_WIDTH, round($pixels / self::PIXELS_PER_CHAR, 1))
        );
    }

    /** Índice 0-based → letra de columna (0 → A, 25 → Z, 26 → AA). */
    public static function columnLetter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index + 1);
    }
}
