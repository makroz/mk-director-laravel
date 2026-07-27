<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * mk:prune-abilities — saca de la tabla las abilities que el código ya no declara.
 *
 * Es el complemento exacto de `mk:discover-abilities`: uno hace que la tabla
 * tenga lo que el código declara, el otro saca lo que sobra. Sin el segundo, la
 * tabla es acumulativa para siempre: cada ability renombrada, cada módulo
 * retirado y cada prueba manual deja su fila, y el listado de permisos se
 * vuelve un lugar donde nadie sabe qué está vivo.
 *
 * No es un problema hipotético: el bug del scope pluralizado (arreglado en
 * `DiscoverAbilitiesCommand`) dejó en reto-api 28 abilities con nombres que
 * ninguna ruta valida, indistinguibles a simple vista de las buenas.
 *
 * ## Qué considera VIVO
 *
 * Una ability sobrevive si cumple CUALQUIERA de estas:
 *
 *  1. La declara el discovery (atributos, docblocks, provider o `$mkConfig`).
 *  2. La exige alguna ruta por el middleware `mk.ability:`.
 *  3. Sale del config (`mk_director.auth.abilities.*`).
 *  4. Es el wildcard `*`.
 *  5. Está OTORGADA a un rol o a un usuario — salvo `--incluir-otorgadas`.
 *
 * Las cinco son deliberadamente generosas. Dejar de más una fila que nadie mira
 * cuesta una línea en un listado; borrar de más una que alguien usaba cuesta un
 * 403 que nadie sabe leer.
 *
 * ## Las dos reglas que hacen que esto sea seguro
 *
 * 🔴 **La #2 no es redundante con la #1.** Una ruta puede exigir una ability que
 * el discovery no descubre —porque el módulo la arma a mano, o porque el
 * controller no sigue la convención—. Borrarla dejaría la ruta pidiendo un
 * permiso que no existe: 403 para TODO el mundo, incluso para quien lo tenía.
 * De todas las formas de equivocarse acá, ésta es la peor, y es la única que el
 * discovery por sí solo no puede evitar.
 *
 * 🔴 **La #5 existe porque borrar una ability otorgada REVOCA en silencio.** Las
 * filas de `ability_role` / `ability_user` se van con ella. Que el código ya no
 * la declare no significa que nadie la esté usando: puede ser una ability
 * legítima que un admin concede a mano desde la UI, o una que alguien
 * simplemente se olvidó de declarar. Por eso se saltean y se avisan una por una,
 * con la cuenta de a cuántos afectaría.
 *
 * ## Uso
 *
 *   php artisan mk:prune-abilities                  # preview (default No)
 *   php artisan mk:prune-abilities --dry-run        # preview, sin preguntar
 *   php artisan mk:prune-abilities --force          # borra
 *   php artisan mk:prune-abilities --force --incluir-otorgadas
 *
 * 🔴 NO ACEPTA `--module`, Y ES A PROPÓSITO. El conjunto de "lo declarado" se
 * calcula sobre TODOS los módulos siempre. Podar mirando un módulo solo borraría
 * las abilities de todos los demás — un `--module` acá no acota el daño, lo
 * concentra.
 */
class PruneAbilitiesCommand extends Command
{
    protected $signature = 'mk:prune-abilities
                            {--dry-run : Preview sin borrar (skip prompt)}
                            {--force : Borrar sin preguntar}
                            {--incluir-otorgadas : Borrar también las que estén otorgadas a un rol o usuario. REVOCA permisos.}
                            {--json : Output en JSON en vez de tabla humana}';

    protected $description = 'Saca de la tabla `abilities` las que el código ya no declara. Complemento de mk:discover-abilities.';

