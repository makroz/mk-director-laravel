<?php

declare(strict_types=1);

namespace Mk\Director\Export\Contracts;

use Mk\Director\Models\MkReport;

/**
 * Para los reportes cuyo título depende de QUÉ se pidió.
 *
 * El caso que lo trajo: un reporte de expensas de un período se llama
 * "Reporte de Expensas — Marzo 2026", y el período sale de los params del
 * pedido. Con `title()` a secas todos los períodos se bajaban con el mismo
 * nombre de archivo y el mismo encabezado.
 *
 * ⚠️ Esto NO abre la puerta a que el título lo mande el front. Lo que se
 * revirtió en su momento fue que el motor leyera `params['title']`, o sea
 * TEXTO del cliente: ahí había dos lugares declarando el título, ganaba el de
 * afuera, y terminaba imprimiéndose texto arbitrario en el encabezado del PDF.
 *
 * Acá el título lo sigue armando el módulo, en el back. Lo único que viene de
 * afuera son los DATOS del pedido —qué período—, que es lo mismo que ya viaja
 * para filtrar la consulta.
 */
interface TituloSegunElReporte
{
    /**
     * Título para este pedido concreto. Va al encabezado del PDF y al nombre
     * del archivo.
     */
    public function titleFor(MkReport $report): string;
}
