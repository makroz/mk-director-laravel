<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Models\AuthUser;

/**
 * `php artisan mk:auth:create-super-admin` — crea el primer usuario
 * super-admin del scope "admin".
 *
 * El command es **no-invasivo** (sprint 2026-06-24):
 *
 *   - Solo crea el usuario si la clase App\Modules\Admin\Models\Admin
 *     existe (asumimos que el consumer ya corrió `mk:make:auth-user
 *     Admin`). Si no, falla con un mensaje accionable.
 *   - Asigna el rol "super-admin" con guard "admin" (crea la fila en
 *     `roles` si no existe).
 *   - Asigna la ability "*" como grant directo (path `ability_user`).
 *     Esto evita requerir un seeder adicional; el `*` es el wildcard
 *     que mk-director trata como super-admin.
 *
 * Por qué existe: `docs/GETTING_STARTED.md` documentaba este command
 * desde 1.0.0 pero nunca se implementó. El audit 2026-06-24 lo detectó
 * y este PR cierra el gap.
 *
 * El command es **interactive**: pregunta email, name, password (con
 * confirmación). En CI se puede usar con `--no-interaction` y los
 * flags `--email`, `--name`, `--password` para skip los prompts.
 *
 * @see HasRoles
 * @see HasAbilities
 */
class AuthCreateSuperAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:auth:create-super-admin
        {--email= : Email del super-admin (omite el prompt)}
        {--name= : Nombre (omite el prompt)}
        {--password= : Password en texto plano (omite el prompt; preferir prompt o env en CI)}
        {--roles= : CSV de roles a sembrar en una corrida (omite → solo super-admin). Roles soportados: super-admin, admin, editor, viewer. (R-PKG-014 MEJORA-04)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea el primer usuario super-admin (scope=admin, role=super-admin, ability=*). Use --roles=super-admin,admin,editor,viewer para sembrar los 4 roles predefinidos.';

    /**
     * Definición de los roles predefinidos (R-PKG-014 MEJORA-04).
     *
     * Cada rol tiene un set de abilities pre-asignadas:
     *   - super-admin: `*` (bypass total).
     *   - admin: CRUD completo (`{scope}.{resource}.{action}` para todos los verbos).
     *   - editor: view + update (no delete ni create).
     *   - viewer: solo view.
     *
     * Override este map en subclases para customizar la jerarquía.
     */
    protected function roleAbilitiesMap(): array
    {
        $scope = 'admin';
        $resource = 'admins';

        return [
            'super-admin' => ['*'],
            'admin' => [
                "{$scope}.{$resource}.viewAny",
                "{$scope}.{$resource}.view",
                "{$scope}.{$resource}.create",
                "{$scope}.{$resource}.update",
                "{$scope}.{$resource}.delete",
            ],
            'editor' => [
                "{$scope}.{$resource}.viewAny",
                "{$scope}.{$resource}.view",
                "{$scope}.{$resource}.update",
            ],
            'viewer' => [
                "{$scope}.{$resource}.viewAny",
                "{$scope}.{$resource}.view",
            ],
        ];
    }

    public function handle(): int
    {
        $adminModel = 'App\\Modules\\Admin\\Models\\Admin';
        if (! class_exists($adminModel)) {
            $this->error("No se encontró la clase {$adminModel}.");
            $this->newLine();
            $this->line('Antes de crear un super-admin, generá el scope "admin" con:');
            $this->line('  php artisan mk:make:auth-user Admin');
            $this->newLine();
            $this->line('Eso crea app/Modules/Admin/Models/Admin.php + la migration + el ServiceProvider.');

            return self::FAILURE;
        }

        // ── Resolver roles a sembrar (R-PKG-014 MEJORA-04) ──
        // Default BC: solo super-admin.
        $rolesRaw = trim((string) $this->option('roles'));
        $rolesToSeed = $rolesRaw === ''
            ? ['super-admin']
            : array_values(array_filter(array_map('trim', explode(',', $rolesRaw))));

        $roleAbilitiesMap = $this->roleAbilitiesMap();

        // Validar roles contra el map.
        foreach ($rolesToSeed as $roleName) {
            if (! isset($roleAbilitiesMap[$roleName])) {
                $this->error("Rol no soportado: `{$roleName}`. Roles válidos: ".implode(', ', array_keys($roleAbilitiesMap)));

                return self::FAILURE;
            }
        }

        // 1. Recolectar credenciales base.
        $email = $this->option('email') ?: $this->ask('Email del super-admin');
        $name = $this->option('name') ?: $this->ask('Nombre del super-admin');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Email inválido: {$email}");

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('Password (mínimo 8 caracteres)');
        $confirm = $this->option('password') ? $password : $this->secret('Confirmá el password');

        if ($password !== $confirm) {
            $this->error('Los passwords no coinciden.');

            return self::FAILURE;
        }
        if (strlen($password) < 8) {
            $this->error('El password debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        // 2. Idempotencia: si ya existe un admin con ese email, salir limpio.
        if ($adminModel::where('email', $email)->exists()) {
            $this->warn("Ya existe un admin con email {$email}. No se creó nada.");

            return self::SUCCESS;
        }

        // 3. Crear el admin base. El email es el mismo para todos los roles
        //    (cada role se vincula al MISMO user). Esto modela el caso real
        //    donde un admin acumula roles (e.g. super-admin + admin).
        /** @var AuthUser $admin */
        $admin = $adminModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // 4. Asignar roles + abilities a cada uno.
        foreach ($rolesToSeed as $roleName) {
            $admin->assignRole($roleName);

            // Otorgar abilities del rol como grants directos.
            // Para super-admin, esto es `*` (bypass).
            // Para admin/editor/viewer, son abilities específicas.
            foreach ($roleAbilitiesMap[$roleName] as $ability) {
                $admin->giveAbilityTo($ability);
            }
        }

        $this->newLine();
        $infoVerb = count($rolesToSeed) > 1 ? 'Roles sembrados.' : 'Super-admin creado.';
        $this->info('✅ '.$infoVerb);
        $this->table(
            ['Campo', 'Valor'],
            [
                ['id',          (string) $admin->getKey()],
                ['name',        $admin->name],
                ['email',       $admin->email],
                ['auth_scope',  $admin->getAuthScope() ?? 'admin'],
                ['roles',       $admin->roles->pluck('name')->implode(', ') ?: '—'],
                ['canMk(*)',    $admin->canMk('*') ? 'yes (super-admin)' : 'no'],
            ],
        );
        $this->newLine();
        $this->line('Login:');
        $this->line('  POST /api/admin/auth/login');
        $this->line('  { "email": "'.$email.'", "password": "<el que tipeaste>" }');

        return self::SUCCESS;
    }
}