    public function handle(): int
    {
        if ($this->option('dry-run') && $this->option('force')) {
            $this->error('No combines --dry-run y --force. Elegí uno.');

            return self::FAILURE;
        }

        $tabla = 'abilities';
        if (! $this->tablaExiste($tabla)) {
            $this->error("No existe la tabla `{$tabla}`. Corriste `php artisan migrate`?");

            return self::FAILURE;
        }

        // Primero las rutas: si no se pueden leer, esto lanza y no se borra nada.
        $deLasRutas = $this->exigidasPorLasRutas();

        $vivas = $this->nombresVivos($deLasRutas, $this->declaradasPorElDiscovery());
        $filas = DB::table($tabla)->get(['id', 'name']);

        $candidatas = $filas->reject(fn ($f): bool => in_array($f->name, $vivas, true));

        // Las otorgadas se apartan ANTES de cualquier borrado.
        [$otorgadas, $huerfanas] = $candidatas->partition(fn ($f): bool => $this->estaOtorgada($f->id));

        if (! $this->option('incluir-otorgadas')) {
            foreach ($otorgadas as $f) {
                $this->warn(
                    "   ⚠ `{$f->name}` no la declara el código, pero está OTORGADA "
                    ."(roles: {$this->cuantosRoles($f->id)}, usuarios: {$this->cuantosUsuarios($f->id)}). "
                    .'No se toca. Usá --incluir-otorgadas si de verdad querés revocarla.'
                );
            }
        } else {
            $huerfanas = $huerfanas->concat($otorgadas);
            $otorgadas = collect();
        }

        if ($huerfanas->isEmpty()) {
            $this->info('No hay abilities para podar. La tabla ya refleja lo que declara el código.');

            return self::SUCCESS;
        }

        $this->reportar($huerfanas, $otorgadas);

        if (! $this->debeEscribir($huerfanas->count())) {
            $this->line('   (preview — no se borró nada)');

            return self::SUCCESS;
        }

        $ids = $huerfanas->pluck('id')->all();

        $this->podar($ids);

        $this->info('   → '.count($ids).' abilities podadas.');

        return self::SUCCESS;
    }

    /**
     * El borrado. Separado de `handle()` para poder medirlo contra una base real.
     *
     * 🔴 LAS PIVOTS PRIMERO Y A MANO. No todos los consumidores tienen las FK con
     * ON DELETE CASCADE —las tablas per-scope las genera el scaffolder en el
     * proyecto de cada uno—, y confiar en que la base limpie deja filas en
     * `ability_role` / `ability_user` apuntando a un id que ya no existe. Una
     * pivot huérfana no da error: da resultados raros mucho después, en un JOIN
     * que devuelve de menos sin que nadie sepa por qué.
     *
     * Todo en una transacción: quedarse a mitad de camino sería peor que no
     * haber empezado.
     *
     * @param  array<int, int>  $ids
     */
    public function podar(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        DB::transaction(function () use ($ids): void {
            foreach (['ability_role', 'ability_user'] as $pivot) {
                if ($this->tablaExiste($pivot)) {
                    DB::table($pivot)->whereIn('ability_id', $ids)->delete();
                }
            }

            DB::table('abilities')->whereIn('id', $ids)->delete();
        });
    }

    /**
     * Todo lo que NO se puede borrar.
     *
     * Recibe las de las rutas en vez de buscarlas: así `handle()` puede
     * resolverlas primero y ABORTAR si no puede, en lugar de que una lista vacía
     * pase por acá disfrazada de "no hay rutas protegidas".
     *
     * @param  array<int, string>  $deLasRutas
     * @param  array<int, string>  $declaradas
     * @return array<int, string>
     */
    private function nombresVivos(array $deLasRutas, array $declaradas): array
    {
        $vivas = ['*'];

        // 1. Lo que declara el código, sobre TODOS los módulos.
        $vivas = array_merge($vivas, $declaradas);

        // 2. Lo que exige alguna ruta. La red de seguridad contra el peor error.
        $vivas = array_merge($vivas, $deLasRutas);

        // 3. Lo que nombra el config. No sale de ningún atributo ni de ninguna
        //    ruta, así que sin esto el paquete borraría sus propias abilities.
        foreach ((array) config('mk_director.auth.abilities', []) as $nombre) {
            if (is_string($nombre) && $nombre !== '') {
                $vivas[] = $nombre;
            }
        }

        return array_values(array_unique($vivas));
    }

    /**
     * @return array<int, string>
     */
    private function declaradasPorElDiscovery(): array
    {
        // 🔴 SIN `--module`: ver el docblock de la clase. El conjunto tiene que
        // ser el de TODOS los módulos o la poda borra los ajenos.
        //
        // 🔴 Y SIEMPRE `--dry-run`. El discovery en modo escritura crearía filas
        // justo antes de que las contemos, lo que es inofensivo, pero también
        // tocaría el rol base — y una PODA no tiene por qué modificar nada.
        Artisan::call('mk:discover-abilities', ['--dry-run' => true, '--json' => true]);

        $reporte = json_decode(trim(Artisan::output()), true);

        if (! is_array($reporte)) {
            // Sin lista de declaradas no hay nada seguro que podar: TODO
            // parecería huérfano. Frenar es la única salida sensata.
            throw new \RuntimeException(
                'No se pudo leer el reporte de `mk:discover-abilities --json`. '
                .'Sin esa lista, podar borraría abilities vivas. Corré el discovery a mano y mirá qué falla.'
            );
        }

        $nombres = [];
        foreach ($reporte as $modulo) {
            foreach ($modulo['abilities'] ?? [] as $a) {
                if (isset($a['name']) && is_string($a['name'])) {
                    $nombres[] = $a['name'];
                }
            }
        }

        return $nombres;
    }

