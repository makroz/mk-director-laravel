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
        {--password= : Password en texto plano (omite el prompt; preferir prompt o env en CI)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea el primer usuario super-admin (scope=admin, role=super-admin, ability=*).';

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

        // 1. Recolectar credenciales.
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

        // 3. Crear el admin. La clase generada por mk:make:auth-user
        //    setea auth_scope='admin' en el constructor; no hace falta
        //    pasarlo como atributo.
        /** @var AuthUser $admin */
        $admin = $adminModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

        // 4. Asignar rol "super-admin" (lo crea si no existe, con guard
        //    = auth_scope del user = "admin").
        $admin->assignRole('super-admin');

        // 5. Asignar ability "*" como grant directo. Esto es lo que
        //    {@see \Mk\Director\Auth\Services\AbilityResolver} consulta
        //    y matchea con cualquier ability. Más robusto que depender
        //    de que el rol "super-admin" tenga la ability "*" en su
        //    set de abilities.
        $admin->giveAbilityTo('*');

        $this->newLine();
        $this->info('✅ Super-admin creado.');
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
