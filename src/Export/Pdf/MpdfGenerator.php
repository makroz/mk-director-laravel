<?php

declare(strict_types=1);

namespace Mk\Director\Export\Pdf;

use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use ReflectionClass;

/**
 * Envoltorio mínimo sobre mPDF para generar los PDF del motor.
 *
 * ## Por qué mPDF y no Dompdf
 *
 * Medido sobre reportes reales de +400 páginas: Dompdf + FPDI consumía ~2 GB
 * de churn concatenando los segmentos y moría con "Allowed memory size
 * exhausted". mPDF respeta `margin-bottom`, es varias veces más rápido en
 * tablas grandes, y da numeración de páginas GLOBAL (`{nb}`) nativa sobre una
 * sola instancia — o sea, sin FPDI, sin concatenar y sin archivos temporales.
 *
 * No hay elección de motor. Sostener dos duplica cada bug de layout y ninguno
 * de los dos queda bien probado.
 *
 * ## 🔴 Por qué se escribe por SEGMENTOS y no de una
 *
 * No es una optimización: es obligatorio. mPDF rechaza un `WriteHTML` que
 * supere `pcre.backtrack_limit` con un "pass your HTML in smaller chunks".
 *
 * El flujo es UNA instancia por reporte, alimentada con sub-tablas completas
 * —cada una con su `<thead>`— vía {@see writeChunk}. Cada `</table>` deja que
 * mPDF haga el layout y libere el buffer de ese segmento; el árbol de páginas
 * se acumula hasta el `Output`. Medido: 150.000 filas → 5.193 páginas, pico
 * ~418 MB, lineal con la cantidad de páginas.
 *
 * ⚠️ Y {@see newPage} entre segmentos no es cosmético: sin él el `<thead>` del
 * segmento siguiente reaparece a mitad de página, en el borde.
 *
 * ## 🔴 El margen inferior lo MIDE mPDF, no lo adivinamos nosotros
 *
 * En el original el margen inferior era un `25` escrito en el código, medido a
 * mano contra un pie concreto. Cualquier pie personalizado por proyecto —una
 * línea legal más, un logo más alto— lo rompe, y el modo de fallar es
 * intermitente: el contenido pisa el pie sólo en las páginas donde la última
 * fila cae justo.
 *
 * Ahora el margen sale de `export.margins` y por defecto está en `'stretch'`:
 * mPDF recalcula, **en cada página**, `max(piso, margin_footer + altoReal +
 * padding)`. El alto real lo mide él renderizando el pie; no hay fórmula
 * nuestra que pueda hacerlo, porque depende de la fuente, del cuerpo, del
 * ancho de hoja y de cuántas líneas envuelva cada bloque.
 *
 * Para ajustar el aire hay una sola perilla, `auto_padding`. Y para no ir a
 * ciegas, {@see medidasDelMarco} devuelve lo que mPDF midió: con
 * `export.log_margins` en true queda en el log de cada reporte.
 */
final class MpdfGenerator
{
    private Mpdf $mpdf;

    /** @var array<string, int|float|string> */
    private array $margenes;

    /**
     * La última foto de las medidas del marco.
     *
     * 🔴 Hace falta porque `Output()` DESTRUYE los márgenes calculados:
     * al cerrar el documento mPDF restaura `bMargin` desde el estado guardado
     * y lo deja en el valor de `margin_footer`. Medido con un pie de 8 líneas:
     * `bMargin` vale 40,51 hasta el `Output` y 9,00 después. Leer las medidas
     * al final —que es lo natural— devuelve un número que no tiene nada que
     * ver con lo que se usó para paginar.
     *
     * @var array<string, float>|null
     */
    private ?array $foto = null;

    public function __construct(
        ?string $headerHtml = null,
        ?string $footerHtml = null,
        ?string $memoryLimit = null,
        ?string $orientation = null,
        /**
         * Encabezado que se REPITE en cada página.
         *
         * ⚠️ Es distinto del `$headerHtml` del reporte, que va una sola vez en
         * el cuerpo de la página 1. Un consumer que quiera membrete en todas
         * las hojas lo pasa por acá, y el margen superior se estira solo para
         * dejarle lugar.
         */
        ?string $pageHeaderHtml = null,
    ) {
        $this->margenes = self::margenesConfigurados();

        $this->mpdf = new Mpdf(array_filter([
            'tempDir' => sys_get_temp_dir(),
            'memory_limit' => $memoryLimit ?? (string) config('mk_director.export.job_memory_limit', '2G'),
            'margin_top' => $this->margenes['top'],
            'margin_bottom' => $this->margenes['bottom'],
            'margin_left' => $this->margenes['left'],
            'margin_right' => $this->margenes['right'],
            'margin_header' => $this->margenes['header'],
            'margin_footer' => $this->margenes['footer'],
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => $orientation ?? (string) config('mk_director.export.pdf_orientation', 'P'),
            'fontDir' => self::fontDir(),
            // Empaca la data de la tabla como strings en vez de un objeto por
            // celda. Es lo que hace que una sola instancia escale a miles de
            // páginas sin FPDI ni concatenación.
            'packTableData' => true,
        ], fn ($v) => $v !== null));

        // 🔴 Estas tres líneas son las que hacen que el pie de CUALQUIER
        // proyecto entre. mPDF recalcula el margen en cada página con el alto
        // real del bloque renderizado.
        //
        // ⚠️ Van ANTES de `SetHTMLHeader`/`SetHTMLFooter`: la medición corre al
        // instalar el bloque, y con los flags todavía en `false` no se aplica.
        $this->mpdf->setAutoTopMargin = $this->margenes['auto_top'];
        $this->mpdf->setAutoBottomMargin = $this->margenes['auto_bottom'];
        $this->mpdf->autoMarginPadding = $this->margenes['auto_padding'];

        if ($pageHeaderHtml !== null) {
            $this->mpdf->SetHTMLHeader($pageHeaderHtml);
        } elseif ($headerHtml !== null) {
            $this->mpdf->SetHTMLHeader($headerHtml);
        }

        if ($footerHtml !== null) {
            $this->mpdf->SetHTMLFooter($footerHtml);
        }
    }

