<?php

declare(strict_types=1);

namespace Mk\Director\Export\Contracts;

use Mk\Director\Export\Support\ReportHeaderData;
use Mk\Director\Models\MkReport;

/**
 * ReportHeaderProvider — de dónde salen el logo y los nombres del encabezado.
 *
 * ## Por qué existe
 *
 * En el motor original ésta era la ÚNICA dependencia de dominio que quedaba
 * dentro del renderizador: `HeaderBuilder` hacía `Client::find($report->
 * client_id)` para poner el nombre del condominio y buscar su logo. Una línea,
 * pero era la línea que hacía imposible sacar el motor de ese proyecto.
 *
 * Acá el motor no conoce ningún modelo del consumer. Pide estos datos al
 * contenedor y arma el HTML con lo que le den.
 *
 * ## Cómo se implementa en un proyecto
 *
 * Bindeá tu implementación en un `ServiceProvider`:
 *
 * ```php
 * $this->app->bind(ReportHeaderProvider::class, MiEncabezado::class);
 * ```
 *
 * Sin binding se usa {@see DefaultReportHeaderProvider}, que devuelve el
 * `app.name` y ningún logo. Un reporte con encabezado genérico es mejor que
 * un motor que no arranca.
 *
 * 🔴 La implementación corre DENTRO del job: no hay request, ni sesión, ni
 * usuario autenticado. Resolvé todo desde `$report->tenant_id` y
 * `$report->user_id`. Leer el contexto del request acá devuelve null en
 * silencio y el encabezado sale vacío sin que nada falle.
 */
interface ReportHeaderProvider
{
    public function forReport(MkReport $report): ReportHeaderData;
}
