<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Mk\Director\Export\Dispatchers\CreaElReporte;
use Mk\Director\Export\Jobs\GenerateCustomReportJob;

/**
 * Crea el reporte y encola el job para un reporte CUSTOM.
 *
 * ⚠️ A diferencia del de lista, acá no hay controller que re-ejecutar: el
 * custom arma su propia consulta adentro del job. Todo lo que necesita viaja
 * en los params del reporte.
 */
final class CustomReportDispatcher
{
    use CreaElReporte;

    public function __construct(
        private readonly CustomReportRegistry $registry,
    ) {}

    /**
     * @throws InvalidArgumentException si la clave no está registrada o el
     *                                  formato no lo soporta.
     */
    public function dispatch(
        string|int $userId,
        string|int|null $tenantId,
        string $type,
        string $format,
        array $params = [],
    ): JsonResponse {
        $custom = $this->registry->get($type);

        if ($custom === null) {
            throw new InvalidArgumentException(
                "CustomReport '{$type}' no registrado. Disponibles: "
                .implode(', ', $this->registry->availableModules())
            );
        }

        $format = $this->normalizeFormat($format);
        $this->exigirFormatoSoportado($format, $custom->supportedFormats(), $type);

        $report = $this->crearElReporte($userId, $tenantId, $type, $format, $params);

        GenerateCustomReportJob::dispatch($report->id);

        return $this->respuestaAceptada($report);
    }
}
