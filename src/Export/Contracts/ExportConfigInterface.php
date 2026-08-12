<?php

declare(strict_types=1);

namespace Mk\Director\Export\Contracts;

use Mk\Director\Models\MkReport;

/**
 * ExportConfigInterface — el contrato de "la lista de la pantalla, en otro
 * formato".
 *
 * Un módulo declara un `ExportConfig` que dice QUÉ columnas exportar, QUÉ
 * formatos soporta y cómo mutar la data antes de renderizar. El
 * `ExportConfigRegistry` los auto-descubre; el `GenerateListExportJob`
 * re-ejecuta el controller para conseguir la lista YA PROCESADA y se la pasa
 * al `ExportService` junto con este config.
 *
 * ## Diferencia con {@see CustomReportInterface}
 *
 * - `ExportConfigInterface` = **lista + formato**. El controller ya resolvió
 *   la consulta; el config sólo declara columnas, formatos y `beforeExport`.
 * - `CustomReportInterface` = **cálculo propio**. El reporte ejecuta su
 *   propia consulta y devuelve los bytes del archivo.
 *
 * ## El motor de PDF es mPDF y punto
 *
 * No hay `pdfEngine()`. Sostener dos motores de PDF no tiene valor: se
 * duplica cada bug de layout y ninguno de los dos queda bien probado.
 *
 * ## 🔴 El orden que importa
 *
 * `afterList` corre ANTES del export, no después. Es el gancho donde el
 * controller DECORA las filas, y con el orden invertido el archivo salía con
 * el dato crudo de la columna mientras la pantalla mostraba el resuelto: dos
 * verdades para la misma fila. Lo que se exporta tiene que ser exactamente lo
 * que el usuario ve.
 */
interface ExportConfigInterface extends ReportChromeAware
{
    /**
     * Clave del módulo, y `MkReport::type` con el que se despacha
     * (e.g. 'payments', 'expenses').
     *
     * 🔴 Tiene que coincidir EXACTA con la clave que resuelve el controller.
     * Si se separan, el registry no encuentra el config, el motor devuelve
     * `null` y el export se va por el fallback del consumer — sin error y sin
     * diferencia visible en el botón. Cada módulo migrado lo pinea en su test.
     */
    public function module(): string;

    /**
     * Hook opcional para mutar la data ANTES de renderizar.
     *
     * Corre DENTRO del job, después de `beforeList`/`afterList`. Sirve para
     * calcular campos derivados, filtrar filas, agregar renglones de total o
     * cargar relaciones que el controller no trajo.
     *
     * @param  iterable  $data  Collection<Model> o array de filas.
     * @return iterable Data modificada.
     */
    public function beforeExport(iterable $data, MkReport $report): iterable;

    /**
     * Relaciones que el controller debe eager-load antes del export.
     *
     * Útil para columnas que leen `category.name` o `paymentMethod.label`. Si
     * el controller ya las trae, no hace falta declararlas acá.
     *
     * @return string[]
     */
    public function requiredRelations(): array;

    /**
     * Si el motor debe pedirle al controller su bloque de `extraData` antes
     * de exportar. Default: `false`.
     *
     * ⚠️ El motor restaura el request ENTERO al salir de esa llamada, no sólo
     * el flag de export. En Condaty restaurar de a partes dejaba el request en
     * modo estadísticas y el PDF de Ingresos salía con 3 filas en lugar de
     * 200+ páginas.
     */
    public function useExtraData(): bool;

    /**
     * Formatos soportados. Default: `['pdf', 'xlsx', 'csv']`.
     *
     * Si un config declara sólo `['pdf']`, el motor rechaza xlsx/csv con 400
     * ANTES de encolar — y el front muestra un botón simple en vez del menú.
     *
     * @return string[]
     */
    public function supportedFormats(): array;

    /**
     * Separador del CSV. Default: el de la config. Excel en es-* suele
     * preferir `;`.
     */
    public function csvSeparator(): string;

    /**
     * El texto del renglón de totales: "Total de morosidad", "Saldo total a
     * cobrar". Default: `'Total'`.
     *
     * 🔴 QUÉ columnas se suman lo dice cada columna con `->sumarize()`; acá va
     * sólo el texto, porque nombra a la FILA y no a una columna. Si viviera en
     * la columna, dos columnas sumadas podrían declarar dos etiquetas distintas
     * para el mismo renglón.
     *
     * ⚠️ El renglón aparece sólo si alguna columna suma, y sólo en el PDF.
     */
    public function etiquetaDeTotales(): string;
}
