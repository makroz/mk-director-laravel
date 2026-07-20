<?php

declare(strict_types=1);

namespace Mk\Director\Utils;

/**
 * MkRequestAwareStorageUrl — arma la URL pública del storage desde el host de
 * la request entrante, EN DESARROLLO.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * `config/filesystems.php` arma la url del disk `public` como
 * `APP_URL.'/storage'`. Ese valor es UNO SOLO, pero en desarrollo la URL
 * correcta depende de QUIÉN pregunta:
 *
 *   - el navegador en la misma máquina llega por `127.0.0.1:8000`,
 *   - el celular con la app llega por la IP de LAN (`192.168.0.3:8000`).
 *
 * Con `APP_URL` fijo, uno de los dos siempre recibe URLs que no puede
 * resolver. Y como la IP de LAN la reparte DHCP, además se rompe sola cada
 * vez que cambia el lease.
 *
 * Caso real (RETO, 2026-07-20): los avatares salían rotos en el panel. El
 * archivo estaba bien, el endpoint devolvía bien el `avatar_url` y el front
 * leía el campo correcto — pero el host de esa URL era una IP vieja
 * (`192.168.0.4`) y el server escuchaba sólo en `127.0.0.1`. Diagnosticarlo
 * llevó recorrer los cuatro eslabones de la cadena.
 *
 * Derivando el host de la request, cada cliente recibe una URL que SÍ puede
 * resolver, y el cambio de IP deja de importar.
 *
 * 🔴 POR QUÉ ES DEV-ONLY POR DISEÑO
 * ---------------------------------
 * Construir URLs a partir del header `Host` es un vector conocido en
 * producción: **host header injection / envenenamiento de caché**. Un
 * atacante manda `Host: evil.com` y la app empieza a emitir URLs apuntando a
 * su servidor — que después terminan en mails, en cachés compartidas o en
 * respuestas de API.
 *
 * Por eso el default NO es "activado", ni "activado si alguien lo configura":
 * es **activado sólo si el entorno es local**. Que se apague en producción no
 * puede depender de que alguien se acuerde de apagarlo.
 *
 * En consola (artisan, colas, scheduler) NO hay request entrante, así que
 * tampoco aplica: ahí el único valor correcto es `APP_URL`.
 */
final class MkRequestAwareStorageUrl
{
    /**
     * ¿Corresponde reescribir la URL del storage?
     *
     * @param  bool  $isLocalEnv  `app()->environment('local')`
     * @param  bool  $runningInConsole  `app()->runningInConsole()`
     * @param  bool|null  $configured  el valor de config; `null` = automático
     */
    public static function shouldApply(bool $isLocalEnv, bool $runningInConsole, ?bool $configured): bool
    {
        // Sin request entrante no hay host que seguir. Vale incluso si alguien
        // lo forzó a `true`: en una cola o un cron, el host de la última
        // request web sería un dato prestado y equivocado.
        if ($runningInConsole) {
            return false;
        }

        if ($configured === false) {
            return false;
        }

        if ($configured === true) {
            return true;
        }

        // Automático: sólo en local. Este es el camino por el que pasa
        // cualquiera que no toque la config, y por eso el default seguro
        // tiene que vivir acá.
        return $isLocalEnv;
    }

    /**
     * `('http://192.168.0.3:8000', 'storage')` → `'http://192.168.0.3:8000/storage'`
     *
     * @param  string  $schemeAndHttpHost  de `$request->getSchemeAndHttpHost()`
     */
    public static function buildUrl(string $schemeAndHttpHost, string $pathPrefix = 'storage'): string
    {
        return rtrim($schemeAndHttpHost, '/').'/'.trim($pathPrefix, '/');
    }
}
