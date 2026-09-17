<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Access\AccessGrantGuard;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Console\Concerns\ResolvesScopeModel;

/**
 * `php artisan mk:auth:grant` — le da roles y abilities a un usuario que YA
 * existe.
 *
 * ── 🔴 POR QUÉ HACE FALTA UN COMANDO APARTE ─────────────────────────────
 *
 * `mk:auth:create-super-admin` es idempotente: si el login ya está tomado,
 * avisa «no se creó nada» y sale en verde SIN tocar roles ni abilities. Es lo
 * correcto —re-correr un comando de alta no debería reescribir permisos— pero
 * dejaba un agujero de operación: un usuario creado con `--no-roles` quedaba
 * sin ninguna capacidad y sin ninguna forma de dárselas salvo `tinker`.
 *
 * Eso es exactamente lo que pasa con el PRIMER usuario de un scope nuevo: no
 * hay todavía pantalla de roles donde apretar el botón, y el único que podría
 * usarla es justamente el que no tiene permisos. El huevo y la gallina.
 *
 * ── LO QUE HACE ─────────────────────────────────────────────────────────
 *
 *   php artisan mk:auth:grant operator mario@ejemplo.com --abilities='*'
 *   php artisan mk:auth:grant mesero 7654321 --roles=viewer
 *   php artisan mk:auth:grant admin ana@ejemplo.com --roles=admin --abilities=admin.reports.view
 *
 * `{scope} {login}` posicionales, como `mk:auth:two-factor-reset`: los dos
 * comandos actúan sobre un usuario que ya existe y lo identifican con los
 * mismos dos datos. Que uno los pida posicionales y el otro con banderas es
 * una trampa gratis para quien escribe los dos el mismo día.
 *
 * - Busca al usuario por su login field (`getLoginField()`, no `email` clavado)
 *   y SIN global scopes: en consola no hay contexto de tenant y con
 *   `tenant.fail_closed` prendido el scope agrega `where 1 = 0`, o sea que el
 *   usuario existe y la consulta no lo ve (el mismo hallazgo #47 que ya mordió
 *   al comando de alta).
 * - Los roles se crean si no existen, con `guard` = el scope. Un rol es de un
 *   scope: el `viewer` de `admin` y el `viewer` de `mesero` son dos filas.
 * - Las abilities se otorgan como grants DIRECTOS (`ability_user`), que es el
 *   mismo camino que usa `create-super-admin` para el `*`.
 * - Es acumulativo, no un `sync`: no le saca nada al usuario. Para quitar hay
 *   que pasar por la pantalla de roles, donde queda registrado quién lo hizo.
 *
 * ── ⚠️ ESTE COMANDO NO TIENE ACTOR, Y POR ESO NO PASA POR EL GUARDIA ────
 *
 * {@see AccessGrantGuard} impide que alguien se dé a
 * sí mismo permisos que no tiene, o que le toque el acceso a quien tiene más
 * que él. Acá no hay «alguien»: hay una terminal con acceso al servidor y a la
 * base, que ya podría escribir la fila a mano. Poner el guardia sería teatro.
 * Quien tiene la consola tiene todo; el control de ese acceso es del servidor.
 */
class AuthGrantCommand extends Command
{
    use ResolvesScopeModel;

    protected $signature = 'mk:auth:grant
        {scope : Scope de auth del usuario (snake_case: admin, mesero, operator…).}
        {login : El valor del login field del usuario (email, ci, username… según el scope).}
        {--roles= : CSV de roles a asignar. Se crean con guard = el scope si no existen.}
        {--abilities= : CSV de abilities a otorgar como grants directos. `*` es el comodín de super-admin.}';

    protected $description = 'Le da roles y abilities a un usuario que ya existe (el primer usuario de un scope, que no tiene todavía pantalla donde dárselos).';

    public function handle(): int
    {
        $scope = trim((string) $this->argument('scope'));

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $scope)) {
            $this->error("--scope debe ser un scope en snake_case (ej: admin, operator). Recibido: '{$scope}'.");

            return self::FAILURE;
        }

        $modelClass = $this->resolveScopeModel($scope);

        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, AuthUser::class)) {
            $this->error("No se encontró el modelo del scope '{$scope}' ({$modelClass}).");
            $this->line('Generá el scope con: php artisan mk:make:auth-user '.ucfirst($scope));

            return self::FAILURE;
        }

        $roles = $this->csv((string) $this->option('roles'));
        $abilities = $this->csv((string) $this->option('abilities'));

        if ($roles === [] && $abilities === []) {
            $this->error('No pediste nada: pasá --roles y/o --abilities.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('abilities') || ! Schema::hasTable('ability_user')) {
            $this->error('Faltan las tablas de RBAC (`abilities` / `ability_user`). Publicá y corré las migraciones del paquete.');

            return self::FAILURE;
        }

        $login = (string) $this->argument('login');
        $loginField = (new $modelClass)->getLoginField();

        /** @var AuthUser|null $user */
        $user = $modelClass::withoutGlobalScopes()->where($loginField, $login)->first();

        if ($user === null) {
            $this->error("No existe un {$scope} con {$loginField} = {$login}.");

            return self::FAILURE;
        }

        foreach ($roles as $role) {
            // 🔴 El rol NO se crea acá. `assignRole()` ya hace el
            // `firstOrCreate(['name', 'guard' => $user->getAuthScope()])`, y el
            // guard tiene que salir del USUARIO, no de `--scope`: es el usuario
            // quien define a qué scope pertenece el rol que se le engancha. Un
            // `firstOrCreate` propio acá creaba la misma fila un instante antes
            // y no cambiaba nada — o peor, la creaba con otro guard si `--scope`
            // y el `auth_scope` del modelo no coincidieran, dejando dos filas
            // con el mismo nombre y el usuario enganchado a la que no se ve en
            // el picker de su scope.
            $user->assignRole($role);
            $this->line("  rol       {$role}");
        }

        foreach ($abilities as $ability) {
            $user->giveAbilityTo($ability);
            $this->line("  ability   {$ability}");
        }

        $this->newLine();
        $this->info("✅ Listo. {$scope} {$login}:");
        $this->line('   roles      '.($user->roles()->pluck('roles.name')->implode(', ') ?: '—'));
        $this->line('   abilities  '.(implode(', ', $user->getEffectiveAbilities()) ?: '—'));
        $this->line('   canMk(*)   '.($user->canMk('*') ? 'sí' : 'no'));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function csv(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($v) => $v !== ''));
    }
}
