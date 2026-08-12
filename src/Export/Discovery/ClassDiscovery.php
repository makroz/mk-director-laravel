<?php

declare(strict_types=1);

namespace Mk\Director\Export\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Encuentra las clases de un tipo dado dentro de las carpetas configuradas.
 *
 * La usan los dos registries. En el motor original la lógica de escaneo estaba
 * DUPLICADA entera entre los dos —mismo bucle, mismo cálculo de FQCN, mismos
 * bugs— y arreglar uno dejaba el otro roto.
 *
 * ## 🔴 El FQCN sale del `namespace` DEL ARCHIVO, no de la ruta
 *
 * El original lo derivaba haciendo cuentas con la ruta: quitarle `app_path()`,
 * cambiar las barras por backslashes, pegarle `App\` adelante. Eso arrastró
 * dos bugs, y los dos fallaban en SILENCIO — el registry quedaba vacío,
 * `isMigrated()` daba false y el export se iba por otro camino sin un solo
 * error visible:
 *
 * 1. `app_path()` no termina en barra, así que el resultado empezaba con `\` y
 *    el FQCN quedaba con backslash doble. `class_exists()` false para todo.
 * 2. `rtrim($class, '.php')` para sacar la extensión. `rtrim` con lista de
 *    caracteres NO saca un sufijo: saca cualquier `.`, `p` o `h` del final. Una
 *    clase llamada `Graph` se convertía en `Gra`. Nunca se disparó porque
 *    ninguna clase terminaba en esas letras — es una mina esperando el nombre
 *    equivocado.
 *
 * Leer el `namespace` que el archivo ya declara no requiere ninguna de esas
 * cuentas, funciona con cualquier layout de PSR-4 y no depende de que el
 * consumer organice sus módulos como los organiza otro proyecto.
 */
final class ClassDiscovery
{
    /**
     * Los FQCN de las clases que implementan `$contrato`, buscando carpetas
     * llamadas `$subcarpeta` dentro de `$raices`.
     *
     * @param  string[]  $raices
     * @return string[]
     */
    public static function implementando(string $contrato, array $raices, string $subcarpeta): array
    {
        $encontradas = [];

        foreach ($raices as $raiz) {
            if (! is_string($raiz) || ! is_dir($raiz)) {
                continue;
            }

            foreach (self::archivosEn($raiz, $subcarpeta) as $archivo) {
                $clase = self::fqcnDe($archivo);

                if ($clase === null || ! class_exists($clase)) {
                    continue;
                }

                if (! is_subclass_of($clase, $contrato)) {
                    continue;
                }

                $encontradas[] = $clase;
            }
        }

        return array_values(array_unique($encontradas));
    }

    /**
     * Los `.php` de cada carpeta `$subcarpeta` bajo `$raiz`.
     *
     * ⚠️ Busca en cualquier nivel, no sólo un módulo abajo: un consumer puede
     * anidar sus módulos (`Modules/Finanzas/Pagos/Export/`) y el original,
     * que miraba exactamente un nivel, no lo habría visto.
     *
     * @return iterable<SplFileInfo>
     */
    private static function archivosEn(string $raiz, string $subcarpeta): iterable
    {
        // Iteradores nativos y no `symfony/finder`: es una dependencia más en
        // un paquete que ya suma mPDF y PhpSpreadsheet, y acá no aporta nada
        // que `RecursiveDirectoryIterator` no haga.
        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $marca = DIRECTORY_SEPARATOR.$subcarpeta.DIRECTORY_SEPARATOR;

        foreach ($iterador as $archivo) {
            if (! $archivo instanceof SplFileInfo || $archivo->getExtension() !== 'php') {
                continue;
            }

            if (! str_contains($archivo->getPathname(), $marca)) {
                continue;
            }

            yield $archivo;
        }
    }

    /**
     * El FQCN declarado dentro del archivo.
     *
     * ⚠️ `getBasename('.php')` y no `rtrim`: saca el sufijo exacto. Ver el
     * docblock de la clase para el bug que eso reemplaza.
     */
    private static function fqcnDe(SplFileInfo $archivo): ?string
    {
        $fuente = @file_get_contents($archivo->getPathname());

        if ($fuente === false) {
            return null;
        }

        if (preg_match('/^\s*namespace\s+([^;{\s]+)\s*[;{]/m', $fuente, $m) !== 1) {
            return null;
        }

        return trim($m[1], '\\').'\\'.$archivo->getBasename('.php');
    }
}
