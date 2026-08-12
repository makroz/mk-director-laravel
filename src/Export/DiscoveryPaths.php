<?php

declare(strict_types=1);

namespace Mk\Director\Export;

/**
 * Dónde busca el motor los `ExportConfig` y los `CustomReport`.
 *
 * 🔴 El original tenía `app_path('Modules')` escrito en el código, dos veces
 * —una por registry—. Eso asume que todo consumer organiza sus módulos igual,
 * y en un paquete distribuido no hay tal cosa: uno usa `app/Modules`, otro
 * `app/Domain`, otro los reparte entre varios directorios.
 *
 * ⚠️ El default no se calcula en el archivo de config sino acá. Un
 * `app_path()` dentro de `config/mk_director.php` se evalúa al mergear la
 * config, que en algunos contextos —tests que arman el container a mano, un
 * comando que corre antes del bootstrap completo— pasa antes de que la ruta
 * base exista. Resolverlo perezosamente evita ese orden frágil.
 */
final class DiscoveryPaths
{
    /**
     * Las carpetas raíz a escanear.
     *
     * @return string[]
     */
    public static function raices(): array
    {
        $configuradas = config('mk_director.export.discovery.paths');

        if (is_string($configuradas) && $configuradas !== '') {
            return [$configuradas];
        }

        if (is_array($configuradas) && $configuradas !== []) {
            return array_values(array_filter($configuradas, 'is_string'));
        }

        return array_filter([self::porDefecto()]);
    }

    /**
     * `app/Modules` de la app, si existe y si hay función para resolverla.
     *
     * ⚠️ Devuelve `null` —y el motor no descubre nada— en vez de reventar
     * cuando no hay app Laravel montada. Es el caso de un test unitario del
     * paquete: pedirle una ruta base a un container pelado tira excepción, y
     * un registry vacío es una respuesta legítima.
     */
    private static function porDefecto(): ?string
    {
        if (! function_exists('app_path')) {
            return null;
        }

        try {
            $ruta = app_path('Modules');
        } catch (\Throwable) {
            return null;
        }

        return is_dir($ruta) ? $ruta : null;
    }
}
