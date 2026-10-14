<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use RuntimeException;

/**
 * El filtro del export trae más filas que `mk_director.export.max_rows`.
 *
 * No es un fallo del job: es una respuesta para quien pidió el reporte. Por
 * eso el job la registra en el reporte y NO la relanza — reintentar da el
 * mismo resultado, y `failed()` pisaría el mensaje con "Job failed: …".
 */
final class ExportTooLargeException extends RuntimeException
{
    public static function make(int $rows, int $max): self
    {
        return new self("El filtro trae {$rows} filas y el máximo es {$max}. Acote el filtro para exportar.");
    }
}
