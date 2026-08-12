<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Mk\Director\Export\Contracts\CustomReportInterface;
use Mk\Director\Export\Discovery\ClassDiscovery;

/**
 * Los `CustomReport` del proyecto, indexados por `key()`.
 *
 * Se auto-descubren buscando la carpeta `export.discovery.custom_dir`
 * —`CustomReports` por defecto— dentro de `export.discovery.paths`.
 *
 * ⚠️ Se indexan por `key()` y NO por `module()`: un módulo puede tener varios
 * customs y todos comparten módulo. `module()` sólo dice en qué menú de
 * exportación aparece cada uno.
 */
final class CustomReportRegistry
{
    /** @var array<string, CustomReportInterface> */
    private array $reportes = [];

    private bool $cargado = false;

    /** @return array<string, CustomReportInterface> */
    public function all(): array
    {
        $this->cargar();

        return $this->reportes;
    }

    public function get(string $key): ?CustomReportInterface
    {
        $this->cargar();

        return $this->reportes[$key] ?? null;
    }

    public function has(string $key): bool
    {
        $this->cargar();

        return isset($this->reportes[$key]);
    }

    /** @return string[] */
    public function availableModules(): array
    {
        $this->cargar();

        return array_keys($this->reportes);
    }

    /**
     * Los customs de un módulo. Es lo que alimenta el menú de exportación del
     * front: además de PDF/XLSX/CSV, el módulo puede ofrecer reportes propios.
     *
     * @return CustomReportInterface[]
     */
    public function forModule(string $module): array
    {
        $this->cargar();

        return array_values(array_filter(
            $this->reportes,
            fn (CustomReportInterface $r) => $r->module() === $module
        ));
    }

    public function register(CustomReportInterface $reporte): void
    {
        $this->cargar();
        $this->reportes[$reporte->key()] = $reporte;
    }

    private function cargar(): void
    {
        if ($this->cargado) {
            return;
        }

        $this->cargado = true;

        $clases = ClassDiscovery::implementando(
            CustomReportInterface::class,
            DiscoveryPaths::raices(),
            (string) config('mk_director.export.discovery.custom_dir', 'CustomReports'),
        );

        foreach ($clases as $clase) {
            /** @var CustomReportInterface $instancia */
            $instancia = app($clase);
            $this->reportes[$instancia->key()] = $instancia;
        }
    }
}
