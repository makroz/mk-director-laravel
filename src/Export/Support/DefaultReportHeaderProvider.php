<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Mk\Director\Export\Contracts\ReportHeaderProvider;
use Mk\Director\Models\MkReport;

/**
 * El encabezado que se usa cuando el consumer no bindeó el suyo.
 *
 * Devuelve el `app.name` como organización, resuelve el nombre de quien pidió
 * el reporte desde el modelo de usuario configurado, y no pone logo.
 *
 * 🔴 Existe para que el motor ARRANQUE sin configuración. Un reporte con
 * encabezado genérico es un problema de estética; un motor que tira excepción
 * porque falta un binding es un problema de adopción — y se descubre en
 * producción, dentro de un job, cuando el reporte queda en `failed` sin que
 * nadie lo mire.
 */
final class DefaultReportHeaderProvider implements ReportHeaderProvider
{
    public function forReport(MkReport $report): ReportHeaderData
    {
        return new ReportHeaderData(
            organization: (string) config('app.name', 'App'),
            organizationLabel: null,
            issuedBy: $this->nombreDeQuienPidio($report),
        );
    }

    /**
     * El nombre de quien pidió el reporte, con los atributos más comunes.
     *
     * ⚠️ Cae a 'Sistema' —no a vacío ni a excepción— cuando el usuario ya no
     * existe. Un reporte puede sobrevivir al usuario que lo pidió, y ese caso
     * no puede romper la generación.
     */
    private function nombreDeQuienPidio(MkReport $report): string
    {
        $modelo = MkReport::userModel();

        if (! $modelo || ! class_exists($modelo)) {
            return 'Sistema';
        }

        $user = $modelo::find($report->user_id);

        if (! $user) {
            return 'Sistema';
        }

        $nombre = trim(implode(' ', array_filter([
            $user->name ?? null,
            $user->last_name ?? null,
        ])));

        return $nombre !== '' ? $nombre : 'Sistema';
    }
}
