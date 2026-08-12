<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Carbon\Carbon;
use Mk\Director\Export\Contracts\ReportHeaderProvider;
use Mk\Director\Models\MkReport;

/**
 * El encabezado estándar del PDF: título, organización, quién lo emitió,
 * fecha y logo.
 *
 * 🔴 **Acá estaba la única línea que hacía imposible sacar el motor del
 * proyecto.** El original hacía `Client::find($report->client_id)` y buscaba
 * el logo por convención de nombre de archivo (`LOGOP-{id}.webp`, fallback
 * `LOGO-{id}.webp`, fallback `logo_empresa.png`) — todo eso es política de UN
 * producto, no del motor. Ahora los datos llegan resueltos desde un
 * {@see ReportHeaderProvider} que implementa el consumer.
 *
 * ⚠️ Y el original tenía un bug que vale recordar: el parámetro del logo se
 * tipó `?int` cuando el id del tenant era un UUID string, así que el job
 * moría con `TypeError` justo en el camino que nadie prueba a mano. Acá no
 * hay tipo que adivinar: el provider devuelve el logo ya resuelto.
 *
 * 🔴 No lee `auth()` ni el contexto del request: corre dentro del job, donde
 * los dos devuelven null en silencio y el encabezado sale vacío sin que nada
 * falle. Todo sale de `$report` y del provider.
 */
final class HeaderBuilder
{
    public static function build(MkReport $report, string $title, ?string $sub = null): string
    {
        $datos = app(ReportHeaderProvider::class)->forReport($report);

        $titulo = self::escapar($title !== '' ? $title : 'Reporte');

        $subHtml = self::presente($sub)
            ? '<div style="font-size: 14px; font-weight: 400; margin-top: -6px;">'
                .'<label style="color: #a7a7a7;">'.self::escapar($sub).'</label>'
                .'</div>'
            : '';

        $organizacion = self::linea($datos->organizationLabel, $datos->organization);
        $emisor = self::linea('Emitido por:', self::textoDelEmisor($datos));
        $fecha = self::linea('Fecha y hora:', self::fechaDeEmision($datos));
        $logo = self::bloqueDelLogo($datos);

        return <<<HTML
        <div style="background-color: #f7f7f7; border: 1px solid #a7a7a7; border-radius: 10px; padding: 20px; margin-bottom: 16px; font-family: sans-serif;">
            <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse;">
                <tr>
                    <td style="width: 75%; vertical-align: top;">
                        <div style="font-size: 16px; font-weight: 600;"><label style="color: #414141;">{$titulo}</label></div>
                        {$subHtml}
                        {$organizacion}
                        {$emisor}
                        {$fecha}
                    </td>
                    <td style="width: 25%; text-align: center; vertical-align: middle;">{$logo}</td>
                </tr>
            </table>
        </div>
        HTML;
    }

    /**
     * "Nombre - Rol", o sólo el nombre si el consumer no maneja roles.
     */
    private static function textoDelEmisor(ReportHeaderData $datos): ?string
    {
        if (! self::presente($datos->issuedBy)) {
            return null;
        }

        return self::presente($datos->issuedByRole)
            ? $datos->issuedBy.' - '.$datos->issuedByRole
            : $datos->issuedBy;
    }

    /**
     * La fecha de emisión, en la zona del reporte.
     *
     * 🔴 `now()` a secas da UTC y el encabezado diría una hora que el lector
     * no reconoce — el mismo problema que {@see DateFormat} resuelve para las
     * celdas. Acá se usa la zona que el provider declare, o la del motor.
     */
    private static function fechaDeEmision(ReportHeaderData $datos): string
    {
        $ahora = Carbon::now($datos->timezone ?? DateFormat::displayTimezone());

        if ($datos->locale !== null) {
            $ahora->locale($datos->locale);
        }

        return $ahora->format(DateFormat::patron('datetime'));
    }

    private static function bloqueDelLogo(ReportHeaderData $datos): string
    {
        if (! self::presente($datos->logoBase64)) {
            return '';
        }

        return '<img src="'.$datos->logoBase64.'" style="width: auto; max-width: 130px; height: auto; max-height: 62px;">';
    }

    /**
     * Una línea "rótulo: valor" del encabezado, o vacío si no hay valor.
     *
     * ⚠️ Devolver vacío en vez de "rótulo: -/-" es a propósito: el marcador de
     * celda existe para una TABLA, donde la columna sigue ahí y hay que
     * distinguir "no hay dato" de "se perdió la columna". En el encabezado no
     * hay columna que perder — un rótulo colgando sin valor sólo ensucia.
     */
    private static function linea(?string $rotulo, ?string $valor): string
    {
        if (! self::presente($valor)) {
            return '';
        }

        $etiqueta = self::presente($rotulo)
            ? '<label style="color: #a7a7a7;">'.self::escapar($rotulo).' </label>'
            : '';

        return '<div style="font-size: 12px;">'
            .$etiqueta
            .'<span style="color: #414141;">'.self::escapar($valor).'</span>'
            .'</div>';
    }

    private static function presente(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }

    private static function escapar(?string $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}
