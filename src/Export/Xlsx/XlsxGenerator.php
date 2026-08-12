<?php

declare(strict_types=1);

namespace Mk\Director\Export\Xlsx;

use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\XlsxLayout;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera el XLSX acumulando las filas en un único Spreadsheet, por segmentos.
 *
 * El caller pasa un iterable de filas asociativas y el mapa de encabezados
 * (`clave => rótulo`). El segmento marca cada cuántas filas se reporta el
 * progreso y se corre el recolector.
 *
 * ## 🔴 Los valores van CRUDOS, no formateados
 *
 * El XLSX recibe el número (`1800.0`), no el texto (`"1,800.00"`), y el
 * formato de celda nativo se encarga de que igual se lea bien. Es la única
 * forma de que el usuario pueda hacer `SUM` sobre la columna — que es
 * exactamente para lo que baja un XLSX y no un PDF. Lo produce
 * {@see ColumnDefinition::rawValue()}.
 */
final class XlsxGenerator
{
    /**
     * El formato de celda de Excel por cada `format` de `ColumnDefinition`.
     *
     * 🔴 En el motor original esta tabla era **código muerto**: se declaraba y
     * no la usaba nadie. El generador adivinaba el formato del TIPO de PHP
     * —`is_float` → dos decimales, `is_int` → entero—. Medido: una columna
     * `percentage` salía como `#,##0.00`, o sea "15.50" sin el %; una
     * `decimal:4` perdía dos decimales; y una fecha quedaba en `General`, como
     * TEXTO. Sin que ningún test se quejara, porque el módulo que la ejercía
     * sólo tenía columnas `currency` y ahí la adivinanza acertaba de casualidad.
     */
    public const CELL_FORMATS_NUMERICOS = [
        'currency' => '#,##0.00',
        'integer' => '#,##0',
        'decimal:2' => '#,##0.00',
        'decimal:4' => '#,##0.0000',
        'percentage' => '0.00%',
    ];

    public function __construct(
        private readonly iterable $rowProvider,
        /** @var array<string, string> clave de columna => rótulo */
        private readonly array $headers,
        private readonly int $chunkSize = 500,
        private readonly ?string $title = null,
        /**
         * Las `ColumnDefinition` del módulo, indexadas por `key`. De acá salen
         * el formato de celda y la alineación — todo lo que el módulo ya
         * declaró.
         *
         * `[]` cae en la adivinanza por tipo de PHP. Ver el docblock de
         * {@see CELL_FORMATS_NUMERICOS} para por qué eso no alcanza.
         *
         * @var array<string, ColumnDefinition>
         */
        private readonly array $columns = [],
    ) {}

    /**
     * Los formatos de celda, con las fechas tomadas de la config.
     *
     * 🔴 Acá vivía el TERCER lugar que definía formatos de fecha —después de
     * `DateFormat` y `ColumnDefinition`— y decía otra cosa que los dos: `date`
     * era `yyyy-mm-dd` mientras el de al lado era `dd/mm/yyyy`. Como es Excel
     * el que aplica esto sobre el valor, la planilla mostraba la fecha
     * distinta al PDF del MISMO reporte.
     *
     * @return array<string, string>
     */
    public static function cellFormats(): array
    {
        $fechas = (array) config('mk_director.export.xlsx_cell_formats', []);

        return self::CELL_FORMATS_NUMERICOS + $fechas;
    }

    /**
     * @param  callable|null  $onChunkRendered  fn(int $chunkIndex, int $filasDelChunk): void
     * @return array{xlsx: string, chunks: int, rows: int}
     */
    public function generate(?callable $onChunkRendered = null): array
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $filaDeEncabezados = $this->escribirTitulo($sheet);
        $this->escribirEncabezados($sheet, $filaDeEncabezados);

        $filaActual = $filaDeEncabezados + 1;
        $acumulado = [];
        $filasDelChunk = 0;
        $chunkIndex = 0;
        $total = 0;

        foreach ($this->rowProvider as $row) {
            $acumulado[] = $row;
            $filasDelChunk++;
            $total++;

            if ($filasDelChunk >= $this->chunkSize) {
                $this->escribirSegmento($sheet, $acumulado, $filaActual);

                if ($onChunkRendered !== null) {
                    $onChunkRendered($chunkIndex, $filasDelChunk);
                }

                $filaActual += $filasDelChunk;
                $acumulado = [];
                $filasDelChunk = 0;
                $chunkIndex++;
                gc_collect_cycles();
            }
        }

        if ($acumulado !== []) {
            $this->escribirSegmento($sheet, $acumulado, $filaActual);

            if ($onChunkRendered !== null) {
                $onChunkRendered($chunkIndex, $filasDelChunk);
            }

            $chunkIndex++;
        }

        $this->acotarAnchos($sheet);

