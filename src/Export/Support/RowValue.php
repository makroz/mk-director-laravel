<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * Lectura de un valor de una fila del export.
 *
 * 🔴 Una fila llega en DOS formas y las dos son reales:
 *
 *   - **Model de Eloquent**, cuando el `ExportConfig` la recibe directo del
 *     flujo del controller.
 *   - **array**, cuando la fila ya pasó por una serialización — ahí las
 *     relaciones son arrays anidados.
 *
 * Un acceso que sólo contempla una de las dos NO falla: devuelve `null` y la
 * columna sale **vacía** por ese camino nada más. El PDF se genera igual, sin
 * un solo error, y el hueco aparece recién cuando alguien mira el archivo.
 *
 * En el motor original esto vivía duplicado dentro de `ColumnDefinition` y
 * otra vez, a mano, dentro del renderer de un módulo. Cada módulo que escriba
 * un renderer propio vuelve a pisar la misma trampa, así que la lectura se
 * resuelve acá una sola vez.
 */
final class RowValue
{
    /**
     * El valor en `$path` (segmentos separados por punto), o `null` si el
     * camino se corta en cualquier tramo.
     *
     *   RowValue::at($row, 'amount')            → $row->amount | $row['amount']
     *   RowValue::at($row, 'category.parent.name')
     */
    public static function at(mixed $row, string $path): mixed
    {
        $valor = $row;

        foreach (explode('.', $path) as $segmento) {
            $valor = self::segment($valor, $segmento);
            if ($valor === null) {
                return null;
            }
        }

        return $valor;
    }

    /**
     * Un solo tramo. Útil cuando el renderer necesita ramificar en el medio
     * del camino (por ejemplo: "¿esta categoría tiene padre?").
     */
    public static function segment(mixed $fuente, string $campo): mixed
    {
        if (is_array($fuente)) {
            return $fuente[$campo] ?? null;
        }

        if (is_object($fuente)) {
            return $fuente->{$campo} ?? null;
        }

        return null;
    }

    /**
     * `true` si el valor existe y no está vacío en el sentido de un reporte:
     * `null`, string vacío o sólo espacios.
     *
     * ⚠️ El `0` y el `'0'` SÍ cuentan como valor — son montos y contadores
     * legítimos. Un `empty()` acá se come un saldo en cero, que es justamente
     * el dato que alguien está buscando.
     */
    public static function present(mixed $valor): bool
    {
        if ($valor === null) {
            return false;
        }

        if (is_string($valor)) {
            return trim($valor) !== '';
        }

        return true;
    }
}
