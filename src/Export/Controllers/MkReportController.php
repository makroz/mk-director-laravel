<?php

declare(strict_types=1);

namespace Mk\Director\Export\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mk\Director\Controllers\BaseController;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\CustomReportRegistry;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Models\MkReport;
use Mk\Director\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * El historial de descargas y el ciclo de vida de un reporte.
 *
 * 🔴 **Todo se direcciona por `uuid`, nunca por el id.** El autoincremental
 * dice cuántos reportes genera el sistema y permite tantear los de otro; el
 * uuid no dice nada y no se adivina.
 */
final class MkReportController extends BaseController
{
    /**
     * "Mis descargas". Sólo las del usuario autenticado.
     *
     * 🔴 El filtro por usuario va SIEMPRE, no según un parámetro. Un historial
     * de reportes contiene los filtros con los que se pidió cada uno, o sea
     * qué estuvo mirando cada persona.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MkReport::query()
            ->where('user_id', (string) $request->user()?->getKey())
            ->latest('id');

        if ($tipo = $request->input('type')) {
            $query->where('type', $tipo);
        }

        if ($estado = $request->input('status')) {
            $query->where('status', $estado);
        }

        $reportes = $query->limit((int) $request->input('limit', 50))->get();

        return $this->sendResponse(
            $reportes->map(fn (MkReport $r) => $this->comoLoVeElFront($r))->all()
        );
    }

    /**
     * Encola un export. Devuelve 202 con el uuid para hacer polling.
     */
    public function store(Request $request, string $type): JsonResponse
    {
        try {
            return app(AsyncExportManager::class)->export($request, [], $type);
        } catch (InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 400);
        }
    }

    /**
     * El estado, para la barra de progreso.
     */
    public function status(Request $request, string $report): JsonResponse
    {
        $modelo = $this->suyoOFalla($request, $report);

        if ($modelo instanceof JsonResponse) {
            return $modelo;
        }

        return $this->sendResponse($this->comoLoVeElFront($modelo));
    }

    /**
     * Baja el archivo.
     */
    public function download(Request $request, string $report): StreamedResponse|JsonResponse
    {
        $modelo = $this->suyoOFalla($request, $report);

        if ($modelo instanceof JsonResponse) {
            return $modelo;
        }

        if ($modelo->status !== MkReport::STATUS_COMPLETED || ! $modelo->file_path) {
            return $this->sendError('El reporte todavía no está listo.', [], 409);
        }

        $disk = Storage::disk((string) config('mk_director.export.disk', 'local'));

        // ⚠️ El archivo puede no estar aunque la fila diga `completed`: el
        // limpiador borra por `expires_at` y una descarga vieja llega después.
        // Un 404 con mensaje es mejor que una excepción de storage.
        if (! $disk->exists($modelo->file_path)) {
            return $this->sendError('El archivo expiró y ya no está disponible.', [], 404);
        }

        return $disk->download($modelo->file_path, $this->nombreDelArchivo($modelo));
    }

    /**
     * "Limpiar historial".
     *
     * ⚠️ Borra SÓLO los que están en estado terminal. Un reporte en
     * `processing` tiene un job corriendo detrás: borrar la fila deja al worker
     * escribiendo sobre un registro que ya no existe.
     */
    public function destroy(Request $request): JsonResponse
    {
        $query = MkReport::query()
            ->where('user_id', (string) $request->user()?->getKey())
            ->whereIn('status', MkReport::TERMINAL_STATUSES);

        if ($tipo = $request->input('type')) {
            $query->where('type', $tipo);
        }

        $disk = Storage::disk((string) config('mk_director.export.disk', 'local'));
        $borrados = 0;

        foreach ($query->cursor() as $reporte) {
            if ($reporte->file_path && $disk->exists($reporte->file_path)) {
                $disk->delete($reporte->file_path);
            }

            $reporte->delete();
            $borrados++;
        }

        return $this->sendResponse(['deleted' => $borrados], 'Historial limpiado');
    }

    /**
     * Los módulos que exportan. Alimenta el menú del front.
     */
    public function types(): JsonResponse
    {
        return $this->sendResponse([
            'export_configs' => app(ExportConfigRegistry::class)->availableModules(),
            'custom_reports' => app(CustomReportRegistry::class)->availableModules(),
        ]);
    }

    /**
     * Los reportes custom de un módulo, con sus formatos. Es lo que el front
     * cuelga del menú de exportación además de PDF/XLSX/CSV.
     */
    public function customReports(Request $request): JsonResponse
    {
        $module = (string) $request->input('module', '');

        $customs = $module !== ''
            ? app(CustomReportRegistry::class)->forModule($module)
            : app(CustomReportRegistry::class)->all();

        return $this->sendResponse(array_map(fn ($c) => [
            'key' => $c->key(),
            'module' => $c->module(),
            'title' => $c->title(),
            'formats' => $c->supportedFormats(),
        ], array_values($customs)));
    }

    /**
     * El reporte, si es de quien lo pide.
     *
     * 🔴 Se valida el DUEÑO, no sólo el tenant. Dos administradores del mismo
     * condominio no tienen por qué ver los reportes del otro: los params
     * guardados dicen qué estuvo mirando cada uno.
     *
     * ⚠️ Devuelve 404 y no 403 cuando el reporte es ajeno. Un 403 confirma que
     * ese uuid existe.
     */
    private function suyoOFalla(Request $request, string $uuid): MkReport|JsonResponse
    {
        $reporte = MkReport::query()->where('uuid', $uuid)->first();

        if (! $reporte) {
            return $this->sendError('Reporte no encontrado.', [], 404);
        }

        if ((string) $reporte->user_id !== (string) $request->user()?->getKey()) {
            return $this->sendError('Reporte no encontrado.', [], 404);
        }

        $tenantActual = app(TenantContext::class)->current();

        if ($tenantActual !== null && $reporte->tenant_id !== null
            && (string) $reporte->tenant_id !== (string) $tenantActual) {
            return $this->sendError('Reporte no encontrado.', [], 404);
        }

        return $reporte;
    }

    /** @return array<string, mixed> */
    private function comoLoVeElFront(MkReport $report): array
    {
        return [
            'job_id' => $report->uuid,
            'type' => $report->type,
            'format' => $report->format,
            'status' => $report->status,
            'progress' => $report->progress,
            'current_chunk' => $report->current_chunk,
            'total_chunks' => $report->total_chunks,
            'error_message' => $report->error_message,
            'download_url' => $report->getDownloadUrl(),
            'expires_at' => $report->expires_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    private function nombreDelArchivo(MkReport $report): string
    {
        $fecha = $report->created_at?->format('Y-m-d') ?? date('Y-m-d');

        return "{$report->type}-{$fecha}.{$report->format}";
    }
}
