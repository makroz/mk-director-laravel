<?php

declare(strict_types=1);

namespace Mk\Director\Export\Dispatchers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mk\Director\Models\MkReport;

/**
 * Lo que los dos dispatchers hacen igual: validar el formato, crear el
 * reporte y armar la respuesta 202.
 *
 * En el original esto estaba copiado entero entre `InlineExportDispatcher` y
 * `CustomReportDispatcher` —`normalizeFormat`, el `create`, el
 * `buildAcceptedResponse`, los tres idénticos—. Dos copias del mismo `202` se
 * separan en cuanto alguien agregue un campo a una sola, y el front recibe
 * respuestas distintas según por qué camino pidió el reporte.
 */
trait CreaElReporte
{
    /**
     * Los tres formatos canónicos.
     *
     * ⚠️ Un formato desconocido cae en `pdf` en vez de rechazarse acá: la
     * validación real es contra `supportedFormats()` del reporte, que sabe
     * cuáles genera. Rechazar dos veces con criterios distintos da mensajes
     * de error que se contradicen.
     */
    public function normalizeFormat(string $format): string
    {
        return match (strtolower(trim($format))) {
            'excel', 'xls', 'xlsx' => 'xlsx',
            'csv' => 'csv',
            default => 'pdf',
        };
    }

    /**
     * @param  string[]  $soportados
     *
     * @throws InvalidArgumentException
     */
    protected function exigirFormatoSoportado(string $format, array $soportados, string $type): void
    {
        if (in_array($format, $soportados, true)) {
            return;
        }

        throw new InvalidArgumentException(
            "El reporte '{$type}' no genera '{$format}'. Soporta: ".implode(', ', $soportados).'.'
        );
    }

    protected function crearElReporte(
        string|int $userId,
        string|int|null $tenantId,
        string $type,
        string $format,
        array $params,
    ): MkReport {
        return MkReport::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => (string) $userId,
            'tenant_id' => $tenantId !== null ? (string) $tenantId : null,
            'type' => $type,
            'format' => $format,
            'params' => $params,
            'status' => MkReport::STATUS_PENDING,
            'expires_at' => now()->addHours(MkReport::retentionHours()),
        ]);
    }

    /**
     * El 202 que recibe el front: el `uuid` con el que va a hacer polling y la
     * URL del status.
     *
     * ⚠️ `download_url` viene en `null` a propósito y no se omite: el front
     * mira esa clave para saber si ya puede bajar. Omitirla lo obliga a
     * distinguir "no existe" de "existe y es null", que son lo mismo para él y
     * dos ramas distintas de código.
     */
    protected function respuestaAceptada(MkReport $report): JsonResponse
    {
        // `new JsonResponse` y no el helper `response()`: un paquete no
        // debería depender de un helper global para armar una respuesta de una
        // línea. Y de paso la clase queda construible sin medio framework
        // montado, que es lo que hace posible testearla.
        return new JsonResponse([
            'success' => true,
            'job_id' => $report->uuid,
            'status' => $report->status,
            'progress' => $report->progress,
            'status_url' => route('mk.reports.status', ['report' => $report->uuid]),
            'download_url' => $report->getDownloadUrl(),
            'created_at' => $report->created_at?->toIso8601String(),
        ], 202);
    }
}
