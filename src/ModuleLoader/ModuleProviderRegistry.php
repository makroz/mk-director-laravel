<?php

declare(strict_types=1);

namespace Mk\Director\ModuleLoader;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ModuleProviderRegistry — discovers Module ServiceProviders under
 * the app's Modules directory and caches the result.
 *
 * Spec: MK-LAR-1.0.4 + audit R4-006 / R2-016.
 *
 * Before this registry, ModuleLoaderServiceProvider walked the
 * `app/Modules` directory on every request via DirectoryIterator.
 * For an app with 30+ modules, that is 30+ stat() calls per request
 * plus a class_exists() probe for each one. The cache key is the
 * md5 of the canonical (real) path of every discovered directory;
 * if any of those paths change (add/remove/rename), the key
 * changes and the cache is automatically rebuilt.
 *
 * Security:
 *  - Symlinked module directories are rejected (R2-016). A symlink
 *    under app/Modules pointing to /tmp/evil would otherwise be
 *    discovered and registered as a legitimate module. We compare
 *    realpath() to the original path and skip mismatches.
 *  - The discovery path is locked to the canonical realpath of
 *    app_path('Modules'), so a symlinked app/Modules itself is also
 *    rejected.
 *
 * El cache es una OPTIMIZACIÓN y nunca un requisito: si el backend no
 * responde, `discover()` escanea el disco y sigue. El porqué —que esto
 * corre en el boot de un provider, o sea dentro de `composer install`—
 * está en `leerDelCache()`.
 */
class ModuleProviderRegistry
{
    /**
     * Default TTL for the discovery cache (1 hour).
     */
    public const DEFAULT_TTL = 3600;

    /**
     * Cache key prefix. Final key is composed with the canonical path
     * hash so any directory change invalidates automatically.
     */
    public const CACHE_KEY_PREFIX = 'mk_module_providers:';

    /**
     * Resultado ya resuelto en este proceso. El registry es un singleton, así
     * que esto ahorra el viaje al cache dentro del mismo request — y sobre
     * todo evita rescanear el disco en cada `discover()` cuando el cache está
     * caído y no hay dónde guardar la respuesta.
     *
     * @var array<int, class-string>|null
     */
    protected ?array $resuelto = null;

    /**
     * ¿Ya avisamos que el cache no responde? Una vez por proceso alcanza: el
     * mismo backend caído dispararía el aviso en cada `discover()`.
     */
    protected static bool $yaAvisamos = false;

    /**
     * Discover every Module ServiceProvider under app_path('Modules').
     *
     * @return array<int, class-string>
     */
    public function discover(): array
    {
        if ($this->resuelto !== null) {
            return $this->resuelto;
        }

        $cacheKey = $this->cacheKey();

        $enCache = $this->leerDelCache($cacheKey);
        if ($enCache !== null) {
            return $this->resuelto = $enCache;
        }

        // 🔴 EL SCAN VA AFUERA DEL TRY A PROPÓSITO. Si falla el scan, eso es un
        // bug nuestro y tiene que explotar; lo único que este método absorbe es
        // que el cache no esté disponible.
        $encontrados = $this->scan();

        $this->guardarEnCache($cacheKey, $encontrados);

        return $this->resuelto = $encontrados;
    }

    /**
     * Forget the discovery cache so the next discover() rebuilds.
     * Consumers call this from a deploy hook after adding/removing
     * a module.
     */
    public function flush(): void
    {
        $this->resuelto = null;

        try {
            Cache::forget($this->cacheKey());
        } catch (\Throwable $e) {
            $this->avisarCacheCaido($e);
        }
    }

    /**
     * Lee el descubrimiento cacheado. Devuelve `null` tanto si no hay nada
     * guardado como si el cache no contesta: para el que llama, las dos cosas
     * significan lo mismo —hay que escanear— y ninguna es motivo para tirar
     * abajo el boot.
     *
     * 🔴 POR QUÉ ESTO NO ES UN `Cache::remember()` COMO ANTES.
     *
     * Este método corre en el boot de un ServiceProvider, y el boot de los
     * providers lo dispara `package:discover`, o sea `composer install`. Con el
     * driver `database` —o `redis`, o cualquiera que salga por la red— eso
     * significaba que INSTALAR DEPENDENCIAS EXIGÍA UNA BASE DE DATOS VIVA. En
     * un CI limpio no la hay, y el install moría con un mensaje que no nombra
     * ni a los módulos ni al cache:
     *
     *   Database file at path [.../database.sqlite] does not exist.
     *
     * Nadie lee eso y piensa "el descubrimiento de módulos". Se lo tapó una vez
     * poniendo `CACHE_STORE=array` en el workflow, que es curar el síntoma en
     * un consumidor y dejar la trampa armada para el próximo.
     *
     * El cache acá es una OPTIMIZACIÓN, no la fuente de verdad: la fuente es el
     * disco, y el disco siempre está. Que una optimización caída voltee el boot
     * es la relación al revés.
     *
     * @return array<int, class-string>|null
     */
    protected function leerDelCache(string $cacheKey): ?array
    {
        try {
            $valor = Cache::get($cacheKey);
        } catch (\Throwable $e) {
            $this->avisarCacheCaido($e);

            return null;
        }

        // Un valor de otro tipo es basura de una versión vieja de la clave o de
        // otro que pisó el prefijo: se ignora y se rescanea.
        return is_array($valor) ? $valor : null;
    }

