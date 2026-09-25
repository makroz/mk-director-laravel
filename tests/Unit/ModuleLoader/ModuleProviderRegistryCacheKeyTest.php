<?php

declare(strict_types=1);

namespace App\Modules\Alpha\Providers {
    class AlphaServiceProvider {}
}

namespace App\Modules\Beta\Providers {
    class BetaServiceProvider {}
}

namespace {
    use Mk\Director\ModuleLoader\ModuleProviderRegistry;
    use Mk\Director\Tests\MkLaravelTestCase;

    /**
     * HALLAZGO 32: UN MÓDULO NUEVO ES INVISIBLE HASTA UNA HORA.
     *
     * `ModuleProviderRegistry::cacheKey()` hashea el directorio PADRE
     * (`app/Modules`), que no cambia nunca. Con un TTL de 3600 s, agregar un
     * módulo no invalidaba nada: `php artisan migrate` decía «nothing to
     * migrate», el endpoint daba 404, la Policy no se registraba — y ningún
     * error nombraba al caché.
     *
     * 🔴 Y EL DOCBLOCK DE LA CLASE AFIRMABA LO CONTRARIO, TEXTUAL: «The cache key
     * is the md5 of the canonical (real) path of every discovered directory; if
     * any of those paths change (add/remove/rename), the key changes». Con la
     * clave que estaba escrita, no.
     *
     * ── 🔴 POR QUÉ ESTE TEST CREA DIRECTORIOS DE VERDAD ──────────────────────
     *
     * Un test que compare dos `cacheKey()` mide la clave, no el síntoma: queda
     * verde el día que alguien cambie la clave de una forma que igual no
     * invalide. Lo que hay que medir es lo que le pasó a quien lo encontró: un
     * módulo en disco que la app no ve. De ahí los directorios temporales y las
     * dos clases de provider declaradas arriba — `scan()` exige que la clase
     * exista.
     *
     * La segunda llamada va con una INSTANCIA NUEVA a propósito: el memo
     * `$resuelto` es por instancia, así que reusar la primera mediría el memo y
     * no el caché.
     */
    uses(MkLaravelTestCase::class);

    /**
     * Directorio de módulos temporal, con su realpath.
     *
     * 🔴 `realpath()` Y NO `sys_get_temp_dir()` A SECAS. En macOS el temp real
     * es `/var/folders/...` y `/var` es un symlink a `/private/var`:
     * `canonicalModulesPath()` compara `realpath($candidato) !== $candidato` y
     * descarta el candidato, así que el registry no encontraría NADA y los dos
     * `discover()` darían `[]` — verde, midiendo nada.
     */
    function modulesDirTemporal(): string
    {
        $base = sys_get_temp_dir().'/mk-modules-key-'.getmypid().'-'.uniqid();
        mkdir($base, 0o777, true);

        return realpath($base);
    }

    function borrarRecursivo(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }
            $ruta = $dir.'/'.$entrada;
            is_dir($ruta) ? borrarRecursivo($ruta) : unlink($ruta);
        }

        rmdir($dir);
    }

    beforeEach(function () {
        ModuleProviderRegistry::flushProcessState();

        $this->modulesDir = modulesDirTemporal();
        config()->set('mk_director.paths.modules', $this->modulesDir);
    });

    afterEach(function () {
        borrarRecursivo($this->modulesDir);
    });

    // ─────────────────────────────────────────────────────────────────────────

    test('🔴 EL BUG: un módulo agregado después del primer discover() se ve enseguida', function () {
        mkdir($this->modulesDir.'/Alpha');

        expect((new ModuleProviderRegistry)->discover())
            ->toBe(['App\\Modules\\Alpha\\Providers\\AlphaServiceProvider']);

        mkdir($this->modulesDir.'/Beta');

        expect((new ModuleProviderRegistry)->discover())
            ->toContain('App\\Modules\\Beta\\Providers\\BetaServiceProvider');
    });

    test('🔴 y uno BORRADO deja de verse, sin esperar el TTL', function () {
        mkdir($this->modulesDir.'/Alpha');
        mkdir($this->modulesDir.'/Beta');

        expect((new ModuleProviderRegistry)->discover())->toHaveCount(2);

        rmdir($this->modulesDir.'/Beta');

        expect((new ModuleProviderRegistry)->discover())
            ->not->toContain('App\\Modules\\Beta\\Providers\\BetaServiceProvider')
            ->and((new ModuleProviderRegistry)->discover())->toHaveCount(1);
    });

    /**
     * EL CONTROL. Sin esto, los dos rojos de arriba se «arreglan» tirando el
     * caché a la basura —una clave distinta en cada llamada también los pone
     * verdes— y el registry vuelve a caminar el disco en cada request, que es
     * justo lo que la auditoría R4-006 vino a sacar.
     */
    test('CONTROL: sin cambios en el disco, la segunda instancia NO vuelve a escanear', function () {
        mkdir($this->modulesDir.'/Alpha');

        $primero = new class extends ModuleProviderRegistry
        {
            public int $escaneos = 0;

            public function scan(): array
            {
                $this->escaneos++;

                return parent::scan();
            }
        };
        $primero->discover();

        $segundo = new class extends ModuleProviderRegistry
        {
            public int $escaneos = 0;

            public function scan(): array
            {
                $this->escaneos++;

                return parent::scan();
            }
        };

        expect($segundo->discover())
            ->toBe(['App\\Modules\\Alpha\\Providers\\AlphaServiceProvider'])
            ->and($primero->escaneos)->toBe(1)
            ->and($segundo->escaneos)->toBe(0);
    });
}
