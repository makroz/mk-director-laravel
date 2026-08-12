<?php

declare(strict_types=1);

namespace Mk\Director\Export;

use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\Discovery\ClassDiscovery;

/**
 * Los `ExportConfig` del proyecto, indexados por `module()`.
 *
 * Se auto-descubren buscando la carpeta `export.discovery.config_dir`
 * —`Export` por defecto— dentro de `export.discovery.paths`.
 *
 * ⚠️ La carga es perezosa y ocurre UNA vez por proceso: escanear disco en cada
 * request de listado sería absurdo, y el registry se consulta en TODOS.
 */
final class ExportConfigRegistry
{
    /** @var array<string, ExportConfigInterface> */
    private array $configs = [];

    private bool $cargado = false;

    /** @return array<string, ExportConfigInterface> */
    public function all(): array
    {
        $this->cargar();

        return $this->configs;
    }

    public function get(string $module): ?ExportConfigInterface
    {
        $this->cargar();

        return $this->configs[$module] ?? null;
    }

    public function has(string $module): bool
    {
        $this->cargar();

        return isset($this->configs[$module]);
    }

    /** @return string[] */
    public function availableModules(): array
    {
        $this->cargar();

        return array_keys($this->configs);
    }

    /**
     * Registra un config a mano.
     *
     * Existe para los tests y para el consumer que prefiera declararlos
     * explícitamente en vez de que se los descubran.
     */
    public function register(ExportConfigInterface $config): void
    {
        $this->cargar();
        $this->configs[$config->module()] = $config;
    }

    private function cargar(): void
    {
        if ($this->cargado) {
            return;
        }

        $this->cargado = true;

        $clases = ClassDiscovery::implementando(
            ExportConfigInterface::class,
            DiscoveryPaths::raices(),
            (string) config('mk_director.export.discovery.config_dir', 'Export'),
        );

        foreach ($clases as $clase) {
            /** @var ExportConfigInterface $instancia */
            $instancia = app($clase);
            $this->configs[$instancia->module()] = $instancia;
        }
    }
}