        $tmp = sys_get_temp_dir().'/mk-xlsx-'.uniqid('', true).'.xlsx';
        (new Xlsx($spreadsheet))->save($tmp);
        $contenido = (string) file_get_contents($tmp);
        @unlink($tmp);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $sheet, $acumulado);
        gc_collect_cycles();

        return [
            'xlsx' => $contenido,
            'chunks' => $chunkIndex,
            'rows' => $total,
        ];
    }

    /**
     * El título y la fecha, replicando el encabezado del PDF. Devuelve en qué
     * fila van los encabezados de columna.
     */
    private function escribirTitulo(Worksheet $sheet): int
    {
        if ($this->title === null) {
            return 1;
        }

        $ultima = XlsxLayout::columnLetter(max(0, count($this->headers) - 1));

        $sheet->setCellValue('A1', $this->title);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:'.$ultima.'1');

        $sheet->setCellValue('A2', 'Generado: '.date('Y-m-d H:i'));
        $sheet->getStyle('A2')->getFont()->setSize(9)->setItalic(true);
        $sheet->mergeCells('A2:'.$ultima.'2');

        // Fila 3 vacía, de separación.
        return 4;
    }

    private function escribirEncabezados(Worksheet $sheet, int $fila): void
    {
        $indice = 0;

        foreach ($this->headers as $clave => $rotulo) {
            $letra = XlsxLayout::columnLetter($indice);

            $sheet->setCellValue($letra.$fila, (string) $rotulo);
            $sheet->getStyle($letra.$fila)->getFont()->setBold(true);
            $sheet->getStyle($letra.$fila)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFD2D3D3');

            // La alineación declarada vale para la columna entera.
            $align = $this->columns[$clave]->align ?? 'left';

            if ($align !== 'left') {
                $sheet->getStyle($letra)->getAlignment()->setHorizontal(
                    $align === 'right'
                        ? Alignment::HORIZONTAL_RIGHT
                        : Alignment::HORIZONTAL_CENTER
                );
            }

            $sheet->getColumnDimension($letra)->setAutoSize(true);
            $indice++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function escribirSegmento(Worksheet $sheet, array $rows, int $filaInicial): void
    {
        $fila = $filaInicial;

        foreach ($rows as $row) {
            $indice = 0;

            // 🔴 Se indexa por el KEY del mapa de encabezados, no por el
            // rótulo. El mapa es `[clave_interna => 'Rótulo visible']` y la
            // fila es `[clave_interna => valor]`. Indexar por el rótulo no
            // matchea y la celda queda VACÍA: la planilla salía con los
            // encabezados y ninguna fila de datos.
            foreach (array_keys($this->headers) as $clave) {
                $valor = $row[$clave] ?? ($row[$indice] ?? '');
                $letra = XlsxLayout::columnLetter($indice);

                $sheet->setCellValue($letra.$fila, $valor);
                $this->aplicarFormato($sheet, $letra.$fila, (string) $clave, $valor);

                $indice++;
            }

            $fila++;
        }
    }

    /**
     * Primero el formato DECLARADO por la columna. Sólo si la columna no
     * declara `format` se cae en la adivinanza por tipo de PHP.
     */
    private function aplicarFormato(Worksheet $sheet, string $celda, string $clave, mixed $valor): void
    {
        $declarado = $this->formatoDeclarado($clave);

        if ($declarado !== null) {
            $sheet->getStyle($celda)->getNumberFormat()->setFormatCode($declarado);

            return;
        }

        if (is_int($valor) || is_float($valor)) {
            $sheet->getStyle($celda)->getNumberFormat()
                ->setFormatCode(is_float($valor) ? '#,##0.00' : '#,##0');
        }
    }

    private function formatoDeclarado(string $clave): ?string
    {
        $format = $this->columns[$clave]->format ?? null;

        return $format !== null ? (self::cellFormats()[$format] ?? null) : null;
    }

    /**
     * Acota el auto-ajuste al máximo de {@see XlsxLayout::MAX_WIDTH}.
     *
     * `calculateColumnWidths()` es lo que PhpSpreadsheet corre al guardar:
     * adelantarlo deja el ancho ya calculado y permite pisarlo. Los anchos por
     * debajo del máximo se respetan tal cual.
     *
     * ⚠️ Acotar SIN envolver el texto lo dejaría cortado en pantalla: Excel
     * sólo desborda hacia la celda vecina si está vacía, y acá nunca lo está.
     * Con `wrapText` la fila crece y se lee entero — igual que en el PDF, que
     * ya parte esa columna en dos líneas.
     */
    private function acotarAnchos(Worksheet $sheet): void
    {
        $sheet->calculateColumnWidths();

        foreach ($sheet->getColumnDimensions() as $dimension) {
            if (! $dimension->getAutoSize() || $dimension->getWidth() <= XlsxLayout::MAX_WIDTH) {
                continue;
            }

            $dimension->setAutoSize(false);
            $dimension->setWidth(XlsxLayout::MAX_WIDTH);

            $sheet->getStyle($dimension->getColumnIndex())
                ->getAlignment()->setWrapText(true);
        }
    }
}
