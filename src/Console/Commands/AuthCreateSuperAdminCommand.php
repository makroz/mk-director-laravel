<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;

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
 * **R-PKG-046 F9-B05 fix — login field dinámico**:
 * Pre-fix, el command pineaba hardcoded `email` en signature, validación y
 * `where()`. Si el consumer ejecutó `mk:make:auth-user Admin --login-field=ci`,
 * el modelo `Admin::$loginField = 'ci'`, pero el command pedía `--email` y
 * buscaba por `where('email', ...)`. Resultado: incompatible con scopes no-email.
 * Workaround consumer-side (RETO): tinker manual con `firstOrCreate(['ci' => ...])`.
 *
 * Post-fix: el command detecta `$admin->getLoginField()` (default `'email'`,
 * override `'ci'`, etc.) y:
 *   - signature dinámica `--{loginField}` (e.g. `--ci`, `--email`, `--username`).
 *   - `--email` se mantiene como BC fallback (cuando `loginField='email'`).
 *   - Validación dinámica: `FILTER_VALIDATE_EMAIL` solo si `loginField='email'`,
 *     si no, validar `required|string|min:3`.
 *   - `where()` dinámico: `where($loginField, $value)`.
 *   - Tabla final muestra el campo correcto.
 *   - Output de "Login:" usa `{loginField}`.
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
        {--email= : Email del super-admin (omite el prompt; BC para login field=email)}
        {--name= : Nombre (omite el prompt)}
        {--password= : Password en texto plano (omite el prompt; preferir prompt o env en CI)}
        {--roles= : CSV de roles a sembrar en una corrida (omite → solo super-admin). Roles soportados: super-admin, admin, editor, viewer. (R-PKG-014 MEJORA-04)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea el primer usuario super-admin (scope=admin, role=super-admin, ability=*). Use --roles=super-admin,admin,editor,viewer para sembrar los 4 roles predefinidos. Soporta login field custom (e.g. --ci, --username) pineado por el scaffolder.';

    /**
     * R-PKG-046 F9-B05 — Login field dinámico.
     *
     * Laravel console command signature NO soporta placeholders dinámicos
     * (los `{}` se parsean estáticamente en el constructor). Usamos
     * `configure()` para agregar `--{loginField}` dinámicamente después de
     * detectar el campo del modelo Admin.
     *
     * BC: `--email` permanece en la signature hardcoded para scopes con
     * `loginField='email'` (default). Para `loginField != 'email'`,
     * `--{loginField}` se agrega via `configure()`.
     */
    protected function configure(): void
    {
        parent::configure();

        $adminModel = 'App\\Modules\\Admin\\Models\\Admin';

        // BC: si el modelo Admin NO existe todavía, asumimos loginField='email'
        // (default). El handle() hace la verificación estricta y falla limpio.
        if (! class_exists($adminModel)) {
            return;
        }

        try {
            $loginField = (new $adminModel)->getLoginField();
        } catch (\Throwable $e) {
            // Si getLoginField() falla (model sin la prop, etc.), BC fallback.
            return;
        }

        // Solo agregar el flag dinámico si difiere del BC `--email`.
        if ($loginField !== 'email') {
            $this->getDefinition()->addOption(
                new \Symfony\Component\Console\Input\InputOption(
                    name: $loginField,
                    shortcut: null,
                    mode: \Symfony\Component\Console\Input\InputOption::VALUE_OPTIONAL,
                    description: "Valor del login field `{$loginField}` del super-admin (omite el prompt)",
                    default: null,
                ),
            );
        }
    }

    /**
     * Login field detectado del modelo Admin (R-PKG-046 F9-B05).
     * Default: 'email'. Se sobrescribe en handle() si el modelo override.
     *
     * @var string
     */
    protected string $loginField = 'email';

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

        // R-PKG-046 F9-B05 — Detectar login field del modelo (override per scope).
        // El scaffolder pinea `protected string $loginField = 'ci'` (o 'email', etc.)
        // en el modelo scaffoldeado. Leemos vía `getLoginField()` que ya existe
        // en `AuthUser` desde R-PKG-009 D6.
        $this->loginField = (new $adminModel)->getLoginField();

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
        //
        // R-PKG-046 F9-B05 — Resolver el valor del login field dinámicamente.
        // BC: si `loginField='email'` y el consumer pasa `--email`, lo usa.
        // NEW: si `loginField != 'email'` (e.g. 'ci'), acepta `--ci` flag.
        // Prompt interactivo pineado con el nombre del login field.
        $loginFieldValue = $this->resolveLoginFieldValue();

        // Validación dinámica según tipo de login field.
        if ($this->loginField === 'email') {
            if (! filter_var($loginFieldValue, FILTER_VALIDATE_EMAIL)) {
                $this->error("Email inválido: {$loginFieldValue}");

                return self::FAILURE;
            }
        } else {
            // Para ci, username, phone, etc.: required + string + min length.
            if (strlen($loginFieldValue) < 3) {
                $this->error("{$this->loginField} inválido (mínimo 3 caracteres): {$loginFieldValue}");

                return self::FAILURE;
            }
        }

        $nameOption = trim((string) $this->option('name'));

        // R-PKG-016 BUG-NEW-15 fix: si `--name` no se pasa Y el modo es
        // `--no-interaction` (CI / seed scripts), `$this->ask()` retorna
        // `null` y `create(['name' => null, ...])` rompe con `NOT NULL violation
        // on column "name"`. Fallback chain:
        //   1. `--name=` flag (highest priority)
        //   2. prompt interactivo `ask('Nombre')`
        //   3. autogenerar del login field (e.g. `1234567` → `1234567`,
        //      o `admin@example.com` → `Admin`)
        // Esto permite ejecutar `mk:auth:create-super-admin --{loginField}=X
        // --password=Y --roles=... --no-interaction` sin tener que especificar name.
        if ($nameOption !== '') {
            $name = $nameOption;
        } else {
            $name = $this->ask('Nombre del super-admin');
        }

        if ($name === null || $name === '') {
            // R-PKG-046 F9-B05 — Autogenerar del login field (no solo de email).
            // Si `loginField='email'`: tomar local-part. Si no: usar el valor entero
            // como fallback. Si también está vacío: 'Admin'.
            if ($this->loginField === 'email') {
                $localPart = explode('@', $loginFieldValue, 2)[0] ?? '';
                $name = $localPart !== ''
                    ? ucfirst(strtolower($localPart))
                    : 'Admin';
            } else {
                $name = $loginFieldValue !== ''
                    ? ucfirst(strtolower($loginFieldValue))
                    : 'Admin';
            }
            $this->line("   (autogenerado de {$this->loginField}: nombre = \"{$name}\")");
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

        // 2. Idempotencia: si ya existe un admin con ese login field value, salir limpio.
        // R-PKG-046 F9-B05 — where() dinámico según loginField.
        if ($adminModel::where($this->loginField, $loginFieldValue)->exists()) {
            $this->warn("Ya existe un admin con {$this->loginField} {$loginFieldValue}. No se creó nada.");

            return self::SUCCESS;
        }

        // 3. Crear el admin base.
        //
        // R-PKG-046 F9-B05 — `create()` solo con los campos que existen
        // en el fillable del modelo scaffoldeado. Si `loginField='ci'`,
        // el `email` puede NO estar en `$fillable` (RETO lo omite).
        //
        // Strategy: pasar solo `name`, `password`, el login field value, y
        // `auth_scope` (siempre presente). NO pineamos `email` salvo que
        // `loginField='email'`.
        $createAttrs = [
            'name' => $name,
            'password' => Hash::make($password),
            $this->loginField => $loginFieldValue,
        ];

        // R-PKG-016 BUG-NEW-15 fix pineado por BC: pinear `auth_scope` si está en fillable.
        // Defense-in-depth: `Schema::hasColumn()` check evita SQLSTATE si la
        // columna no existe (versiones pre-R-PKG-022 del schema).
        if (Schema::hasColumn((new $adminModel)->getTable(), 'auth_scope')) {
            $createAttrs['auth_scope'] = $adminModel === 'App\\Modules\\Admin\\Models\\Admin'
                ? 'admin'
                : (new $adminModel)->getAuthScope() ?? 'admin';
        }

        /** @var AuthUser $admin */
        $admin = $adminModel::create($createAttrs);

        // OBS-02 fix (R-PKG-031 pineado 2026-06-28, defense-in-depth): pinear
        // `is_active => true` explícitamente al crear el admin. Sin esto, si
        // la columna `is_active` existe en la tabla del scope (pineada per
        // R-PKG-027 PKG-NEW-04, nullable), el admin se crea con `is_active =
        // null`. Semántica pineada: `null` = permitido (compat con datos
        // preexistentes) — pero defense-in-depth ideal es pinear `true` desde
        // el inicio para que un admin NUEVO siempre esté activo explícito.
        //
        // Patrón consistente con PKG-NEW-04: `Schema::hasColumn()` para BC con
        // scopes que NO tienen la columna. Si no existe, no pinear (evita
        // SQLSTATE "Unknown column 'is_active'").
        //
        // Bypass fillable: `is_active` NO está en `$fillable` del modelo base
        // `AuthUser` ni del stub scaffoldeado (status: not exposed via mass
        // assignment por diseño — solo lectura/escritura explícita). Usamos
        // property assignment + save() en vez de add al array de create().
        // Follow-up opcional: pinear `is_active` en `$fillable` del stub del
        // modelo scaffoldeado (defense-in-depth adicional, pero no requerido
        // para este fix).
        if (Schema::hasColumn((new $adminModel)->getTable(), 'is_active')) {
            $admin->is_active = true;
            $admin->save();
        }

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

        // 5. R-PKG-021 BUG-NEW-30 (MEDIUM): invocar el `AdminRolesSeeder`
        //    scaffoldeado para popular `ability_role` (asigna abilities a
        //    los ROLES, no a los users). Sin esto, `ability_role` queda
        //    vacío y los roles no tienen abilities asignadas — solo los
        //    grants directos (path `ability_user`) funcionan.
        //
        //    Namespace DDD (R-P-009): `App\Modules\Admin\Database\Seeders\`
        //    Antes (silencioso) el comando asumía `Database\Seeders\...` que
        //    NO existe en proyectos DDD estricto. Fallaba con
        //    `Target class [Database\Seeders\AdminRolesSeeder] does not exist.`
        //
        //    Fix: `class_exists()` chequea el namespace DDD correcto. Si no
        //    existe, warning explícito (no error fatal) para que el consumer
        //    sepa que necesita scaffoldear el seeder.
        $this->seedAdminRolesIfAvailable();

        // Pin el flag `is_fixed` en las filas de sistema que NUNCA deben
        // editarse/eliminarse desde el CRUD: el role `super-admin` y la
        // ability wildcard `*`. Idempotente (mass update por nombre). Se
        // corre solo si super-admin fue parte de la siembra.
        if (in_array('super-admin', $rolesToSeed, true)) {
            Role::query()->where('name', 'super-admin')->update(['is_fixed' => FixedStatus::Fixed->value]);
            Ability::query()->where('name', '*')->update(['is_fixed' => FixedStatus::Fixed->value]);
        }

        $this->newLine();
        $infoVerb = count($rolesToSeed) > 1 ? 'Roles sembrados.' : 'Super-admin creado.';
        $this->info('✅ '.$infoVerb);
        $this->table(
            ['Campo', 'Valor'],
            [
                ['id',          (string) $admin->getKey()],
                ['name',        $admin->name],
                [$this->loginField, $admin->{$this->loginField}],
                ['auth_scope',  $admin->getAuthScope() ?? 'admin'],
                ['roles',       $admin->roles->pluck('name')->implode(', ') ?: '—'],
                ['canMk(*)',    $admin->canMk('*') ? 'yes (super-admin)' : 'no'],
            ],
        );
        $this->newLine();
        $this->line('Login:');
        $this->line("  POST /api/admin/auth/login");
        $this->line('  { "'.$this->loginField.'": "'.$loginFieldValue.'", "password": "<el que tipeaste>" }');

        return self::SUCCESS;
    }

    /**
     * R-PKG-046 F9-B05 — Resolver el valor del login field dinámicamente.
     *
     * Fallback chain:
     *   1. `--{loginField}` flag (e.g. `--ci`, `--email`, `--username`).
     *   2. BC: si `loginField='email'` y `--email` está pineado, usarlo.
     *   3. Prompt interactivo pineado con el nombre del login field.
     *
     * @return string El valor del login field.
     */
    protected function resolveLoginFieldValue(): string
    {
        // Strategy 1: --{loginField} flag.
        $dynamicFlag = $this->option($this->loginField);
        if (! empty($dynamicFlag)) {
            return (string) $dynamicFlag;
        }

        // Strategy 2: BC fallback --email (solo si loginField='email').
        if ($this->loginField === 'email') {
            $emailOption = $this->option('email');
            if (! empty($emailOption)) {
                return (string) $emailOption;
            }
        }

        // Strategy 3: prompt interactivo con nombre del login field.
        return (string) $this->ask("{$this->loginField} del super-admin");
    }

    /**
     * Invoca el `AdminRolesSeeder` scaffoldeado (namespace DDD, R-P-009) si existe.
     *
     * R-PKG-021 BUG-NEW-30 (MEDIUM):
     *  - El seeder scaffoldeado por `mk:make:auth-user Admin --with-crud` vive en
     *    `App\Modules\Admin\Database\Seeders\AdminRolesSeeder` (DDD estricto).
     *  - El namespace NO es `Database\Seeders\AdminRolesSeeder` (eso era un
     *    bug previo silencioso — el comando asumía el namespace global).
     *  - Si el seeder existe, lo invoca. Si no, warning explícito indicando
     *    que las abilities no se asignaron a los roles (solo a users directos).
     *
     * Defense-in-depth: el seeder es idempotente (usa `firstOrCreate` y `sync`
     * sin detach), así que múltiples invocaciones no duplican filas.
     */
    private function seedAdminRolesIfAvailable(): void
    {
        $seederClass = 'App\\Modules\\Admin\\Database\\Seeders\\AdminRolesSeeder';

        if (! class_exists($seederClass)) {
            $this->warn("   ⚠️  Seeder DDD '{$seederClass}' no existe.");
            $this->warn('   Las abilities del role (`ability_role`) NO fueron sembradas — solo los grants directos al user (`ability_user`).');
            $this->warn('   Si querés las abilities asignadas a los roles, scaffoldeá con:');
            $this->warn('     php artisan mk:make:auth-user Admin --with-crud --with-auth-rbac');

            return;
        }

        try {
            /** @var Seeder $seeder */
            $seeder = app($seederClass);
            $seeder->run();
            $this->info('   → AdminRolesSeeder corrió OK (ability_role + roles re-poblados).');
        } catch (\Throwable $e) {
            $this->warn("   ⚠️  AdminRolesSeeder falló: {$e->getMessage()}");
        }
    }
}