    /**
     * Renderiza HTML completo en un solo `WriteHTML` y devuelve los bytes.
     *
     * ⚠️ Para reportes grandes va el flujo por segmentos ({@see writeChunk} +
     * {@see newPage} + {@see outputToFile}). Este método es para casos chicos
     * y para tests: no escala más allá del `pcre.backtrack_limit`.
     */
    public function render(string $html): string
    {
        $this->ensurePcreLimits();
        $this->mpdf->WriteHTML($html);

        // ⚠️ ANTES del Output, siempre. Ver el docblock de {@see $foto}.
        $this->sacarLaFoto();

        return $this->mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * Escribe un segmento en ESTA instancia. Ver el docblock de la clase para
     * por qué el segmentado es obligatorio.
     */
    public function writeChunk(string $html): void
    {
        $this->ensurePcreLimits();
        $this->mpdf->WriteHTML($html);
        $this->sacarLaFoto();
    }

    /**
     * Fuerza página nueva antes del próximo segmento.
     *
     * ⚠️ Sin esto el `<thead>` del segmento siguiente reaparece a mitad de
     * página. Con página nueva cada segmento arranca en hoja limpia y el
     * encabezado sólo se repite por página dentro del segmento, que es el
     * comportamiento nativo de mPDF.
     */
    public function newPage(): void
    {
        $this->mpdf->AddPage();
    }

    public function outputToFile(string $path): void
    {
        $this->sacarLaFoto();
        $this->mpdf->Output($path, Destination::FILE);
    }

    public function getMpdf(): Mpdf
    {
        return $this->mpdf;
    }

    /**
     * Lo que mPDF midió del marco, en milímetros.
     *
     * 🔴 Existe para que ajustar el pie de un proyecto no sea prueba y error a
     * ciegas. `footer_height` es el alto REAL del bloque renderizado; con eso
     * y `bottom_margin` se ve de una si el `auto_padding` alcanza o si el
     * contenido está quedando pegado.
     *
     * ⚠️ Sólo tiene sentido DESPUÉS de escribir al menos una página: antes de
     * eso mPDF no midió nada y los altos son 0.
     *
     * ⚠️ Y con `auto_bottom`/`auto_top` en `false` los altos salen 0 SIEMPRE:
     * en modo fijo mPDF no necesita el alto del bloque, así que ni lo calcula.
     * O sea que quien fija el margen a mano se queda justamente sin la
     * herramienta que le diría si le alcanza. Para medir, prendé el modo
     * automático una corrida.
     *
     * @return array{footer_height: float, header_height: float, bottom_margin: float, top_margin: float, page_height: float}
     */
    public function medidasDelMarco(): array
    {
        return $this->foto ?? $this->leerMedidas();
    }

    /**
     * Guarda las medidas mientras todavía son válidas.
     *
     * 🔴 Se llama después de CADA escritura y antes de cualquier `Output`. No
     * alcanza con hacerlo una vez al final: el `Output` ya destruyó el número.
     */
    private function sacarLaFoto(): void
    {
        $this->foto = $this->leerMedidas();
    }

    /** @return array<string, float> */
    private function leerMedidas(): array
    {
        return [
            'footer_height' => (float) ($this->mpdf->HTMLFooter['h'] ?? 0),
            'header_height' => (float) ($this->mpdf->HTMLHeader['h'] ?? 0),
            'bottom_margin' => (float) $this->mpdf->bMargin,
            'top_margin' => (float) $this->mpdf->tMargin,
            'page_height' => (float) $this->mpdf->h,
        ];
    }

    /**
     * Cuántas líneas de texto entran en el pie antes de empujar el margen por
     * encima de `$topeDeMargen`.
     *
     * 🔴 Es la respuesta a "¿cuántas líneas me entran?" — la pregunta que el
     * número fijo del original no podía contestar. Se calcula del alto MEDIDO
     * de una línea real, no de una fórmula: el interlineado depende de la
     * fuente y del cuerpo, y estimarlo da un número que parece bien y no lo es.
     *
     * ⚠️ Devuelve `null` si todavía no hay pie medido — o sea, si se llama
     * antes de escribir una página.
     */
    public function lineasQueEntranEnElPie(float $topeDeMargen, int $lineasActuales): ?int
    {
        $medidas = $this->medidasDelMarco();

        if ($medidas['footer_height'] <= 0 || $lineasActuales < 1) {
            return null;
        }

        $altoDeLinea = $medidas['footer_height'] / $lineasActuales;
        $disponible = $topeDeMargen
            - (float) $this->margenes['footer']
            - (float) $this->margenes['auto_padding'];

        return max(0, (int) floor($disponible / $altoDeLinea));
    }

    /**
     * Los márgenes configurados, normalizados.
     *
     * ⚠️ `auto_top`/`auto_bottom` aceptan `false` para fijar el margen. Un
     * `(bool)` sobre la config no sirve: mPDF distingue `'stretch'` de `'pad'`,
     * así que cualquier valor que no sea uno de esos dos se trata como fijo.
     *
     * @return array<string, int|float|string>
     */
    private static function margenesConfigurados(): array
    {
        $c = (array) config('mk_director.export.margins', []);

        $modo = function (string $clave) use ($c): string|false {
            $valor = $c[$clave] ?? 'stretch';

            return in_array($valor, ['stretch', 'pad'], true) ? $valor : false;
        };

        return [
            'top' => $c['top'] ?? 8,
            'bottom' => $c['bottom'] ?? 10,
            'left' => $c['left'] ?? 10,
            'right' => $c['right'] ?? 10,
            'header' => $c['header'] ?? 9,
            'footer' => $c['footer'] ?? 9,
            'auto_top' => $modo('auto_top'),
            'auto_bottom' => $modo('auto_bottom'),
            'auto_padding' => $c['auto_padding'] ?? 2,
        ];
    }

    /**
     * Deja las medidas en el log cuando `export.log_margins` está prendido.
     *
     * Es lo que convierte el ajuste del pie en un número y no en una
     * corazonada: se ve el alto real, el margen que salió, y cuánto aire
     * quedó.
     */
    public function registrarMedidas(): void
    {
        if (! config('mk_director.export.log_margins', false)) {
            return;
        }

        $m = $this->medidasDelMarco();

        Log::debug('[mk-director] márgenes del PDF (mm)', $m + [
            'aire_bajo_el_contenido' => round(
                $m['bottom_margin'] - (float) $this->margenes['footer'] - $m['footer_height'],
                2
            ),
            'auto_bottom' => $this->margenes['auto_bottom'],
            'auto_padding' => $this->margenes['auto_padding'],
        ]);
    }

    /**
     * Los directorios de fuentes.
     *
     * ⚠️ Cuando el consumer declara los suyos hay que sumar TAMBIÉN el de
     * mPDF, no reemplazarlo: sin él, mPDF no encuentra la fuente de respaldo
     * (`DejaVuSerifCondensed.ttf`) y tira excepción al primer carácter que la
     * necesite — un acento raro en una fila cualquiera tumba el reporte
     * entero.
     *
     * @return string[]|null
     */
    private static function fontDir(): ?array
    {
        $propio = config('mk_director.export.pdf_font_dir');

        if ($propio === null || $propio === '' || $propio === []) {
            return null;
        }

        $dirs = is_array($propio) ? $propio : [$propio];
        $dirs[] = self::fontDirDeMpdf();

        return array_values(array_unique(array_filter($dirs, 'is_string')));
    }

    /**
     * El directorio de fuentes que trae mPDF, resuelto desde la clase misma.
     *
     * 🔴 No se arma con `base_path('vendor/mpdf/...')`: eso asume que el
     * paquete está instalado en el vendor de la app raíz, y en un monorepo con
     * paquetes enlazados por path no lo está. La reflexión sobre la clase da
     * la ruta real, la tenga donde la tenga.
     */
    private static function fontDirDeMpdf(): ?string
    {
        $archivo = (new ReflectionClass(Mpdf::class))->getFileName();

        if ($archivo === false) {
            return null;
        }

        $ttf = dirname($archivo, 2).'/ttfonts';

        return is_dir($ttf) ? $ttf : null;
    }

    /**
     * mPDF usa PCRE para parsear HTML y CSS, y el default de
     * `pcre.backtrack_limit` se queda corto con HTML grande.
     *
     * ⚠️ Subirlo no reemplaza al segmentado: mPDF impone su propio tope por
     * `WriteHTML` igual.
     */
    private function ensurePcreLimits(): void
    {
        ini_set('pcre.backtrack_limit', '10000000');
        ini_set('pcre.recursion_limit', '1000000');
    }
}
