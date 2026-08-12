<?php

declare(strict_types=1);

namespace Mk\Director\Export\Csv;

use DateTimeInterface;
use RuntimeException;

/**
 * Genera el CSV escribiendo a un archivo temporal, no acumulando en memoria.
 *
 * El caller pasa un iterable de filas asociativas y el mapa de encabezados
 * (`clave => rótulo`, en orden). El generador itera y escribe; el chunk sólo
 * marca cada cuántas filas se reporta el progreso y se corre el recolector.
 *
 * ⚠️ Escribe a disco y recién al final lee el archivo. Acumular el CSV en un
 * string es lo mismo que no tener chunking: el pico de memoria termina siendo
 * el archivo entero.
 */
final class CsvGenerator
{
    public function __construct(
        private readonly iterable $rowProvider,
        /** @var array<string, string> clave de columna => rótulo */
        private readonly array $headers,
        private readonly string $separator = ',',
        private readonly string $enclosure = '"',
        private readonly string $escape = '\\',
        private readonly int $chunkSize = 1000,
        /**
         * Título del reporte, para replicar el encabezado del PDF en las
         * primeras filas.
         *
         * ⚠️ El CSV no tiene logo — es texto plano. Sólo van el título y la
         * fecha de generación, y después una fila vacía de separación.
         */
        private readonly ?string $title = null,
    ) {}

    /**
     * @param  callable|null  $onChunkRendered  fn(int $chunkIndex, int $filasDelChunk): void
     * @return array{csv: string, chunks: int, rows: int}
     */
    public function generate(?callable $onChunkRendered = null): array
    {
        $tmp = sys_get_temp_dir().'/mk-csv-'.uniqid('', true).'.csv';
        $fp = fopen($tmp, 'w');

        if ($fp === false) {
            throw new RuntimeException("No se pudo crear el archivo temporal: {$tmp}");
        }

        try {
            // ⚠️ BOM UTF-8. Sin esto Excel abre el archivo en la codificación
            // del sistema y cada acento sale como dos caracteres raros. Es una
            // de esas cosas que sólo se ven cuando el usuario lo abre.
            fwrite($fp, "\xEF\xBB\xBF");

            if ($this->title !== null) {
                $this->escribir($fp, [$this->title]);
                $this->escribir($fp, ['Generado: '.date('Y-m-d H:i')]);
                $this->escribir($fp, ['']);
            }

            $this->escribir($fp, array_values($this->headers));

            $claves = array_keys($this->headers);
            $filasDelChunk = 0;
            $chunkIndex = 0;
            $total = 0;

            foreach ($this->rowProvider as $row) {
                $linea = [];

                foreach ($claves as $clave) {
                    $linea[] = $this->celda($row[$clave] ?? '');
                }

                $this->escribir($fp, $linea);

                $filasDelChunk++;
                $total++;

                if ($filasDelChunk >= $this->chunkSize) {
                    if ($onChunkRendered !== null) {
                        $onChunkRendered($chunkIndex, $filasDelChunk);
                    }

                    $filasDelChunk = 0;
                    $chunkIndex++;
                    gc_collect_cycles();
                }
            }

            if ($filasDelChunk > 0 && $onChunkRendered !== null) {
                $onChunkRendered($chunkIndex, $filasDelChunk);
            }
        } finally {
            fclose($fp);
        }

        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);

        return [
            'csv' => $contenido,
            'chunks' => $chunkIndex,
            'rows' => $total,
        ];
    }

    /**
     * El valor listo para una celda.
     *
     * 🔴 Los números salen como string SIN separadores de miles y sin
     * notación científica. Un `1,800.50` en el CSV lo lee Excel como texto —o
     * peor, como dos columnas si la coma es el separador—, y un `1.8E+3` no
     * lo reconoce nadie. El CSV es para calcular: va el número pelado.
     */
    private function celda(mixed $valor): string
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('Y-m-d H:i:s');
        }

        if (is_array($valor) || is_object($valor)) {
            return (string) json_encode($valor, JSON_UNESCAPED_UNICODE);
        }

        if (is_float($valor)) {
            // `(string)` respeta la precisión de PHP y evita la notación
            // científica para cualquier valor razonable.
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return (string) $valor;
    }

    /**
     * @param  resource  $fp
     * @param  array<int, string>  $campos
     */
    private function escribir($fp, array $campos): void
    {
        fputcsv($fp, $campos, $this->separator, $this->enclosure, $this->escape);
    }
}
