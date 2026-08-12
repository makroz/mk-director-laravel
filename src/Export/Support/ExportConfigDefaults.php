<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Models\MkReport;

/**
 * Los defaults de {@see ExportConfigInterface}.
 *
 * Un módulo hace `use ExportConfigDefaults;` y escribe sólo lo que le importa:
 * `module()`, `title()`, `columns()` y los overrides puntuales. Todo lo demás
 * sale de acá.
 *
 * 🔴 **Sin este trait el contrato es inusable.** Entre la interface y su padre
 * `ReportChromeAware` son TRECE métodos, y en un config real sólo tres dicen
 * algo del módulo. Obligar a escribir los otros diez es copiar y pegar que se
 * desincroniza solo — y, peor, hace que agregar un método declarativo nuevo al
 * contrato ROMPA todos los módulos existentes. Con el trait, un método nuevo
 * nace con default y nadie se entera.
 *
 * Los valores por defecto no lo pisan a uno: cada `chunkSize` y cada formato
 * salen de la config del proyecto, así que un consumer los cambia para todos
 * sus módulos en un solo lugar.
 */
trait ExportConfigDefaults
{
    /** Sin mutación: la data pasa tal cual. */
    public function beforeExport(iterable $data, MkReport $report): iterable
    {
        return $data;
    }

    /** Sin eager-load adicional: el controller trae las suyas. */
    public function requiredRelations(): array
    {
        return [];
    }

    /** La lista simple alcanza. */
    public function useExtraData(): bool
    {
        return false;
    }

    /**
     * Filas por segmento, de la config.
     *
     * ⚠️ El PDF va MUCHO más chico que los otros dos, y no es un descuido:
     * mPDF mantiene el documento entero en memoria mientras lo arma, así que
     * un segmento grande no acelera — revienta. XLSX y CSV escriben en
     * streaming.
     */
    public function chunkSize(string $format): int
    {
        $config = (array) config('mk_director.export.chunk_size', []);

        $clave = match (strtolower($format)) {
            'xlsx', 'excel' => 'xlsx',
            'csv' => 'csv',
            default => 'pdf',
        };

        $defaults = ['pdf' => 20, 'xlsx' => 500, 'csv' => 1000];

        return max(1, (int) ($config[$clave] ?? $defaults[$clave]));
    }

    /** @return string[] */
    public function supportedFormats(): array
    {
        return ['pdf', 'xlsx', 'csv'];
    }

    /** `null` = el encabezado estándar de {@see HeaderBuilder}. */
    public function headerHtml(MkReport $report): ?string
    {
        return null;
    }

    /** `null` = el pie estándar de {@see ReportChrome}. */
    public function footerHtml(): ?string
    {
        return null;
    }

    /**
     * ⚠️ `true`, pero el bloque sale VACÍO si la config no declara rótulos en
     * `export.chrome.signatures`. O sea que un proyecto que no quiere firmas
     * no tiene que tocar nada en sus módulos.
     */
    public function includeSignatures(): bool
    {
        return true;
    }

    public function csvSeparator(): string
    {
        return (string) config('mk_director.export.csv_separator', ',');
    }

    /**
     * El texto del renglón de totales.
     *
     * ⚠️ Que el renglón EXISTA no lo decide esto: lo decide si alguna columna
     * declaró `->sumarize()`. Acá va sólo cómo se llama.
     */
    public function etiquetaDeTotales(): string
    {
        return 'Total';
    }
}
