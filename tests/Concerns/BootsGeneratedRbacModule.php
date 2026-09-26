<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Console\Commands\MakeModuleCommand;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Genera `mk:module Squad --with-rbac` con `handle()` ENTERO y lo levanta en la
 * app real de {@see BootsHttpApp}: las 18 rutas generadas, por el Kernel, con
 * los controllers, Policies y el provider tal como salen del scaffolder.
 * Requiere `BootsHttpApp` en el mismo test.
 *
 * 🔴 El módulo se genera UNA vez por proceso, en un directorio fijo por PID, y
 * se borra al terminar el proceso. Las clases `App\Modules\Squad\*` se cargan
 * una sola vez y el provider carga las rutas desde su `__DIR__`: si cada
 * archivo de test generara el suyo y lo borrara al final, el siguiente
 * cargaría las rutas de un directorio que ya no existe.
 *
 * El actor viaja en el header `X-Actor` y lo resuelve el guard POR DEFECTO:
 * es donde lo buscan `$this->authorize()` y la Policy del CRUD. El consumer
 * real lo resuelve con el middleware que le pasa a `--middleware`.
 */
trait BootsGeneratedRbacModule
{
    public const RBAC_ABILITIES = [
        'squad.squads.viewAny', 'squad.squads.view', 'squad.squads.create', 'squad.squads.update', 'squad.squads.delete',
        'squad.squads.assignRole', 'squad.squads.revokeRole',
        'squad.roles.viewAny', 'squad.roles.view', 'squad.roles.create', 'squad.roles.update', 'squad.roles.delete', 'squad.roles.syncAbilities',
        'squad.abilities.viewAny', 'squad.abilities.view',
    ];

    public static function rbacModuleBase(): string
    {
        return sys_get_temp_dir().'/mk-module-rbac-'.getmypid();
    }

    /**
     * @param  array<string, mixed>  $mkDirectorConfig
     */
    public function bootRbacModule(array $mkDirectorConfig = [], bool $tenantColumns = false): Application
    {
        $dir = self::rbacModuleBase().'/app/Modules/Squad';

        if (! is_file("{$dir}/SquadModuleServiceProvider.php")) {
            $this->generateRbacModule();
        }

        $app = $this->bootHttpApp('App\\Modules\\Squad\\Models\\Squad', $mkDirectorConfig);

        foreach (['Models/Squad', 'Models/Role', 'Models/Ability', 'Services/RbacService',
            'Policies/SquadPolicy', 'Policies/RolePolicy', 'Policies/AbilityPolicy',
            'Controllers/SquadController', 'Controllers/RoleController', 'Controllers/AbilityController',
            'SquadModuleServiceProvider'] as $file) {
            if (! class_exists('App\\Modules\\Squad\\'.str_replace('/', '\\', $file), false)) {
                require "{$dir}/{$file}.php";
            }
        }

        $migrations = glob("{$dir}/Database/Migrations/*.php") ?: [];
        sort($migrations);
        foreach ($migrations as $migration) {
            (require $migration)->up();
        }

        if ($tenantColumns) {
            foreach (['squad_users', 'squad_roles'] as $table) {
                Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('client_id')->nullable());
            }
        }

        $app['auth']->viaRequest('actor-header', function (Request $request) {
            $id = $request->header('X-Actor');

            return $id ? ('App\\Modules\\Squad\\Models\\Squad')::query()->find($id) : null;
        });
        $app['config']->set('auth.guards.actor-header', ['driver' => 'actor-header']);
        $app['config']->set('auth.defaults.guard', 'actor-header');

        // `$request->validate()` es un macro de FoundationServiceProvider, que el
        // harness no registra. El `syncAbilities` generado lo usa.
        if (! Request::hasMacro('validate')) {
            Request::macro('validate', function (array $rules, ...$params) {
                return validator()->validate($this->all(), $rules, ...$params);
            });
        }

        $app->register('App\\Modules\\Squad\\SquadModuleServiceProvider');

        return $app;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function rbacSend(string $method, string $uri, array $data = [], ?int $actor = null): array
    {
        $headers = $actor !== null ? ['HTTP_X_ACTOR' => (string) $actor] : [];
        $response = $this->httpSend($method, $uri, $data, $headers);

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    /** @return array<string, int> nombre → id */
    public function seedRbacAbilities(): array
    {
        $ids = [];
        foreach (self::RBAC_ABILITIES as $name) {
            $ids[$name] = DB::table('squad_abilities')->insertGetId(['name' => $name]);
        }

        return $ids;
    }

    /** @param  array<int, string>  $abilities */
    public function seedRbacRole(string $name, array $abilities, ?int $clientId = null): int
    {
        $id = DB::table('squad_roles')->insertGetId(array_filter(['name' => $name, 'client_id' => $clientId], fn ($v) => $v !== null));
        foreach ($abilities as $ability) {
            DB::table('squad_ability_role')->insert([
                'ability_id' => DB::table('squad_abilities')->where('name', $ability)->value('id'),
                'role_id' => $id,
            ]);
        }

        return $id;
    }

    /** @param  array<int, int>  $roleIds */
    public function seedRbacUser(string $name, array $roleIds, ?int $clientId = null): int
    {
        $id = DB::table('squad_users')->insertGetId(array_filter([
            'name' => $name, 'email' => "{$name}@squad.test", 'password' => 'hash-original', 'client_id' => $clientId,
        ], fn ($v) => $v !== null));
        foreach ($roleIds as $roleId) {
            DB::table('squad_role_user')->insert(['role_id' => $roleId, 'user_id' => $id]);
        }

        return $id;
    }

    public function userHasRbacRole(int $userId, int $roleId): bool
    {
        return DB::table('squad_role_user')->where(['user_id' => $userId, 'role_id' => $roleId])->exists();
    }

    /** @return array<int, string> */
    public function rbacRoleAbilities(int $roleId): array
    {
        return DB::table('squad_ability_role')
            ->join('squad_abilities', 'squad_abilities.id', '=', 'squad_ability_role.ability_id')
            ->where('role_id', $roleId)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    private function generateRbacModule(): void
    {
        $base = self::rbacModuleBase();
        $fs = new Filesystem;
        $fs->ensureDirectoryExists($base.'/app');
        $fs->ensureDirectoryExists($base.'/bootstrap');
        file_put_contents($base.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
        register_shutdown_function(fn () => (new Filesystem)->deleteDirectory($base));

        $app = $this->bootHttpApp(\stdClass::class);
        $app->setBasePath($base);

        $command = new MakeModuleCommand;
        $command->setLaravel($app);
        $output = new BufferedOutput;
        $exit = $command->run(new ArrayInput(['name' => 'Squad', '--with-rbac' => true]), $output);

        $this->tearDownHttpApp();

        if ($exit !== 0) {
            throw new RuntimeException("mk:module Squad --with-rbac falló:\n".$output->fetch());
        }
    }
}
