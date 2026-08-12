<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Tenancy\TenantContext;

/**
 * El único punto de entrada de todos los exports.
 *
 * Decide por dónde va el pedido:
 *   1. ¿Hay un `CustomReport` con esa clave? → `CustomReportDispatcher`.
 *   2. ¿Hay un `ExportConfig` con ese módulo? → `InlineExportDispatcher`.
 *   3. Ninguno → 400 con la lista de lo que sí existe.
 *
 * 🔴 **SIEMPRE async.** No hay modo síncrono, y no es una preferencia. Un
 * export sync se muere de dos maneras distintas y las dos son invisibles hasta
 * que el listado crece: el request se pasa de `max_execution_time`, o se queda
 * sin memoria armando el archivo. Medido sobre un listado de accesos real
 * —198.004 filas, ~422 MB de JSON— el request ni llegaba a responder.
 * Encolar hace que apretar "Exportar" cueste lo mismo con 20 filas que con
 * 200.000.
 */
final class AsyncExportManager
{
    /**
     * La marca que el job pone en el request al re-ejecutar el controller.
     *
     * 🔴 Es el corte de re-entrada, y sin él el motor se dispara a sí mismo en
     * cadena: el job re-ejecuta el controller, el controller vuelve a caer
     * acá, se crea OTRO reporte que encola OTRO job, que al correr crea otro.
     * Medido corriendo un job a mano: los reportes de un módulo pasaron de 6 a
     * 7 con UNA sola corrida, y en la base local había **1648 reportes
     * huérfanos** de un módulo y 55 de otro — casi todos hijos que nadie pidió.
     *
     * ⚠️ El usuario nunca lo nota: su descarga sale bien, porque sale del
     * PRIMER reporte. Sólo se ve mirando la tabla.
     */
    public const MARCA_DE_REENTRADA = '__snapshot_to_report';

    public function __construct(
        private readonly ExportConfigRegistry $configRegistry,
        private readonly CustomReportRegistry $customRegistry,
        private readonly InlineExportDispatcher $inlineDispatcher,
        private readonly CustomReportDispatcher $customDispatcher,
        private readonly TenantContext $tenantContext,
        private readonly FilasDelExport $buzon,
    ) {}

    /**
     * @param  iterable  $data  Las filas ya procesadas, cuando el job está
     *                          re-ejecutando el controller. En el pedido del
     *                          usuario van vacías: el request no arma la lista.
     * @return JsonResponse 202 con el uuid, o 400 / 401 si algo falta.
     */
    public function export(
        Request $request,
        iterable $data,
        string $type,
        ?string $controllerClass = null,
    ): JsonResponse {
        // Re-entrada desde el job: acá NO se pide un export nuevo. Ver la
        // constante de arriba.
        if (! empty($request->input(self::MARCA_DE_REENTRADA))) {
            return $this->entregarleLasFilasAlJob($data);
        }

        $user = $request->user();

        if (! $user) {
            return new JsonResponse(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $tenantId = $this->tenantContext->current();

        if ($tenantId === null && config('mk_director.tenant.enabled', false)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'No hay tenant en contexto para este export.',
            ], 400);
        }

        $params = $this->paramsDelPedido($request);
        $format = $request->input('_export', 'pdf');

        // El custom se pregunta PRIMERO: su clave es más específica que el
        // módulo, y un custom puede compartir nombre con el listado del que
        // cuelga.
        if ($this->customRegistry->has($type)) {
            return $this->customDispatcher->dispatch(
                userId: $user->getKey(),
                tenantId: $tenantId,
                type: $type,
                format: (string) $format,
                params: $params,
            );
        }

        if ($this->configRegistry->has($type)) {
            return $this->inlineDispatcher->dispatch(
                userId: $user->getKey(),
                tenantId: $tenantId,
                type: $type,
                format: (string) $format,
                params: $params,
                controllerClass: $controllerClass,
            );
        }

        return new JsonResponse([
            'success' => false,
            'message' => "No hay ExportConfig ni CustomReport registrado para '{$type}'.",
            'available_export_configs' => $this->configRegistry->availableModules(),
            'available_custom_reports' => $this->customRegistry->availableModules(),
        ], 400);
    }

    public function isMigrated(string $type): bool
    {
        return $this->configRegistry->has($type) || $this->customRegistry->has($type);
    }

    /**
     * Guarda las filas recién calculadas en el buzón del job.
     *
     * Es el otro lado del corte de re-entrada: el job re-ejecutó el controller
     * y lo que salió de ahí es la lista de ESE reporte, no de uno nuevo.
     */
    private function entregarleLasFilasAlJob(iterable $data): JsonResponse
    {
        $this->buzon->dejar($data);

        // ⚠️ El job no mira esta respuesta —llama al controller sólo para que
        // deje las filas—, pero devolver algo es parte del contrato de
        // `index()`.
        return new JsonResponse(['success' => true, 'filas_entregadas' => true], 200);
    }

    /**
     * Los filtros del pedido, que es lo ÚNICO que se guarda en el reporte.
     *
     * De acá sale todo lo que el job necesita para reconstruir la misma lista.
     * La data no: la recalcula el controller adentro del job.
     *
     * ⚠️ Los dos flags del transporte NO van al reporte. `_export` es lo que
     * pidió el usuario en ESTE request, y la marca de re-entrada es la señal
     * que corta la cadena: guardarlas haría que el job se re-dispachara a sí
     * mismo al reconstruir el request.
     */
    private function paramsDelPedido(Request $request): array
    {
        $params = $request->all();

        unset($params['_export'], $params[self::MARCA_DE_REENTRADA]);

        return $params;
    }
}
