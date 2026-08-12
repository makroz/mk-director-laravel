<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Closure;
use Generator;
use Mk\Director\Export\Contracts\CustomReportInterface;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\Contracts\ReportChromeAware;
use Mk\Director\Export\Contracts\TituloSegunElReporte;
use Mk\Director\Export\Csv\CsvGenerator;
use Mk\Director\Export\Pdf\MpdfGenerator;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\HeaderBuilder;
use Mk\Director\Export\Support\ReportChrome;
use Mk\Director\Export\Support\TableStyles;
use Mk\Director\Export\Support\TotalesDeLaTabla;
use Mk\Director\Export\Xlsx\XlsxGenerator;
use Mk\Director\Models\MkReport;

/**
 * ExportService — convierte data + config en un archivo PDF, XLSX o CSV.
 *
 * Es el único lugar que sabe cómo se arma un reporte. Lo llaman los jobs, y
 * atiende por el mismo camino a los dos contratos: `ExportConfigInterface`
 * (la lista de la pantalla en otro formato) y `CustomReportInterface` (cálculo
 * propio) — porque los dos extienden `ReportChromeAware`, que es lo único que
 * este servicio necesita.
 */
final class ExportService
{
    // ────────────────────────────────────────────────────────────────────
    // Reportes de lista
    // ────────────────────────────────────────────────────────────────────

    public function renderPdfFromConfig(iterable $data, ExportConfigInterface $config, MkReport $report): string
    {
        // ⚠️ UNA sola instancia del acumulador: es lo que las filas van
        // llenando mientras el generator se consume. Pedirlo de nuevo más
        // abajo daría un objeto en cero y el pie saldría con todo en 0,00.
        $totales = TotalesDeLaTabla::deLasColumnas($config->columns(), $config->etiquetaDeTotales());

        return $this->renderPdf(
            $this->iterateData($data, $config, totales: $totales),
            $config,
            $report,
            $totales,
        );
    }

    public function renderXlsxFromConfig(iterable $data, ExportConfigInterface $config, MkReport $report): string
    {
        return $this->renderXlsx($data, $config, $report, $config->chunkSize('xlsx'));
    }

    public function renderCsvFromConfig(iterable $data, ExportConfigInterface $config, MkReport $report): string
    {
        return $this->renderCsv($data, $config, $report, $config->chunkSize('csv'), $config->csvSeparator());
    }

    // ────────────────────────────────────────────────────────────────────
    // Reportes custom
    //
    // 🔴 Pasan por el MISMO motor. En el original iban por otro camino, con
    // su propio template, así que un reporte custom salía con otro marco y
    // otra paginación que el resto del sistema.
    // ────────────────────────────────────────────────────────────────────

    public function renderPdfFromCustom(iterable $data, CustomReportInterface $custom, MkReport $report): string
    {
        return $this->renderPdf($this->iterateData($data, $custom), $custom, $report);
    }

    public function renderXlsxFromCustom(iterable $data, CustomReportInterface $custom, MkReport $report): string
    {
        return $this->renderXlsx($data, $custom, $report, $custom->chunkSize('xlsx'));
    }

    public function renderCsvFromCustom(iterable $data, CustomReportInterface $custom, MkReport $report): string
    {
        return $this->renderCsv($data, $custom, $report, $custom->chunkSize('csv'));
    }

    // ────────────────────────────────────────────────────────────────────
    // Los tres motores
    // ────────────────────────────────────────────────────────────────────