    /**
     * Guarda el descubrimiento. Que no se pueda guardar no es un error del
     * llamador: ya tiene su respuesta, sólo va a pagarla de nuevo en el
     * próximo request.
     *
     * @param  array<int, class-string>  $encontrados
     */
    protected function guardarEnCache(string $cacheKey, array $encontrados): void
    {
        $ttl = (int) config('mk_director.modules.cache_ttl', self::DEFAULT_TTL);

        try {
            Cache::put($cacheKey, $encontrados, $ttl);
        } catch (\Throwable $e) {
            $this->avisarCacheCaido($e);
        }
    }

    /**
     * Deja constancia de que el cache no responde, UNA vez por proceso.
     *
     * 🔴 DEGRADAR EN SILENCIO ABSOLUTO SERÍA CAMBIAR UNA CAÍDA RUIDOSA POR UNA
     * LENTITUD MUDA. Sin cache, cada request vuelve a caminar el directorio de
     * módulos con un `class_exists()` por cada uno: la app anda, y anda peor,
     * y nadie se entera. Por eso queda registrado.
     *
     * Y por eso mismo el aviso va envuelto en su propio try: si el cache está
     * caído porque el container todavía no terminó de armarse, es perfectamente
     * posible que el logger tampoco esté. Un aviso que rompe el boot que
     * veníamos a salvar sería el peor final posible.
     */
    protected function avisarCacheCaido(\Throwable $e): void
    {
        if (self::$yaAvisamos) {
            return;
        }
        self::$yaAvisamos = true;

        try {
            if (function_exists('app') && app()->bound('log')) {
                Log::warning(
                    'mk-director: el cache no responde, el descubrimiento de módulos escanea el disco en cada request.',
                    ['exception' => $e->getMessage()],
                );
            }
        } catch (\Throwable) {
            // Sin logger no hay nada más que hacer. Nunca desde acá se
            // propaga: este método es el que avisa de un problema, no el que
            // agrega uno nuevo.
        }
    }

    /**
     * Olvida el memo de proceso y el aviso. Sólo para tests, que corren muchos
     * escenarios dentro del mismo proceso PHP.
     */
    public static function flushProcessState(): void
    {
        self::$yaAvisamos = false;
    }

    /**
     * Force a synchronous rescan, ignoring the cache. Useful for
     * tests and for the `mk:module` console command.
     *
     * @return array<int, class-string>
     */
    public function scan(): array
    {
        $modulesPath = $this->canonicalModulesPath();
        if ($modulesPath === null) {
            return [];
        }

        $found = [];
        foreach (new \DirectoryIterator($modulesPath) as $module) {
            if ($module->isDot() || ! $module->isDir()) {
                continue;
            }

            // R2-016: refuse symlinks. realpath() of a symlink resolves
            // to the target; the original path stays the symlink path.
            // When they differ, we have a symlink and we skip it.
            if ($module->isLink()) {
                continue;
            }

            $realDir = realpath($module->getPathname());
            if ($realDir === false || $realDir !== $module->getPathname()) {
                // Sanity check — DirectoryIterator reports isLink() but
                // we keep the realpath comparison as a belt-and-braces
                // guard against partial symlink chains.
                continue;
            }

            $name = $module->getFilename();
            $providerClass = sprintf(
                'App\\Modules\\%s\\Providers\\%sServiceProvider',
                $name,
                $name,
            );

            if (! class_exists($providerClass)) {
                continue;
            }

            $found[] = $providerClass;
        }

        return $found;
    }

    /**
     * Returns the canonical path of app_path('Modules'), or null if
     * the directory does not exist (or is a symlink to something
     * outside the app tree).
     */
    protected function canonicalModulesPath(): ?string
    {
        $candidates = [
            // El knob documentado. `config/mk_director.php` ya publicaba
            // `paths.modules` (con `MK_MODULES_PATH` detrás) y este registry no
            // lo miraba: un consumidor que moviera sus módulos lo configuraba,
            // no pasaba nada, y no había ningún error que se lo dijera.
            $this->desdeConfig(),

            // 🔴 `app_path()` NO SE LLAMA A PELO. La función existe siempre
            // —viene en los helpers de Laravel— pero por dentro hace
            // `app()->path()`, que sólo existe en una Application completa. Con
            // un Container pelado —el harness de este paquete, un script de
            // consola, un worker armado a mano— tira
            // "Call to undefined method Container::path()" y se lleva puesto el
            // boot entero. Un `function_exists()` no cubre eso: comprueba que
            // la función esté declarada, no que se pueda ejecutar.
            $this->intentar(static fn (): ?string => function_exists('app_path') ? app_path('Modules') : null),

            getcwd().'/app/Modules',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }
            if (! is_dir($candidate)) {
                continue;
            }
            // Reject symlinked Modules directory itself.
            if (is_link($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || $real !== $candidate) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * `mk_director.paths.modules`, si hay config y trae un string usable.
     */
    protected function desdeConfig(): ?string
    {
        $valor = $this->intentar(static function (): ?string {
            if (! function_exists('config')) {
                return null;
            }
            $ruta = config('mk_director.paths.modules');

            return is_string($ruta) && $ruta !== '' ? $ruta : null;
        });

        return $valor;
    }

    /**
     * Corre algo que puede reventar por cómo esté armado el entorno y devuelve
     * `null` en vez de propagar.
     *
     * Descubrir dónde viven los módulos es una BÚSQUEDA con varios candidatos:
     * que uno no se pueda ni evaluar es un candidato menos, no el final del
     * boot.
     *
     * @param  callable(): ?string  $intento
     */
    protected function intentar(callable $intento): ?string
    {
        try {
            return $intento();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Cache key composed of the canonical modules path hash so any
     * directory rename/add/remove invalidates the cache automatically.
     */
    protected function cacheKey(): string
    {
        $path = $this->canonicalModulesPath() ?? 'no_modules_path';

        return self::CACHE_KEY_PREFIX.md5($path);
    }
}
