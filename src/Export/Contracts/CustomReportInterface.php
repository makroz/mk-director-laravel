<?php

declare(strict_types=1);

namespace Mk\Director\Export\Contracts;

use Mk\Director\Models\MkReport;

/**
 * CustomReportInterface — un reporte que NO es "la lista de la pantalla en
 * otro formato". Tiene su propia consulta, sus propias columnas y su propia
 * forma.
 *
 * ## Por qué produce BYTES y no una estructura declarativa
 *
 * Se estudió el caso real que motivó el contrato: un reporte de ingresos de
 * ~830 líneas con cosas que un contrato de columnas no puede expresar sin
 * crecer para siempre —una fila de TOTAL al final, hoja con nombre y color
 * propios, sin el bloque de título/fecha de los reportes de lista, un
 * marcador de vacío distinto, columnas ELEGIBLES por el usuario que llegan en
 * los params, y una consulta propia con `whereExists` más un mapa de detalles
 * aparte—.
 *
 * Forzar todo eso dentro de `ColumnDefinition[]` era pelearle al problema. El
 * custom recibe el `MkReport` y devuelve el archivo. Punto.
 *
 * ## Qué NO significa
 *
 * No es una invitación a reimplementar el motor. Si el reporte es una tabla,
 * el custom puede apoyarse en `XlsxGenerator`, `CsvGenerator`,
 * `MpdfGenerator` y `ReportChrome`, y resolverse en pocas líneas. La libertad
 * es para cuando hace falta.
 *
 * ## Dos decisiones que parecen detalles y no lo son
 *
 * - **El formato es un PARÁMETRO, no parte del nombre del método.** Con
 *   `downloadXlsx()` agregar PDF significa otro método y otra rama en el
 *   registry, para siempre.
 * - **Devuelve bytes, no una respuesta HTTP.** Una respuesta HTTP ata el
 *   reporte al flujo síncrono, y el job async necesita los bytes para
 *   guardarlos en el disk.
 *
 * Un módulo puede tener 0, 1 o N customs. Cada uno es su clase con su
 * `key()`; `module()` dice a qué menú de exportación se cuelga.
 */
interface CustomReportInterface extends ReportChromeAware
{
    /**
     * Identificador único, y `MkReport::type` con el que se despacha
     * (e.g. 'payments-income'). Dos customs del mismo módulo tienen keys
     * distintas.
     */
    public function key(): string;

    /**
     * Módulo al que pertenece (e.g. 'payments'). Define en qué menú aparece.
     * NO es único: varios customs pueden compartir módulo.
     */
    public function module(): string;

    /**
     * Formatos que este reporte sabe generar. Si el front pide uno que no
     * está, se rechaza ANTES de encolar.
     *
     * @return string[]
     */
    public function supportedFormats(): array;

    /**
     * Genera el archivo y devuelve su contenido binario.
     *
     * 🔴 Corre dentro del job: no hay request HTTP, ni sesión, ni usuario
     * autenticado. Todo lo que el reporte necesite —filtros, columnas
     * elegidas, rango de fechas— viaja en `$report->params`, y el tenant en
     * `$report->tenant_id`. Leer el contexto del request acá devuelve null en
     * silencio y el reporte sale vacío marcado como exitoso.
     *
     * @param  string  $format  Uno de `supportedFormats()`.
     * @return string Bytes del archivo.
     */
    public function render(MkReport $report, string $format): string;
}