    /**
     * El PDF, con UNA sola instancia de mPDF alimentada por segmentos.
     *
     * - El **encabezado** y el CSS van sólo antes del primer segmento, así que
     *   aparecen una vez, en la página 1.
     * - **Página nueva entre segmentos**, o el `<thead>` del siguiente
     *   reaparece a mitad de página.
     * - El **pie** con `{PAGENO} de {nb}` va por `SetHTMLFooter`: mPDF resuelve
     *   `{nb}` al total real, así que la numeración es GLOBAL. Sin concatenar
     *   nada.
     * - Las **firmas** después del último segmento, una sola vez.
     */
    private function renderPdf(
        iterable $rows,
        ReportChromeAware $config,
        MkReport $report,
        ?TotalesDeLaTabla $totales = null,
    ): string {
        $rowRenderer = $this->buildRowRenderer($config);
        $styles = TableStyles::css();
        $thead = $this->buildTheadHtml($config);

        // Un módulo puede declarar su propio marco. `null` —el caso normal—
        // usa el estándar. El encabezado sale del `$report`, no de `auth()`:
        // adentro del job no hay usuario y `auth()` devuelve null en silencio.
        $header = $config->headerHtml($report)
            ?? HeaderBuilder::build($report, $this->resolveTitle($config, $report));

        $signatures = $config->includeSignatures() ? ReportChrome::signaturesHtml() : '';
        $chunkSize = max(1, $config->chunkSize('pdf'));

        $gen = new MpdfGenerator(footerHtml: $config->footerHtml() ?? ReportChrome::footerHtml());

        $escribirSegmento = function (string $body, int $index, bool $esElUltimo) use (
            $gen, $styles, $header, $thead, $signatures, $report, $config, $totales
        ): void {
            $tabla = '<table class="data-table framed">'.$thead.'<tbody>';
            $apertura = $index === 0 ? $styles.$header.$tabla : $tabla;

            // 🔴 El pie de totales se escribe en el ÚLTIMO segmento y no antes:
            // recién ahí el generator terminó de consumirse y el acumulador
            // está completo.
            $pie = $esElUltimo && $totales !== null ? $this->buildTfootHtml($config, $totales) : '';
            $cierre = $pie.'</tbody></table>'.($esElUltimo ? $signatures : '');

            if ($index > 0) {
                $gen->newPage();
            }

            $gen->writeChunk($apertura.$body.$cierre);
            self::anotarElAvance($report, $index + 1);
        };

        // 🔴 Se difiere UN segmento para saber cuál es el último — las firmas
        // y el renglón de totales van sólo ahí. `$rows` es un generator: no se
        // sabe el total de antemano, así que no hay forma de preguntarlo.
        $index = 0;
        $filasDelSegmento = 0;
        $body = '';
        $pendiente = null;

        foreach ($rows as $row) {
            $body .= $rowRenderer($row);

            if (++$filasDelSegmento >= $chunkSize) {
                if ($pendiente !== null) {
                    $escribirSegmento($pendiente, $index++, false);
                }

                $pendiente = $body;
                $body = '';
                $filasDelSegmento = 0;
            }
        }

        if ($body !== '') {
            if ($pendiente !== null) {
                $escribirSegmento($pendiente, $index++, false);
            }
            $escribirSegmento($body, $index, true);
        } elseif ($pendiente !== null) {
            // El total fue múltiplo exacto del segmento.
            $escribirSegmento($pendiente, $index, true);
        } else {
            // ⚠️ Sin filas igual se escribe: encabezado, tabla vacía y firmas.
            // Un archivo con el encabezado y ninguna fila le dice al usuario
            // que su filtro no devolvió nada; un archivo de 0 bytes le dice
            // que algo se rompió.
            $escribirSegmento('', 0, true);
        }

        $destino = sys_get_temp_dir().'/mk-pdf-'.$report->id.'-'.uniqid('', true).'.pdf';
        $gen->outputToFile($destino);
        $pdf = (string) file_get_contents($destino);

        if (is_file($destino)) {
            @unlink($destino);
        }

        return $pdf;
    }

    /**
     * ⚠️ `rawValues: true`. El XLSX recibe el NÚMERO, no el texto formateado,
     * para que el usuario pueda hacer `SUM` — que es exactamente para lo que
     * baja un XLSX y no un PDF. El formato de celda nativo se encarga de que
     * igual se lea bien.
     */
    private function renderXlsx(
        iterable $data,
        ReportChromeAware $config,
        MkReport $report,
        int $chunkSize,
    ): string {
        $generator = new XlsxGenerator(
            rowProvider: $this->iterateData($data, $config, rawValues: true),
            headers: $this->buildHeaders($config),
            chunkSize: $chunkSize,
            title: $this->resolveTitle($config, $report),
            // Le pasamos las COLUMNAS, no sólo los rótulos. Sin esto el
            // generador adivina el formato de celda del tipo de PHP e ignora
            // el `format`/`align` que el módulo ya declaró.
            columns: $this->columnsByKey($config),
        );

        return $generator->generate(
            fn (int $chunkIndex) => self::anotarElAvance($report, $chunkIndex + 1)
        )['xlsx'];
    }

