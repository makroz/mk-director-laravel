<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * ReportChrome — la ÚNICA fuente de verdad del "marco" de todo reporte: pie y
 * bloque de firmas.
 *
 * **Por qué existe.** En el motor original el marco vivía repartido en cinco
 * lugares: dos constructores de encabezado y tres de pie —uno privado y
 * hardcodeado dentro del servicio, otro para el motor de PDF viejo, y una
 * copia muerta dentro del manager—. Cambiar el texto legal del pie obligaba a
 * tocar tres archivos, y nada avisaba si te olvidabas de uno.
 *
 * **Qué NO hace.** No decide márgenes (eso es del `MpdfGenerator`) ni estilos
 * de tabla.
 *
 * **Cómo se personaliza.** Los textos y el logo salen de
 * `mk_director.export.chrome`. Un reporte puntual puede pisar el pie entero
 * devolviendo HTML propio desde `ExportConfigInterface::footerHtml()`.
 *
 * 🔴 Los defaults son VACÍOS a propósito. En el original el texto legal
 * nombraba al producto y el bloque de firmas decía "Comité de administración /
 * Administrador a cargo" — perfecto para un sistema de condominios y absurdo
 * para cualquier otro. Un pie vacío es un reporte sobrio; un pie con el
 * nombre de otro producto es un bug que sale impreso.
 */
final class ReportChrome
{
    /**
     * El pie del PDF: logo opcional, líneas legales configurables y el
     * indicador de página.
     *
     * `{PAGENO}` y `{nb}` los resuelve mPDF sobre la instancia única, así que
     * la numeración es GLOBAL y real — no "1 de 2" repetido por cada segmento.
     *
     * ⚠️ Si cambiás el alto de este bloque hay que volver a medir
     * `MpdfGenerator::MARGIN_BOTTOM`: el margen inferior se calcula del alto
     * real del pie, o el contenido lo pisa.
     */
    public static function footerHtml(): string
    {
        $logo = self::bloqueDelLogo();
        $legales = self::bloqueLegal();

        return <<<HTML
        <div style="font-family: sans-serif; font-size: 8px; color: #414141; text-align: center; padding-top: 6px;">
            {$logo}
            {$legales}
            <div style="border-top: 0.5px solid #a7a7a7; padding-top: 4px; color: #414141;">
                Página {PAGENO} de {nb}
            </div>
        </div>
        HTML;
    }

    /**
     * El bloque de firmas, que va UNA sola vez al final del último segmento.
     *
     * Devuelve string vacío si la config no declara rótulos: un reporte
     * operativo no tiene por qué cerrar con firmas.
     */
    public static function signaturesHtml(): string
    {
        $rotulos = array_values(array_filter(
            (array) config('mk_director.export.chrome.signatures', [])
        ));

        if ($rotulos === []) {
            return '';
        }

        $ancho = number_format(100 / count($rotulos), 2, '.', '');

        $celdas = '';
        foreach ($rotulos as $rotulo) {
            $texto = htmlspecialchars((string) $rotulo, ENT_QUOTES, 'UTF-8');
            $celdas .= <<<HTML
                <td style="width: {$ancho}%;">
                    <div style="border-top: 1px solid #414141; width: 200px; margin: 0 auto 5px;"></div>
                    <div style="font-size: 12px; color: #414141;">{$texto}</div>
                </td>
            HTML;
        }

        return <<<HTML
        <div style="margin-top: 100px; page-break-inside: avoid;">
            <table style="width: 100%; text-align: center;">
                <tr>{$celdas}</tr>
            </table>
        </div>
        HTML;
    }

    private static function bloqueLegal(): string
    {
        $lineas = array_values(array_filter(
            (array) config('mk_director.export.chrome.legal_lines', [])
        ));

        if ($lineas === []) {
            return '';
        }

        $html = '';
        foreach ($lineas as $linea) {
            $texto = htmlspecialchars((string) $linea, ENT_QUOTES, 'UTF-8');
            $html .= '<div style="color: #a7a7a7; margin-bottom: 2px;">'.$texto.'</div>';
        }

        return $html;
    }

    private static function bloqueDelLogo(): string
    {
        $logo = self::footerLogoBase64();

        if ($logo === null) {
            return '';
        }

        $w = (int) config('mk_director.export.chrome.footer_logo_width', 102);
        $h = (int) config('mk_director.export.chrome.footer_logo_height', 22);

        return '<div style="margin-bottom: 6px;">'
            .'<img src="'.$logo.'" style="width: '.$w.'px; height: '.$h.'px;">'
            .'</div>';
    }

    /**
     * El logo del pie como data URI, o `null` si no está configurado.
     *
     * 🔴 Se lee del disco y se embebe: mPDF corre dentro de un job, sin
     * request, y no puede resolver rutas relativas ni salir a buscar una URL.
     * Un `<img src="/img/logo.png">` ahí adentro sale como un recuadro vacío.
     *
     * Se cachea por proceso porque el pie se arma una vez por SEGMENTO, y un
     * reporte de 200 páginas leería el mismo archivo decenas de veces.
     *
     * ⚠️ Una ruta configurada que no existe devuelve `null` en vez de romper:
     * el reporte sale sin logo. Que un logo mal configurado deje sin reportes
     * a todo el sistema es peor que un pie sobrio.
     */
    public static function footerLogoBase64(): ?string
    {
        static $cache = [];

        $ruta = config('mk_director.export.chrome.footer_logo');

        if (! is_string($ruta) || $ruta === '') {
            return null;
        }

        if (array_key_exists($ruta, $cache)) {
            return $cache[$ruta];
        }

        if (! is_file($ruta) || ! is_readable($ruta)) {
            return $cache[$ruta] = null;
        }

        $bytes = @file_get_contents($ruta);

        if ($bytes === false) {
            return $cache[$ruta] = null;
        }

        $mime = match (strtolower((string) pathinfo($ruta, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };

        return $cache[$ruta] = 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
