<?php

declare(strict_types=1);

namespace Mk\Director\Export\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\ExportConfigRegistry;

/**
 * El enganche del export en el listado de un controller.
 *
 * `CRUDSmart` ya lo usa, así que cualquier `SmartController` exporta sin
 * escribir una línea. Un controller con `index()` propio lo suma con una:
 *
 * ```php
 * class BankAccountController extends BaseController
 * {
 *     use ExportaListados;
 *
 *     public function index(Request $request): JsonResponse
 *     {
 *         $filas = $this->service->listar($filtros, $busqueda);
 *
 *         if ($export = $this->exportarListado($request, $filas)) {
 *             return $export;
 *         }
 *
 *         return $this->sendResponse(...);
 *     }
 *
 *     protected function resolveReportType(): string { return 'bank-accounts'; }
 * }
 * ```
 *
 * 🔴 **Por qué es un trait y no vive adentro del `index()` genérico.** Ahí sólo
 * lo tendrían los módulos que usan ese `index()`. Los que escriben el suyo
 * quedaban afuera SIN NINGÚN AVISO: el botón de exportar seguía andando y el
 * archivo salía por el camino viejo. Peor todavía si el módulo registraba un
 * `ExportConfig` sin pasar por acá — el job re-ejecutaba el controller
 * esperando las filas, no las encontraba, y producía **un archivo con
 * encabezado, ninguna fila, y marcado `completed`**. Un reporte vacío que dice
 * que salió bien.
 *
 * 🔴 **Se llama con las filas YA RESUELTAS** —después de filtros, búsqueda,
 * orden y los hooks del módulo— porque lo que se exporta tiene que ser
 * exactamente lo que el usuario ve en pantalla. Ése es el punto de re-ejecutar
 * el controller en vez de rearmar la consulta.
 */
trait ExportaListados
{
    /**
     * La respuesta 202 del export, o `null` si este request no pide export o
     * el módulo no está registrado.
     *
     * El `null` es a propósito: deja que el llamador decida el fallback.
     */
    protected function exportarListado(Request $request, mixed $data): ?JsonResponse
    {
        if (empty($request->input('_export'))) {
            return null;
        }

        $type = $this->resolveReportType();
        $manager = app(AsyncExportManager::class);

        if (! $manager->isMigrated($type)) {
            return null;
        }

        $config = app(ExportConfigRegistry::class)->get($type);

        if ($config !== null && $config->useExtraData()) {
            $data = $this->conElExtraDataDelModulo($request, $data);
        }

        return $manager->export($request, $data, $type, static::class);
    }

    /**
     * Envuelve las filas junto al `extraData` del módulo, para los configs que
     * lo declaran.
     *
     * 🔴 El `_export => ''` del merge CORTA LA RECURSIÓN. Sin eso, la llamada
     * de abajo vuelve a entrar en `exportarListado()` y encola OTRO export, que
     * al correr encola otro: es la cadena de jobs que dejó 1648 reportes
     * huérfanos en una base local.
     *
     * 🔴 Y el request se restaura ENTERO al salir, no sólo `_export`. El merge
     * lo deja en modo estadísticas, y ésos son los params que el manager guarda
     * en el reporte: con el modo equivocado el controller devuelve el resumen
     * en vez del listado, y un PDF que tenía que tener 200 páginas sale con
     * tres filas.
     */
    private function conElExtraDataDelModulo(Request $request, mixed $data): array
    {
        $paramsDelPedido = $request->all();

        $request->merge([
            'fullType' => 'EXTRA',
            'extraData' => 'false',
            '__internal' => 'true',
            '_export' => '',
        ]);

        $extra = $this->index($request);

        $request->replace($paramsDelPedido);

        return ['data' => $data, '__extraData' => $extra];
    }

    /**
     * La clave del módulo en el registry.
     *
     * 🔴 Tiene que coincidir EXACTA con el `module()` del `ExportConfig`. Si se
     * separan, `isMigrated()` da false, esto devuelve `null` y el export se va
     * por donde iba antes — sin error y sin diferencia visible en el botón.
     * Cada módulo migrado debería pinearlo en su test.
     */
    protected function resolveReportType(): string
    {
        if (property_exists($this, 'mkConfig') && ! empty($this->mkConfig['exportType'])) {
            return (string) $this->mkConfig['exportType'];
        }

        $modelo = property_exists($this, 'mkConfig')
            ? ($this->mkConfig['model'] ?? null)
            : ($this->modelClass ?? null);

        return $modelo !== null ? strtolower((new $modelo)->getTable()) : '';
    }

    /**
     * Las claves que este controller resuelve, cuando son MÁS DE UNA.
     *
     * 🔴 Existe porque el supuesto de "un controller, una clave" se rompe: hay
     * endpoints que sirven varias pantallas según los params, y cada pantalla
     * necesita sus propias columnas — o sea su propia clave.
     *
     * ⚠️ Un test que construya los controllers con un request VACÍO sólo ve la
     * rama por defecto. Sin esta declaración daría por huérfanas a las claves
     * condicionales.
     *
     * @return string[] vacío = este controller resuelve una sola clave.
     */
    public static function clavesDeExportQueResuelve(): array
    {
        return [];
    }
}
