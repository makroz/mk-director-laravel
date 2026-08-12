<?php

declare(strict_types=1);

namespace Mk\Director\Export\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mk\Director\Export\CustomReportRegistry;
use Mk\Director\Export\Support\MemoryLimit;
use Mk\Director\Models\MkReport;
use Mk\Director\Tenancy\TenantContext;
use Throwable;

/**
 * Arma el archivo de un reporte CUSTOM.
 *
 * Más simple que su hermano: acá no hay controller que re-ejecutar. El custom
 * arma su propia consulta y devuelve los bytes.
 */
class GenerateCustomReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    /** @var int[] */
    public array $backoff = [60, 300];

    public function __construct(
        public int $reportId,
    ) {}

    public function handle(CustomReportRegistry $registry): void
    {
        // 🔴 El MISMO piso que el job de listas, y no es simetría decorativa:
        // el custom arma su planilla entera en memoria con PhpSpreadsheet, o
        // sea que corre el mismo riesgo por el mismo camino. En el original
        // este job estaba en 256M mientras el otro ya estaba en 2G — ese 2G no
        // fue precaución, se pagó con un OOM real de 400 páginas. Dos jobs del
        // mismo motor no pueden tener dos techos.
        MemoryLimit::atLeast((string) config('mk_director.export.job_memory_limit', '2G'));

        $report = MkReport::find($this->reportId);

        if (! $report) {
            Log::error('[mk-director] custom: el reporte no existe', ['report_id' => $this->reportId]);

            return;
        }

        try {
            $report->markProcessing();

            $custom = $registry->get($report->type);

            if ($custom === null) {
                throw new InvalidArgumentException("CustomReport '{$report->type}' no registrado.");
            }

            if (! in_array($report->format, $custom->supportedFormats(), true)) {
                throw new InvalidArgumentException(
                    "El reporte '{$custom->key()}' no genera '{$report->format}'. "
                    .'Soporta: '.implode(', ', $custom->supportedFormats()).'.'
                );
            }

            $binary = $this->comoElDuenoDelReporte(
                $report,
                fn (): string => $custom->render($report, (string) $report->format),
            );

            $disk = (string) config('mk_director.export.disk', 'local');
            $dir = trim((string) config('mk_director.export.directory', 'reports'), '/');
            $path = "{$dir}/{$report->uuid}.{$report->format}";

            Storage::disk($disk)->put($path, $binary);

            $report->markCompleted($path);

            Log::info('[mk-director] custom: listo', [
                'report_id' => $this->reportId,
                'file_path' => $path,
                'size_bytes' => strlen($binary),
            ]);
        } catch (Throwable $e) {
            Log::error('[mk-director] custom: falló', [
                'report_id' => $this->reportId,
                'error' => $e->getMessage(),
            ]);

            $report->markFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        MkReport::find($this->reportId)?->markFailed('Job failed: '.$exception->getMessage());
    }

    /**
     * Corre el custom con la identidad del que pidió el reporte, y deja todo
     * como estaba.
     *
     * ⚠️ Un custom NO debería leer `auth()` ni el contexto del request —su
     * contrato dice que todo viaja en `$report`—, pero sembrar la identidad
     * igual es barato y evita que un custom que se olvide de la regla exporte
     * los datos del tenant equivocado en vez de fallar.
     */
    private function comoElDuenoDelReporte(MkReport $report, callable $flujo): string
    {
        $modelo = MkReport::userModel();
        $usuario = $modelo !== null && class_exists($modelo) ? $modelo::find($report->user_id) : null;
        $tenant = app(TenantContext::class);

        $usuarioPrevio = Auth::user();
        $tenantPrevio = $tenant->current();

        try {
            if ($usuario !== null) {
                Auth::setUser($usuario);
            }

            if ($report->tenant_id !== null) {
                $tenant->set($report->tenant_id);
            }

            return $flujo();
        } finally {
            Auth::forgetGuards();

            if ($usuarioPrevio !== null) {
                Auth::setUser($usuarioPrevio);
            }

            $tenantPrevio !== null ? $tenant->set($tenantPrevio) : $tenant->flush();
        }
    }
}
