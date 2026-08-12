<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Mk\Director\Export\Dispatchers\CreaElReporte;
use Mk\Director\Export\Jobs\GenerateListExportJob;

/**
 * Crea el reporte y encola el job para un export de LISTA.
 *
 * ⚠️ Exige el FQCN del controller. El job lo necesita para re-ejecutar el
 * flujo del listado y conseguir las filas YA procesadas —con los hooks del
 * módulo aplicados—, que es la única forma de que el archivo diga exactamente
 * lo mismo que la pantalla.
 */
final class InlineExportDispatcher
{
    use CreaElReporte;

    public function __construct(
        private readonly ExportConfigRegistry $registry,
    ) {}

    /**
     * @throws InvalidArgumentException si el módulo no está registrado, si el
     *                                  formato no lo soporta, o si falta el
     *                                  controller.
     */
    public function dispatch(
        string|int $userId,
        string|int|null $tenantId,
        string $type,
        string $format,
        array $params = [],
        ?string $controllerClass = null,
    ): JsonResponse {
        $config = $this->registry->get($type);

        if ($config === null) {
            throw new InvalidArgumentException(
                "ExportConfig '{$type}' no registrado. Disponibles: "
                .implode(', ', $this->registry->availableModules())
            );
        }

        if ($controllerClass === null) {
            throw new InvalidArgumentException(
                'Un export de lista necesita el controller: el job lo re-ejecuta '
                .'para conseguir las filas ya procesadas por los hooks del módulo.'
            );
        }

        $format = $this->normalizeFormat($format);
        $this->exigirFormatoSoportado($format, $config->supportedFormats(), $type);

        $report = $this->crearElReporte($userId, $tenantId, $type, $format, $params);

        GenerateListExportJob::dispatch($report->id, $controllerClass);

        return $this->respuestaAceptada($report);
    }
}