    /**
     * ⚠️ `rawValues: true` por la misma razón que el XLSX: un `1,800.00` en un
     * CSV lo lee cualquier importador como texto — o peor, como dos columnas
     * si la coma es el separador.
     */
    private function renderCsv(
        iterable $data,
        ReportChromeAware $config,
        MkReport $report,
        int $chunkSize,
        ?string $separator = null,
    ): string {
        $generator = new CsvGenerator(
            rowProvider: $this->iterateData($data, $config, rawValues: true),
            headers: $this->buildHeaders($config),
            separator: $separator ?? (string) config('mk_director.export.csv_separator', ','),
            chunkSize: $chunkSize,
            title: $this->resolveTitle($config, $report),
        );

        return $generator->generate(
            fn (int $chunkIndex) => self::anotarElAvance($report, $chunkIndex + 1)
        )['csv'];
    }

    // ────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Itera la data aplicando el formato de cada columna.
     *
     * 🔴 El tipo es `ReportChromeAware` y no `ExportConfigInterface`. En el
     * original era el segundo, y por eso los tres `render*FromCustom()`
     * **no podían funcionar**: le pasaban un `CustomReportInterface`, que no lo
     * implementa, y reventaban con `TypeError` en la primera línea. Nadie lo
     * había notado porque a esos tres métodos no los llamaba nadie — tres
     * métodos públicos, con docblock explicando su comportamiento, y ni una
     * línea de código ni un test que los ejecutara.
     */
    private function iterateData(
        iterable $data,
        ReportChromeAware $config,
        bool $rawValues = false,
        ?TotalesDeLaTabla $totales = null,
    ): Generator {
        $columnas = $config->columns();

        foreach ($data as $row) {
            $fila = [];

            foreach ($columnas as $col) {
                $valor = $col->extractValue($row);

                // ⚠️ El total se acumula ANTES de formatear. `formatValue()`
                // devuelve "1,234.00" y `(float)` de eso es 1.0: el pie daría
                // un número que no tiene nada que ver con la columna.
                $totales?->acumular($col->key, $valor);

                $fila[$col->key] = $rawValues ? $col->rawValue($valor) : $col->formatValue($valor);
            }

            yield $fila;
        }
    }

    /**
     * La closure que convierte una fila en `<tr>`.
     *
     * La zebra va por CLASE —`.even` / `.odd` según el índice— y el color vive
     * en dos reglas del CSS. Ver {@see TableStyles} para por qué no es
     * `:nth-child()` ni color inline.
     */
    private function buildRowRenderer(ReportChromeAware $config): Closure
    {
        $columnas = array_values($config->columns());
        $ultimo = count($columnas) - 1;
        $indiceDeFila = 0;

        return function (array $row) use ($columnas, $ultimo, &$indiceDeFila): string {
            $paridad = $indiceDeFila % 2 === 0 ? 'even' : 'odd';
            $indiceDeFila++;

            $html = '<tr class="data-tr '.$paridad.'">';

            foreach ($columnas as $i => $col) {
                $valor = $row[$col->key] ?? '';
                $style = $col->align !== 'left' ? ' style="text-align: '.$col->align.';"' : '';
                // `.last-col` suelta el borde derecho: con `collapse` se funde
                // con el marco de la tabla.
                $clase = $i === $ultimo ? 'data-td last-col' : 'data-td';

                $html .= '<td class="'.$clase.'"'.$style.'>'
                    .htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8')
                    .'</td>';
            }

            return $html.'</tr>';
        };
    }

    private function buildTheadHtml(ReportChromeAware $config): string
    {
        $columnas = array_values($config->columns());
        $total = count($columnas);
        $html = '<thead><tr>';

        foreach ($columnas as $i => $col) {
            $style = $col->align !== 'left' ? ' style="text-align: '.$col->align.';"' : '';
            $clase = $i === $total - 1 ? ' class="last-col"' : '';

            $html .= '<th width="'.$col->resolvedWidth($total).'"'.$clase.$style.'>'
                .htmlspecialchars($col->label, ENT_QUOTES, 'UTF-8')
                .'</th>';
        }

        return $html.'</tr></thead>';
    }

