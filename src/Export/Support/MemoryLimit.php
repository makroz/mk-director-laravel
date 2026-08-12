<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

/**
 * Sube el `memory_limit` del proceso — y NUNCA lo baja.
 *
 * Los jobs de export solían hacer `@ini_set('memory_limit', '256M')` a secas.
 * En un worker que arranca en 128M eso es una subida y está bien; el problema
 * es que `ini_set` no distingue: si el proceso YA tenía más, ese mismo
 * `ini_set` lo BAJA, y lo baja para todo lo que venga después.
 *
 * 🔴 Dos lugares donde eso muerde de verdad:
 *
 * 1. **La suite de tests.** El `phpunit.xml` suele levantar el límite a
 *    propósito. Con `QUEUE_CONNECTION=sync` los jobs corren DENTRO del proceso
 *    de la suite, así que el primer test que dispare un export deja la suite
 *    entera clavada en 256M. El test que revienta después no es el culpable:
 *    es el que tuvo la mala suerte de correr más tarde. Con orden aleatorio el
 *    fallo aparece y desaparece, que es lo peor que le puede pasar a un test.
 *
 * 2. **Producción.** Un controller que sube el límite para atender el request
 *    y despacha un job en modo `sync` se encontraba con el límite recortado a
 *    mitad de camino.
 *
 * El valor sigue siendo el piso que cada job necesita; lo único que cambia es
 * que ahora es un PISO y no un techo.
 */
final class MemoryLimit
{
    /**
     * Garantiza al menos `$deseado`, sin bajar lo que ya haya.
     *
     * @param  string  $deseado  Formato de php.ini: '256M', '2G', '512K'.
     * @return bool `true` si efectivamente subió el límite.
     */
    public static function atLeast(string $deseado): bool
    {
        $actual = self::enBytes((string) ini_get('memory_limit'));

        // -1 es "sin límite": cualquier número lo empeora.
        if ($actual === -1) {
            return false;
        }

        $objetivo = self::enBytes($deseado);

        if ($objetivo !== -1 && $objetivo <= $actual) {
            return false;
        }

        @ini_set('memory_limit', $deseado);

        return true;
    }

    /**
     * Convierte un valor de php.ini a bytes. Devuelve -1 para "sin límite".
     */
    public static function enBytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '-1') {
            return -1;
        }

        $numero = (int) $valor;
        $sufijo = strtolower(substr($valor, -1));

        return match ($sufijo) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