    /**
     * Abilities que alguna ruta exige por el middleware `mk.ability:`.
     *
     * 🔴 SI NO PUEDE LEER LAS RUTAS, LANZA. No devuelve una lista vacía.
     *
     * La diferencia entre las dos cosas es todo: una lista vacía significa "no
     * hay ninguna ruta que proteja abilities", y es indistinguible de "no pude
     * mirar". Si se confunden, la poda sigue adelante sin su red de seguridad
     * más importante y borra justo las abilities que las rutas exigen — 403 para
     * todo el mundo, en endpoints que andaban.
     *
     * @return array<int, string>
     *
     * @throws \RuntimeException si el router no está disponible.
     */
    private function exigidasPorLasRutas(): array
    {
        // 🔴 `$this->laravel` puede ser null en un comando construido a mano.
        // Sin contenedor no hay router, y sin router no se poda.
        $app = $this->laravel ?? (function_exists('app') ? app() : null);

        if ($app === null || ! $app->bound('router')) {
            throw new \RuntimeException(
                'No hay router disponible: no se puede saber qué abilities exigen las rutas. '
                .'Podar sin esa lista borraría las que los endpoints validan. Abortado.'
            );
        }

        $nombres = [];

        foreach (Route::getRoutes() as $ruta) {
            try {
                $middlewares = $ruta->gatherMiddleware();
            } catch (Throwable) {
                // Una ruta que no sabe enumerar su middleware es un problema de
                // ESA ruta, no del comando. Se saltea, pero se avisa: si la ruta
                // exigía una ability, se acaba de perder su protección.
                $this->warn("   ⚠ No se pudo leer el middleware de una ruta ({$ruta->uri()}). Si exigía una ability, no está protegida en esta corrida.");

                continue;
            }

            $nombres = array_merge($nombres, self::abilitiesDeMiddleware($middlewares));
        }

        return $nombres;
    }

    /**
     * Extrae los nombres de ability de una lista de middleware.
     *
     * Separado y `static` para poder medirlo sin un router: es la parte con
     * forma de tener bugs. `mk.ability:a,b` es UNA sola cadena con dos
     * abilities — partirla mal deja viva la primera y borra la segunda, y el 403
     * sale sólo en el endpoint que combina las dos, que es el que nadie prueba.
     *
     * @param  iterable<mixed>  $middlewares
     * @return array<int, string>
     */
    public static function abilitiesDeMiddleware(iterable $middlewares): array
    {
        $prefijo = 'mk.ability:';
        $nombres = [];

        foreach ($middlewares as $m) {
            if (! is_string($m) || ! str_starts_with($m, $prefijo)) {
                continue;
            }

            foreach (explode(',', substr($m, strlen($prefijo))) as $n) {
                $n = trim($n);
                if ($n !== '') {
                    $nombres[] = $n;
                }
            }
        }

        return $nombres;
    }

    public function estaOtorgada(int $abilityId): bool
    {
        return $this->cuantosRoles($abilityId) > 0 || $this->cuantosUsuarios($abilityId) > 0;
    }

    private function cuantosRoles(int $abilityId): int
    {
        return $this->tablaExiste('ability_role')
            ? DB::table('ability_role')->where('ability_id', $abilityId)->count()
            : 0;
    }

    private function cuantosUsuarios(int $abilityId): int
    {
        return $this->tablaExiste('ability_user')
            ? DB::table('ability_user')->where('ability_id', $abilityId)->count()
            : 0;
    }

    /**
     * @param  Collection<int, object>  $huerfanas
     * @param  Collection<int, object>  $otorgadas
     */
    private function reportar($huerfanas, $otorgadas): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode([
                'a_podar' => $huerfanas->pluck('name')->values()->all(),
                'salteadas_por_estar_otorgadas' => $otorgadas->pluck('name')->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->table(
            ['A podar (el código ya no las declara)'],
            $huerfanas->map(fn ($f): array => [$f->name])->values()->all()
        );
    }

    private function debeEscribir(int $cuantas): bool
    {
        if ($this->option('dry-run')) {
            return false;
        }

        if ($this->option('force')) {
            return true;
        }

        return $this->confirm("¿Borrar estas {$cuantas} abilities? [y/N]", false);
    }

    private function tablaExiste(string $tabla): bool
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable($tabla);
        } catch (Throwable) {
            return false;
        }
    }
}