    /**
     * El renglón del pie: la etiqueta a la izquierda y cada columna sumada con
     * su total.
     *
     * ⚠️ **SIN `colspan`.** Medido: con dos celdas o más combinadas, mPDF v8
     * muere en `_unpackCellBorder()` con *"Trying to access array offset on
     * int"* — su empaquetado de bordes (`packTableData`) no cubre ese caso con
     * `border-collapse`. La etiqueta va entonces en la celda de al lado de la
     * primera columna sumada, alineada a la derecha, y las de más a la
     * izquierda quedan vacías.
     *
     * ⚠️ Si la config declarara como total la PRIMERA columna no habría dónde
     * poner el texto: en ese caso el renglón sale sin etiqueta, no roto.
     */
    private function buildTfootHtml(ReportChromeAware $config, TotalesDeLaTabla $totales): string
    {
        $columnas = array_values($config->columns());

        $primeraSumada = null;
        foreach ($columnas as $i => $col) {
            if ($totales->suma($col->key)) {
                $primeraSumada = $i;
                break;
            }
        }

        if ($primeraSumada === null) {
            return '';
        }

        $html = '<tr class="data-tr total-row">';
        $celdaDeLaEtiqueta = $primeraSumada - 1;

        for ($i = 0; $i < $primeraSumada; $i++) {
            $esLaEtiqueta = $i === $celdaDeLaEtiqueta;
            $texto = $esLaEtiqueta
                ? htmlspecialchars($totales->textoDeLaEtiqueta(), ENT_QUOTES, 'UTF-8')
                : '';
            $style = $esLaEtiqueta ? ' style="text-align: right;"' : '';

            $html .= '<td class="data-td total-label"'.$style.'>'.$texto.'</td>';
        }

        $ultimo = count($columnas) - 1;

        for ($i = $primeraSumada; $i <= $ultimo; $i++) {
            $col = $columnas[$i];
            $clase = $i === $ultimo ? 'data-td total-label last-col' : 'data-td total-label';
            $style = $col->align !== 'left' ? ' style="text-align: '.$col->align.';"' : '';
            $valor = $totales->suma($col->key)
                ? (string) $col->formatValue($totales->total($col->key))
                : '';

            $html .= '<td class="'.$clase.'"'.$style.'>'
                .htmlspecialchars($valor, ENT_QUOTES, 'UTF-8')
                .'</td>';
        }

        return $html.'</tr>';
    }

    /**
     * El título del reporte: SIEMPRE el que declara el módulo.
     *
     * 🔴 Estuvo un rato leyendo `params['title']` para respetar lo que mandaba
     * el front, y se revirtió. El módulo del back es la única fuente de verdad
     * del export —columnas, relaciones y título—. Con las dos puntas
     * declarándolo hay dos lugares donde cambiar lo mismo y gana el de afuera;
     * encima el título terminaba siendo texto del cliente impreso en el
     * encabezado del PDF.
     *
     * Un módulo cuyo título dependa de lo pedido lo declara implementando
     * {@see TituloSegunElReporte}, y lo sigue armando ÉL: lo único que llega
     * de afuera son los datos del pedido, no el texto.
     */
    private function resolveTitle(ReportChromeAware $config, MkReport $report): string
    {
        if ($config instanceof TituloSegunElReporte) {
            return $config->titleFor($report);
        }

        return $config->title();
    }

    /**
     * Los encabezados para XLSX y CSV: `clave => rótulo`.
     *
     * @return array<string, string>
     */
    private function buildHeaders(ReportChromeAware $config): array
    {
        $headers = [];

        foreach ($config->columns() as $col) {
            $headers[$col->key] = $col->label;
        }

        return $headers;
    }

    /**
     * @return array<string, ColumnDefinition>
     */
    private function columnsByKey(ReportChromeAware $config): array
    {
        $porClave = [];

        foreach ($config->columns() as $col) {
            $porClave[$col->key] = $col;
        }

        return $porClave;
    }

    /**
     * Deja anotado por qué segmento va el reporte, y el porcentaje.
     *
     * 🔴 El `progress` es lo que mueve la barra del front: sin él la ventana
     * de espera se queda en un spinner y el usuario no sabe si faltan diez
     * segundos o cinco minutos. En un reporte de 99 segmentos eso es la
     * diferencia entre esperar y cerrar la pestaña.
     *
     * ⚠️ Se topa en 99 mientras el job corre. El 100 lo pone el job al marcar
     * `completed`, y sólo entonces hay archivo para descargar: una barra llena
     * con el botón de descarga apagado se lee como que algo se colgó.
     *
     * ⚠️ Sin `total_chunks` se escribe sólo el segmento. Es el caso de los
     * reportes custom, que calculan su propia data y no saben de antemano en
     * cuántos pedazos va a salir.
     */
    private static function anotarElAvance(MkReport $report, int $segmento): void
    {
        $total = (int) ($report->total_chunks ?? 0);

        if ($total < 1) {
            $report->update(['current_chunk' => $segmento]);

            return;
        }

        $report->update([
            'current_chunk' => $segmento,
            'progress' => min(99, (int) floor($segmento / $total * 100)),
        ]);
    }
}
