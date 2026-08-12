<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Mk\Director\Export\Pdf\MpdfGenerator;

/**
 * El CSS de la tabla del PDF.
 *
 * 🔴 **Nada de comentarios `//` acá adentro.** Un `//` dentro de un `<style>`
 * no es un comentario CSS: corrompe la hoja entera y mPDF deja de aplicar
 * reglas que sí soporta. Ese fue el origen de una conclusión equivocada que
 * costó caro — se dio por hecho que "mPDF no respeta `background-color` en un
 * selector de tag" y se pasó a inlinear el color en cada `<td>`. Con 72.000
 * celdas eso infló el HTML y alimentó el OOM. El motor estaba bien; el CSS
 * estaba roto. Sólo `/* … *&#47;`.
 *
 * ⚠️ Sin regla `@page`: los márgenes salen del constructor de
 * {@see MpdfGenerator}. Con `@page` mPDF la respeta,
 * pisa esos márgenes y rompe el pie.
 */
final class TableStyles
{
    /**
     * @param  string|null  $fontFamily  Familia tipográfica. `null` usa la de
     *                                   la config, o `sans-serif`.
     */
    public static function css(?string $fontFamily = null): string
    {
        $font = $fontFamily
            ?? (string) config('mk_director.export.pdf_font_family', 'sans-serif');

        return <<<CSS
        <style>
            body {
                font-family: {$font};
                font-size: 12px;
                margin: 10px 10px;
                color: #414141;
            }

            /* `border-collapse: collapse` con líneas de 0.5px. Con `separate`
               cada celda dibuja su borde y los adyacentes se suman: la grilla
               se ve del doble de gruesa. */
            .data-table {
                width: 100%;
                border-collapse: collapse;
            }

            /* Marco de la tabla. Esquinas CUADRADAS y sin div envolvente:
               mPDF ignora `border-radius` en un `<table>`, y envolverla en un
               div (a) deja las celdas de las esquinas sobresaliendo del borde
               redondeado y (b) ROMPE la paginación — las filas invaden el área
               del pie porque el margen inferior deja de respetarse. */
            .data-table.framed {
                border: 0.5px solid #a7a7a7;
            }

            /* Repite el encabezado en cada página. */
            thead {
                display: table-header-group;
            }

            /* Grilla interna: sólo derecha y abajo. */
            th, .data-td {
                padding: 7px 8px;
                font-size: 12px;
                text-align: left;
                border-right: 0.5px solid #a7a7a7;
                border-bottom: 0.5px solid #a7a7a7;
                word-break: break-word;
                overflow-wrap: break-word;
                white-space: normal;
            }

            /* Con `collapse`, el borde de la última columna se funde con el
               del marco: se suelta para no dibujarlo dos veces. */
            th.last-col, .data-td.last-col {
                border-right: none;
            }

            /* No cortar una fila entre dos páginas: si no entra, se mueve
               entera a la siguiente. */
            .data-tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            th {
                background-color: #d2d3d3;
                font-size: 13px;
                font-weight: 600;
                color: #414141;
                padding: 10px 8px;
            }

            /* 🔴 Zebra por CLASE, no por `:nth-child()`. mPDF v8 no soporta
               ese pseudo-selector pero sí uno de clase. El renderer marca cada
               `<tr>` con `.even`/`.odd` según su índice, así el color vive en
               dos reglas y no inline en decenas de miles de celdas. */
            .data-tr.even td {
                background-color: #eeeeee;
            }

            .data-tr.odd td {
                background-color: #fbfbfb;
            }

            /* El renglón de totales del pie.
               ⚠️ Le gana a la zebra porque es más específica: dos clases
               contra una. */
            .data-tr.total-row td {
                background-color: #eef9f4;
            }

            .total-label {
                font-weight: 500;
                color: #414141;
            }
        </style>
        CSS;
    }
}
