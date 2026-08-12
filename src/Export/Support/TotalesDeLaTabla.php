<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * El acumulador del renglón de totales del pie de un reporte.
 *
 * 🔴 Existe porque al migrar un módulo de gestión de deudas el renglón se
 * PERDIÓ sin que nadie lo notara: los reportes viejos cerraban la tabla con un
 * "Total" / "Saldo total a cobrar" / "Total de morosidad", y las columnas
 * declarativas no tenían dónde decirlo. Se compararon las columnas contra la
 * pantalla y no se miró el pie — que es justamente el número que la gente
 * busca primero.
 *
 * QUÉ columnas suman lo dice cada columna con `->sumarize()`, junto a su ancho
 * y su alineación. Esta clase es sólo el acumulador que el motor arma leyendo
 * las columnas. El TEXTO del renglón lo pone la config en
 * `etiquetaDeTotales()`, porque nombra a la fila y no a una columna.
 *
 * ⚠️ Suma lo que la columna MUESTRA, no la columna de la base: acumula el
 * valor que sale de `extractValue()`, o sea con el renderer ya aplicado. Si
 * sumara el dato crudo, una columna calculada —"Monto total", que es deuda +
 * multa + mantenimiento— daría un pie que no es la suma de lo que se ve. Ese
 * error exacto estuvo vivo en producción.
 *
 * ⚠️ Y va **sólo en el PDF**. El XLSX y el CSV son formatos para calcular: una
 * fila de totales al final hace que cualquier `SUM` de la columna cuente dos
 * veces. Es la misma regla que el marcador de celda vacía: marcar es para leer.
 */
final class TotalesDeLaTabla
{
    /** @var array<string, float> */
    private array $sumas = [];

    private string $etiqueta = 'Total';

    /** @param  string[]  $claves */
    private function __construct(private readonly array $claves)
    {
        $this->sumas = array_fill_keys($claves, 0.0);
    }

    /**
     * El acumulador de una tabla, armado desde las columnas que declararon
     * `->sumarize()`.
     *
     * Devuelve `null` si ninguna suma: sin columnas de plata no hay renglón de
     * totales que escribir.
     *
     * @param  ColumnDefinition[]  $columnas
     */
    public static function deLasColumnas(array $columnas, string $etiqueta): ?self
    {
        $claves = [];

        foreach ($columnas as $columna) {
            if ($columna->sumarize) {
                $claves[] = $columna->key;
            }
        }

        if ($claves === []) {
            return null;
        }

        $totales = new self($claves);
        $totales->etiqueta = $etiqueta;

        return $totales;
    }

    public function textoDeLaEtiqueta(): string
    {
        return $this->etiqueta;
    }

    public function suma(string $clave): bool
    {
        return in_array($clave, $this->claves, true);
    }

    /**
     * ⚠️ Los valores que no son números NO se acumulan como cero: simplemente
     * no entran. Una columna declarada como total que resulte ser texto es un
     * error de la config, y sumarle 0 lo escondería.
     */
    public function acumular(string $clave, mixed $valor): void
    {
        if (! $this->suma($clave) || ! is_numeric($valor)) {
            return;
        }

        $this->sumas[$clave] += (float) $valor;
    }

    public function total(string $clave): float
    {
        return $this->sumas[$clave] ?? 0.0;
    }
}
