<?php

declare(strict_types=1);

namespace Mk\Director\Export\Jobs;

use Countable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\ExportService;
use Mk\Director\Export\FilasDelExport;
use Mk\Director\Export\Support\MemoryLimit;
use Mk\Director\Models\MkReport;
use Mk\Director\Tenancy\TenantContext;
use Throwable;

/**
 * Arma el archivo de un export de LISTA.
 *
 * El job re-ejecuta el `index()` del controller del módulo. No es un rodeo: es
 * la única forma de que el archivo diga exactamente lo mismo que la pantalla.
 * Rearmar la consulta acá significaría duplicar los filtros, los joins y cada
 * hook del módulo, y esas dos copias se separan a la primera corrección que
 * alguien haga en una sola.
 */
class GenerateListExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    /** @var int[] */
    public array $backoff = [60, 300];

    public function __construct(
        public int $reportId,
        public string $controllerClass,
    ) {}

    public function handle(
        ExportConfigRegistry $registry,
        ExportService $exportService,
        FilasDelExport $buzon,
    ): void {
        // ⚠️ Es un PISO, no un techo: ver {@see MemoryLimit}. Un `ini_set` a
        // secas acá le RECORTA la memoria a todo lo que corra después en el
        // mismo proceso — y en modo `sync` eso es el request del usuario.
        MemoryLimit::atLeast((string) config('mk_director.export.job_memory_limit', '2G'));

        $report = MkReport::find($this->reportId);

        if (! $report) {
            Log::error('[mk-director] export: el reporte no existe', ['report_id' => $this->reportId]);

            return;
        }

        try {
            $report->markProcessing();

            $config = $registry->get($report->type);

            if ($config === null) {
                throw new InvalidArgumentException("ExportConfig '{$report->type}' no registrado.");
            }

            // ⚠️ El buzón se VACÍA antes de llamar: el worker atiende un job
            // tras otro en el mismo proceso, y lo que quedó de uno no puede
            // aparecerse en el siguiente.
            $buzon->vaciar();

            $this->reEjecutarElControlador($report);

            $binary = $buzon->hayFilas()
                ? $this->armarElArchivo($report, $config, $exportService, $buzon)
                : $this->archivoSinFilas($report, $config, $exportService);

            // Se suelta la referencia apenas se escribió el archivo: el worker
            // sigue vivo y estas filas pueden ser cientos de miles.
            $buzon->vaciar();

            $this->guardar($report, $binary);
        } catch (Throwable $e) {
            Log::error('[mk-director] export: falló', [
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
     * Corre el `index()` del controller para que deje las filas en el buzón.
     */
    private function reEjecutarElControlador(MkReport $report): void
    {
        $controller = app($this->controllerClass);
        $request = $this->armarElRequest($report);

        $request->merge([
            '_export' => $report->format,
            // 🔴 El corte de re-entrada. Sin esto la llamada de abajo vuelve a
            // caer en el manager y crea OTRO reporte que encola OTRO job.
            // Ver {@see AsyncExportManager::MARCA_DE_REENTRADA}.
            AsyncExportManager::MARCA_DE_REENTRADA => $report->id,
        ]);

        $this->comoElDuenoDelReporte($report, $request, fn () => $controller->index($request));
    }

    /**
     * @param  FilasDelExport  $buzon  ya verificado con `hayFilas()`
     */
    private function armarElArchivo(
        MkReport $report,
        ExportConfigInterface $config,
        ExportService $exportService,
        FilasDelExport $buzon,
    ): string {
        $filas = $this->soloLasFilas($buzon->filas());

        $this->anotarElTotalDeSegmentos($report, $filas, $config);

        $data = $config->beforeExport($filas, $report);

        return match ($report->format) {
            'pdf' => $exportService->renderPdfFromConfig($data, $config, $report),
            'xlsx' => $exportService->renderXlsxFromConfig($data, $config, $report),
            'csv' => $exportService->renderCsvFromConfig($data, $config, $report),
            default => throw new InvalidArgumentException("Formato no soportado: {$report->format}"),
        };
    }

    /**
     * El archivo cuando el controller NO dejó filas.
     *
     * ⚠️ Esto NO es "la lista salió vacía" — esa llega igual, vacía, por el
     * buzón. Esto es que el controller no pasó por `ExportaListados`: o no usa
     * el trait, o su `index()` cortó antes.
     *
     * 🔴 El original no distinguía los dos casos y por eso podía marcar
     * `completed` un reporte que nunca tuvo datos: un PDF con encabezado y
     * nada debajo, que dice que salió bien. Acá sale el mismo archivo pero
     * queda el aviso en el log con el controller que lo causó.
     */
    private function archivoSinFilas(
        MkReport $report,
        ExportConfigInterface $config,
        ExportService $exportService,
    ): string {
        Log::warning('[mk-director] export: el controller no dejó filas', [
            'report_id' => $this->reportId,
            'controller' => $this->controllerClass,
            'ayuda' => 'El controller tiene que usar el trait ExportaListados en su index().',
        ]);

        return $exportService->renderPdfFromConfig([], $config, $report);
    }

    private function guardar(MkReport $report, string $binary): void
    {
        $disk = (string) config('mk_director.export.disk', 'local');
        $dir = trim((string) config('mk_director.export.directory', 'reports'), '/');
        $path = "{$dir}/{$report->uuid}.{$report->format}";

        Storage::disk($disk)->put($path, $binary);

        $report->markCompleted($path);

        Log::info('[mk-director] export: listo', [
            'report_id' => $this->reportId,
            'file_path' => $path,
            'size_bytes' => strlen($binary),
        ]);
    }

    /**
     * Un request armado a mano con los filtros guardados en el reporte.
     */
    private function armarElRequest(MkReport $report): Request
    {
        $params = is_array($report->params) ? $report->params : [];
        $uri = '/api/'.trim((string) config('mk_director.export.route_prefix', 'v3/reports'), '/').'/'.$report->type;

        $request = Request::create($uri, 'GET', $params);
        $request->setUserResolver(fn () => $this->usuarioDelReporte($report));

        // 🔴 Un request creado a mano NO TIENE RUTA, y el flujo de listado la
        // usa: cualquier gestor de estado de lista que arme su clave con
        // `request()->route()->getAction()` explota con "Call to a member
        // function getAction() on null" y el reporte muere.
        //
        // ⚠️ Le pasa a cualquier módulo que liste por el camino genérico. Un
        // módulo que arme su consulta con un service propio NO lo destapa —
        // que es justo el que se suele probar primero.
        $ruta = new Route('GET', ltrim($uri, '/'), [
            'controller' => $this->controllerClass.'@index',
        ]);
        $ruta->bind($request);
        $request->setRouteResolver(fn () => $ruta);

        return $request;
    }

    /**
     * Corre el flujo del controller con la identidad del que pidió el reporte,
     * y deja todo como estaba al salir.
     *
     * 🔴 Sin esto el job explota o —peor— exporta de más:
     *
     * - Los controllers asumen que hay alguien logueado. `setUserResolver()`
     *   sólo alcanza para `$request->user()`; un `Auth::user()` va por el
     *   guard, que en un worker no tiene a nadie, y devuelve `null`.
     * - El contexto de tenant se lee del container, no del request que el
     *   controller recibe por parámetro. Sin sembrarlo, un usuario de DOS
     *   tenants exporta el que salga primero, no el del reporte.
     *
     * ⚠️ El worker es un proceso largo: TODO lo que se toca se restaura, o el
     * próximo job arranca con la identidad del anterior. El `finally` no es
     * prolijidad — es lo que evita que un reporte salga con los datos de otro.
     */
    private function comoElDuenoDelReporte(MkReport $report, Request $request, callable $flujo): void
    {
        $usuario = $this->usuarioDelReporte($report);
        $tenant = app(TenantContext::class);

        $requestPrevio = app()->bound('request') ? app('request') : null;
        $usuarioPrevio = Auth::user();
        $tenantPrevio = $tenant->current();

        try {
            app()->instance('request', $request);

            if ($usuario !== null) {
                Auth::setUser($usuario);
            }

            if ($report->tenant_id !== null) {
                $tenant->set($report->tenant_id);
            }

            $flujo();
        } finally {
            Auth::forgetGuards();

            if ($usuarioPrevio !== null) {
                Auth::setUser($usuarioPrevio);
            }

            $tenantPrevio !== null ? $tenant->set($tenantPrevio) : $tenant->flush();

            if ($requestPrevio !== null) {
                app()->instance('request', $requestPrevio);
            } else {
                app()->forgetInstance('request');
            }
        }
    }

    private function usuarioDelReporte(MkReport $report): mixed
    {
        $modelo = MkReport::userModel();

        return $modelo !== null && class_exists($modelo) ? $modelo::find($report->user_id) : null;
    }

    /**
     * Devuelve la lista de filas, venga suelta o envuelta.
     *
     * 🔴 Cuando el `ExportConfig` pide `useExtraData()`, el `index()` no
     * devuelve la lista: devuelve `{data: [...filas...], __extraData: [...]}`.
     * Iterar ese diccionario recorre UN solo elemento —el diccionario entero—
     * y el reporte sale **sin filas**, sin ningún error: un archivo con
     * encabezado y nada debajo.
     *
     * ⚠️ `is_iterable` en el valor de `data`, no `is_array`. Mientras las filas
     * viajaban serializadas llegaban como array de arrays; desde que el job las
     * recibe en memoria son una `Collection`, y un guard que pedía array dejaba
     * de dispararse — el diccionario entero pasaba como si fuera UNA fila. Un
     * módulo reventó con un `TypeError`, que es la forma AFORTUNADA de fallar:
     * uno más permisivo habría impreso una fila de basura sin decir nada.
     */
    private function soloLasFilas(mixed $data): iterable
    {
        if (is_array($data) && isset($data['data']) && is_iterable($data['data'])) {
            return $data['data'];
        }

        return is_iterable($data) ? $data : [];
    }

    /**
     * Deja escrito de cuántos segmentos va a ser el reporte.
     *
     * 🔴 Es lo único que le faltaba a la barra de progreso: `current_chunk` ya
     * se venía actualizando, pero el TOTAL nunca se escribía porque el job
     * llamaba a `markProcessing()` sin argumento. Medido: **82 de 83** reportes
     * completados sin total, o sea con la barra inservible.
     *
     * ⚠️ Se cuenta ANTES de `beforeExport` porque ése puede devolver un
     * GENERATOR y contarlo lo consumiría. Como también puede DESCARTAR filas,
     * el total es una cota superior: la barra puede saltar al 100% al final en
     * vez de llegar caminando. Preferible a no tener barra.
     *
     * ⚠️ Si las filas no se pueden contar sin consumirlas no se escribe nada y
     * el front cae al spinner. Un total inventado sería peor: la barra
     * avanzaría mintiendo.
     */
    private function anotarElTotalDeSegmentos(MkReport $report, mixed $filas, ExportConfigInterface $config): void
    {
        if (! is_array($filas) && ! $filas instanceof Countable) {
            return;
        }

        $porSegmento = max(1, $config->chunkSize((string) $report->format));

        $report->update([
            'total_chunks' => max(1, (int) ceil(count($filas) / $porSegmento)),
        ]);
    }
}
