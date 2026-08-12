<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use Mk\Director\Export\Contracts\ReportHeaderProvider;

/**
 * Los datos del encabezado de un reporte, ya resueltos.
 *
 * Es el valor que devuelve un {@see ReportHeaderProvider}
 * y lo único que el renderizador conoce del dominio del consumer.
 *
 * `organizationLabel` existe porque el rótulo cambia con el negocio: un
 * sistema de condominios dice "Condominio:", uno de restaurantes dice
 * "Sucursal:" y uno single-tenant no dice nada. Estaba escrito fijo en el
 * HTML del motor original, que es exactamente el tipo de detalle que impide
 * reusarlo.
 */
final class ReportHeaderData
{
    /**
     * @param  string  $organization  Nombre de la organización / tenant.
     * @param  string|null  $organizationLabel  Rótulo. `null` omite la línea entera.
     * @param  string  $issuedBy  Quién pidió el reporte.
     * @param  string|null  $issuedByRole  Su rol, si el consumer lo tiene.
     * @param  string|null  $logoBase64  Logo listo para `<img src>` (data URI
     *                                   completo). `null` = sin logo.
     * @param  string|null  $timezone  Zona para la fecha del encabezado.
     *                                 `null` = la de la app.
     * @param  string|null  $locale  Locale para el nombre del día/mes.
     *                               `null` = el de la app.
     */
    public function __construct(
        public readonly string $organization,
        public readonly ?string $organizationLabel = null,
        public readonly string $issuedBy = '',
        public readonly ?string $issuedByRole = null,
        public readonly ?string $logoBase64 = null,
        public readonly ?string $timezone = null,
        public readonly ?string $locale = null,
    ) {}
}
