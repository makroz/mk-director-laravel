<?php

declare(strict_types=1);

namespace Mk\Director\Export\Contracts;

use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Models\MkReport;

/**
 * ReportChromeAware — lo que un reporte declara sobre su "marco": header,
 * footer y firmas.
 *
 * Lo extienden los DOS contratos —{@see ExportConfigInterface} para reportes
 * de lista y {@see CustomReportInterface} para los custom—, así el motor mPDF
 * atiende a ambos por el mismo camino y ningún reporte queda con un marco
 * distinto al resto del sistema.
 *
 * Devolver `null` en header/footer significa "usá el estándar de
 * `ReportChrome`", que es lo que hace la enorme mayoría de los módulos.
 */
interface ReportChromeAware
{
    /**
     * Título legible. Va al encabezado del PDF y es la base del nombre de
     * archivo.
     */
    public function title(): string;

    /**
     * @return ColumnDefinition[]
     */
    public function columns(): array;

    /**
     * Filas por segmento, según el formato.
     *
     * El PDF se parte MUCHO más chico que los otros dos a propósito: mPDF
     * mantiene el documento entero en memoria mientras lo arma, así que un
     * chunk grande no acelera, revienta. XLSX y CSV escriben en streaming.
     */
    public function chunkSize(string $format): int;

    /**
     * Header propio en HTML, o `null` para el estándar.
     *
     * Devolver HTML acá permite un encabezado a medida —otro logo, un bloque
     * de totales, los datos del período— SIN tocar el motor.
     */
    public function headerHtml(MkReport $report): ?string;

    /**
     * Footer propio en HTML, o `null` para el estándar. Acepta los
     * marcadores de mPDF `{PAGENO}` y `{nb}`.
     *
     * ⚠️ Si el footer propio es más alto que el estándar hay que revisar
     * `MpdfGenerator::MARGIN_BOTTOM`: el margen inferior se calcula del alto
     * real del pie, o el contenido lo pisa.
     */
    public function footerHtml(): ?string;

    /**
     * Si el PDF cierra con el bloque de firmas. Un listado operativo puede no
     * quererlo.
     */
    public function includeSignatures(): bool;
}
