<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Enums\FixedStatus;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Tenancy\Concerns\HasTenantMembership;
use Mk\Director\Tenancy\TenantMembershipGate;
use Symfony\Component\Console\Input\InputOption;

/**
 * `php artisan mk:auth:create-super-admin` — crea el primer usuario
 * super-admin de un scope de auth (default: "admin").
 *
 * **`--scope=` (hallazgo #47 del piloto NetPizza)**: antes estaba clavado a
 * `App\Modules\Admin\Models\Admin` y a `AdminRolesSeeder`, así que un scope
 * que no fuera admin (los operadores de plataforma del piloto) no tenía cómo
 * crear su primer usuario salvo tinker. El modelo se resuelve por el mismo
 * camino por el que `mk.auth:{scope}` resuelve al usuario autenticado:
 * `auth.guards.{scope}.provider` → `auth.providers.{provider}.model` (el
 * scaffolder cablea exactamente eso en `config/auth.php`). Si el guard no está
 * cableado, cae a la convención `App\Modules\{Scope}\Models\{Scope}`. Sin
 * `--scope` es `admin`, como siempre.
 *
 * El command es **no-invasivo** (sprint 2026-06-24):
 *
 *   - Solo crea el usuario si el modelo del scope existe (asumimos que el
 *     consumer ya corrió `mk:make:auth-user {Scope}`). Si no, falla con un
 *     mensaje accionable.
 *   - Es idempotente: si ya hay un usuario con ese login, no hace nada. El
 *     chequeo va SIN global scopes (ver handle()).
 *   - Asigna el rol "super-admin" con guard = el scope (crea la fila en
 *     `roles` si no existe).
 *   - Asigna la ability "*" como grant directo (path `ability_user`).
 *     Esto evita requerir un seeder adicional; el `*` es el wildcard
 *     que mk-director trata como super-admin.
 *   - Roles y abilities se saltean con `--no-roles` (scope sin RBAC) o si
 *     el proyecto no tiene las tablas `roles`/`role_user`.
 *
 * **`--tenant=` (el tenant del primer usuario)**: el comando escribía la fila
 * sin tocar la columna de tenant. En un scope generado con `--multi-tenant` esa
 * columna sale `nullable`, así que el super-admin quedaba con tenant NULO y
 * nadie se enteraba — y un usuario sin tenant es justo el que
 * {@see TenantMembershipGate} deja pasar con CUALQUIER
 * `X-Tenant-ID` (sólo compara si `getTenantId()` no es null). En cuanto el
 * consumidor endurece la columna a `NOT NULL`, el mismo comando muere con un
 * `23502` crudo que no dice qué falta.
 *
 * Por eso, cuando la tabla del scope TIENE columna de tenant
 * ({@see HasTenantMembership::getTenantColumn()},
 * default `client_id`, el mismo que emite `mk:make:auth-user --multi-tenant`):
 *
 *   - `--tenant=<id|slug>` es obligatorio. El valor se valida contra
 *     `mk_director.tenant.model` — por clave primaria primero y por `slug`
 *     después, el mismo camino que usa `TenantResolver::resolveSlugToId()`.
 *   - `--without-tenant` es el opt-in EXPLÍCITO al usuario sin tenant, y sólo
 *     si la columna es nullable. Sale con un aviso que explica el riesgo.
 *   - Si el usuario ya existe con OTRO tenant, el comando se niega: mover a
 *     alguien de tenant no es idempotencia, es un cambio de dueño silencioso.
 *
 * Un scope SIN columna de tenant no cambia en nada (y ahí `--tenant` es un
 * error, no una opción que se descarta en silencio).
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
        {--scope=admin : Scope de auth del usuario (snake_case). El modelo sale de config/auth.php: el provider del guard del scope y el model de ese provider. Default: admin (BC).}
        {--no-roles : Crea SOLO el usuario, sin roles ni abilities. Para scopes que no usan RBAC (ej: generados con --no-rbac).}
        {--tenant= : Id (o slug, vía `mk_director.tenant.model`) del tenant dueño del usuario. OBLIGATORIO cuando la tabla del scope tiene columna de tenant.}
        {--without-tenant : Crea el usuario SIN tenant. Sólo si la columna lo admite, y con aviso: un usuario sin tenant pasa el TenantMembershipGate con CUALQUIER X-Tenant-ID.}
        {--email= : Email del super-admin (omite el prompt; BC para login field=email)}
        {--name= : Nombre (omite el prompt)}
        {--password= : Password en texto plano (omite el prompt; preferir prompt o env en CI)}
        {--roles= : CSV de roles a sembrar en una corrida (omite → solo super-admin). Roles soportados: super-admin, admin, editor, viewer. (R-PKG-014 MEJORA-04)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crea el primer usuario super-admin de un scope (default --scope=admin; role=super-admin, ability=*). Use --roles=super-admin,admin,editor,viewer para sembrar los 4 roles predefinidos. Soporta login field custom (e.g. --ci, --username) pineado por el scaffolder.';

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

        // `configure()` corre ANTES de parsear el input: no se sabe qué `--scope`
        // van a pedir. Se agrega `--{loginField}` para el modelo de CADA guard
        // de config/auth.php (antes sólo el de Admin), así `--scope=mesero --ci=`
        // funciona igual que `--ci=` para el admin.
        foreach ($this->candidateScopeModels() as $modelClass) {
            if (! class_exists($modelClass) || ! is_subclass_of($modelClass, AuthUser::class)) {
                // BC: modelo que no existe todavía → loginField='email'.
                // El handle() hace la verificación estricta y falla limpio.
                continue;
            }

            try {
                $loginField = (new $modelClass)->getLoginField();
            } catch (\Throwable $e) {
                // Si getLoginField() falla (model sin la prop, etc.), BC fallback.
                continue;
            }

            // Solo agregar el flag dinámico si difiere del BC `--email`.
            if ($loginField !== 'email' && ! $this->getDefinition()->hasOption($loginField)) {
                $this->getDefinition()->addOption(
                    new InputOption(
                        name: $loginField,
                        shortcut: null,
                        mode: InputOption::VALUE_OPTIONAL,
                        description: "Valor del login field `{$loginField}` del super-admin (omite el prompt)",
                        default: null,
                    ),
                );
            }
        }
    }

    /**
     * Modelos de todos los guards cableados + el Admin por convención (BC).
     *
     * @return array<int, string>
     */
    private function candidateScopeModels(): array
    {
        $models = ['App\\Modules\\Admin\\Models\\Admin'];

        try {
            $guards = function_exists('config') ? (array) config('auth.guards', []) : [];
        } catch (\Throwable) {
            $guards = [];
        }

        foreach (array_keys($guards) as $guard) {
            $models[] = $this->resolveScopeModel((string) $guard);
        }

        return array_values(array_unique($models));
    }

    /**
     * FQCN del modelo de un scope, por el mismo camino que usa `mk.auth:{scope}`
     * (`Auth::guard($scope)` → provider → model). Cae a la convención del
     * scaffolder si el guard no está cableado en config/auth.php.
     */
    private function resolveScopeModel(string $scope): string
    {
        try {
            $provider = function_exists('config') ? config("auth.guards.{$scope}.provider") : null;
            $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;
        } catch (\Throwable) {
            $model = null;
        }

        if (is_string($model) && $model !== '') {
            return $model;
        }

        $studly = Str::studly($scope);

        return "App\\Modules\\{$studly}\\Models\\{$studly}";
    }

    /**
     * Login field detectado del modelo Admin (R-PKG-046 F9-B05).
     * Default: 'email'. Se sobrescribe en handle() si el modelo override.
     */
    protected string $loginField = 'email';

    /**
     * Scope pedido con `--scope` y tabla de su modelo. Los lee
     * {@see roleAbilitiesMap()}; default admin/admins para subclases que lo
     * llamen antes de `handle()`.
     */
    protected string $scope = 'admin';

    protected string $scopeTable = 'admins';

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
        $scope = $this->scope;
        $resource = $this->scopeTable;

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
        $this->scope = trim((string) $this->option('scope'));
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $this->scope)) {
            $this->error("--scope debe ser un scope en snake_case (ej: admin, operador). Recibido: '{$this->scope}'.");

            return self::FAILURE;
        }

        $modelClass = $this->resolveScopeModel($this->scope);
        if (! class_exists($modelClass) || ! is_subclass_of($modelClass, AuthUser::class)) {
            $studly = Str::studly($this->scope);
            $this->error("No se encontró el modelo del scope '{$this->scope}' ({$modelClass}).");
            $this->newLine();
            $this->line("Antes de crear un super-admin, generá el scope \"{$this->scope}\" con:");
            $this->line("  php artisan mk:make:auth-user {$studly}");
            $this->newLine();
            $this->line("Eso crea app/Modules/{$studly}/Models/{$studly}.php + la migration + el ServiceProvider, y cablea el guard en config/auth.php.");

            return self::FAILURE;
        }

        $this->scopeTable = (new $modelClass)->getTable();

        // R-PKG-046 F9-B05 — Detectar login field del modelo (override per scope).
        // El scaffolder pinea `protected string $loginField = 'ci'` (o 'email', etc.)
        // en el modelo scaffoldeado. Leemos vía `getLoginField()` que ya existe
        // en `AuthUser` desde R-PKG-009 D6.
        $this->loginField = (new $modelClass)->getLoginField();

        // ── El tenant dueño del usuario ──
        // Se resuelve ANTES de pedir el password: si falta el tenant, que se
        // sepa antes de tipear nada, y sin haber escrito una sola fila.
        $tenantColumn = $this->resolveTenantColumn($modelClass);
        $tenantId = $this->resolveTenantId($tenantColumn);
        if ($tenantId === false) {
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

        // 2. Idempotencia: si ya existe un usuario con ese login field value, salir limpio.
        // R-PKG-046 F9-B05 — where() dinámico según loginField.
        //
        // 🔴 SIN global scopes (hallazgo #47). En consola no hay tenant, y con
        // `tenant.fail_closed` el scope agrega `where 1 = 0`: el usuario existe,
        // este chequeo no lo veía, y el insert reventaba contra el unique
        // (`SQLSTATE[23505] admins_email_unique`). La pregunta es "¿esta fila
        // chocaría con el unique del login?", y el unique es de la TABLA: no
        // sabe de tenants ni de soft-deletes. Por eso se apagan TODOS, no sólo
        // el de tenant — una fila soft-deleted con ese email también choca.
        $existing = $modelClass::withoutGlobalScopes()->where($this->loginField, $loginFieldValue)->first();
        if ($existing !== null) {
            // Idempotencia SÍ; cambio de dueño NO. Si la fila que ya está es de
            // otro tenant, re-correr el comando la movería en silencio — y el
            // login es único global, así que "el mismo email en otro tenant" no
            // es un usuario nuevo: es el mismo usuario cambiando de dueño.
            if ($tenantColumn !== null) {
                $existingTenant = $existing->getAttribute($tenantColumn);

                if ((string) $existingTenant !== (string) $tenantId) {
                    $this->error("Ya existe un {$this->scope} con {$this->loginField} {$loginFieldValue}, y su {$tenantColumn} es '".($existingTenant ?? '—')."'.");
                    $this->line("Pediste '".($tenantId ?? '—')."'. El comando NO mueve un usuario de un tenant a otro: si el cambio es intencional, hacelo por el CRUD del scope.");

                    return self::FAILURE;
                }
            }

            $this->warn("Ya existe un {$this->scope} con {$this->loginField} {$loginFieldValue}. No se creó nada.");

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
        if (Schema::hasColumn($this->scopeTable, 'auth_scope')) {
            $createAttrs['auth_scope'] = (new $modelClass)->getAuthScope() ?? $this->scope;
        }

        if ($tenantColumn !== null) {
            $createAttrs[$tenantColumn] = $tenantId;
        }

        /** @var AuthUser $admin */
        $admin = new $modelClass;

        // `forceFill` y no `create()`: el `$fillable` del modelo del scope puede
        // no declarar la columna de tenant (el scaffolder sólo la agrega con
        // `--multi-tenant`, y un consumidor que la agregó a mano a la migración
        // puede habérsela olvidado en el modelo). El mass assignment la
        // descartaría SIN ERROR y la fila volvería a quedar con tenant nulo —
        // exactamente el defecto que este comando ahora impide. Acá los valores
        // no vienen de un request: los arma el propio comando.
        $admin->forceFill($createAttrs)->save();

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
        if (Schema::hasColumn($this->scopeTable, 'is_active')) {
            $admin->is_active = true;
            $admin->save();
        }

        // 4. Asignar roles + abilities a cada uno.
        //
        // Se saltea si lo piden (`--no-roles`: un scope sin RBAC no necesita un
        // `super-admin` con guard propio ensuciando la tabla `roles`) o si el
        // proyecto no tiene las tablas de RBAC — antes eso era un SQLSTATE a
        // mitad de camino, con el usuario ya creado y el comando en rojo.
        $withRoles = ! (bool) $this->option('no-roles');
        if ($withRoles && ! (Schema::hasTable('roles') && Schema::hasTable('role_user'))) {
            $this->warn('   ⚠️  No existen las tablas de RBAC (roles/role_user): se creó el usuario SIN roles ni abilities.');
            $withRoles = false;
        }
        if (! $withRoles) {
            $rolesToSeed = [];
        }

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
        if ($withRoles) {
            $this->seedScopeRolesIfAvailable();
        }

        // Pin el flag `is_fixed` en las filas de sistema que NUNCA deben
        // editarse/eliminarse desde el CRUD: el role `super-admin` y la
        // ability wildcard `*`. Idempotente (mass update por nombre). Se
        // corre solo si super-admin fue parte de la siembra.
        if (in_array('super-admin', $rolesToSeed, true)) {
            Role::query()->where('name', 'super-admin')->update(['is_fixed' => FixedStatus::Fixed->value]);
            Ability::query()->where('name', '*')->update(['is_fixed' => FixedStatus::Fixed->value]);
        }

        $this->newLine();
        $infoVerb = match (true) {
            count($rolesToSeed) > 1 => 'Roles sembrados.',
            $rolesToSeed === [] => 'Usuario creado (sin roles).',
            default => 'Super-admin creado.',
        };
        $this->info('✅ '.$infoVerb);
        $this->table(
            ['Campo', 'Valor'],
            [
                ['id',          (string) $admin->getKey()],
                ['name',        $admin->name],
                [$this->loginField, $admin->{$this->loginField}],
                ['auth_scope',  $admin->getAuthScope() ?? $this->scope],
                // El tenant sólo se muestra si el scope lo tiene: en un scope
                // single-tenant la fila sería ruido, y un "—" se leería como
                // "quedó sin tenant".
                ...($tenantColumn !== null ? [[$tenantColumn, (string) ($tenantId ?? 'null — SIN TENANT')]] : []),
                // Sin RBAC no se consultan: sin las tablas, leerlas es otro SQLSTATE.
                ['roles',       $withRoles ? ($admin->roles->pluck('name')->implode(', ') ?: '—') : '—'],
                ['canMk(*)',    $withRoles && $admin->canMk('*') ? 'yes (super-admin)' : 'no'],
            ],
        );
        $this->newLine();
        $this->line('Login:');
        $this->line("  POST /api/{$this->scope}/auth/login");
        $this->line('  { "'.$this->loginField.'": "'.$loginFieldValue.'", "password": "<el que tipeaste>" }');

        return self::SUCCESS;
    }

    /**
     * Columna de tenant de la tabla del scope, o `null` si el scope no tiene.
     *
     * El nombre sale del modelo (`HasTenantMembership::getTenantColumn()`,
     * default `client_id`) y NO se asume que exista: manda el esquema. Un scope
     * generado sin `--multi-tenant` no tiene la columna y este comando no
     * cambia en nada para él.
     *
     * @param  class-string  $modelClass
     */
    private function resolveTenantColumn(string $modelClass): ?string
    {
        $model = new $modelClass;

        if (! method_exists($model, 'getTenantColumn')) {
            return null;
        }

        $column = (string) $model->getTenantColumn();

        return $column !== '' && Schema::hasColumn($this->scopeTable, $column)
            ? $column
            : null;
    }

    /**
     * Valor a escribir en la columna de tenant, o `false` cuando el comando
     * tiene que abortar (el llamador devuelve FAILURE).
     *
     * Reglas, en orden:
     *  - Sin columna de tenant: `--tenant`/`--without-tenant` son un error
     *    (una opción que no puede aplicarse no se descarta en silencio).
     *  - Con columna: `--tenant` y `--without-tenant` son excluyentes, y falta
     *    alguno de los dos es un error que NOMBRA la opción.
     *  - `--without-tenant` sólo si la columna admite null.
     */
    private function resolveTenantId(?string $tenantColumn): string|int|false|null
    {
        $tenantOption = trim((string) $this->option('tenant'));
        $withoutTenant = (bool) $this->option('without-tenant');

        if ($tenantColumn === null) {
            if ($tenantOption !== '' || $withoutTenant) {
                $this->error("El scope '{$this->scope}' no tiene columna de tenant en la tabla `{$this->scopeTable}`: --tenant / --without-tenant no aplican.");
                $this->line('Si el scope tiene que ser multi-tenant, generalo con `mk:make:auth-user --multi-tenant` (o agregá la columna) antes de anclar a nadie.');

                return false;
            }

            return null;
        }

        if ($tenantOption !== '' && $withoutTenant) {
            $this->error('--tenant y --without-tenant son excluyentes: elegí uno.');

            return false;
        }

        if ($tenantOption === '' && ! $withoutTenant) {
            $this->error("La tabla `{$this->scopeTable}` tiene la columna de tenant `{$tenantColumn}`: hay que decir de qué tenant es este usuario.");
            $this->newLine();
            $this->line('  --tenant=<id|slug>   el tenant dueño del usuario');
            $this->line('  --without-tenant     usuario SIN tenant (sólo si la columna lo admite, y es riesgoso)');

            return false;
        }

        if ($withoutTenant) {
            if (! $this->tenantColumnIsNullable($tenantColumn)) {
                $this->error("`{$this->scopeTable}.{$tenantColumn}` es NOT NULL: no se puede crear un usuario sin tenant. Usá --tenant=<id|slug>.");

                return false;
            }

            $this->warn("⚠️  Se creará el {$this->scope} SIN tenant (`{$tenantColumn}` = null).");
            $this->warn('   Un usuario sin tenant pasa el TenantMembershipGate con CUALQUIER X-Tenant-ID:');
            $this->warn('   el gate sólo compara cuando el usuario TIENE tenant. Es un usuario que ve todos los tenants.');

            return null;
        }

        return $this->lookupTenant($tenantOption);
    }

    /**
     * Resuelve `--tenant` a la clave del tenant: por clave primaria primero y
     * por `slug` después, el mismo camino que `TenantResolver::resolveSlugToId()`.
     *
     * Sin `mk_director.tenant.model` cableado no hay contra qué validar: se usa
     * el valor tal cual, avisando que no se pudo verificar (mejor eso que
     * rechazar a un consumidor que todavía no configuró el modelo).
     *
     * `withoutGlobalScopes()` por la misma razón que el chequeo de idempotencia:
     * en consola no hay contexto de tenant, y un modelo de tenant con scope
     * propio devolvería "no existe" para todos.
     */
    private function lookupTenant(string $value): string|int|false
    {
        $tenantModel = config('mk_director.tenant.model');

        if (! is_string($tenantModel) || ! class_exists($tenantModel)) {
            $this->warn("⚠️  `mk_director.tenant.model` no está configurado: no se pudo verificar que el tenant '{$value}' exista. Se usa tal cual.");

            return $value;
        }

        $row = $tenantModel::withoutGlobalScopes()->whereKey($value)->first();

        if ($row === null && Schema::hasColumn((new $tenantModel)->getTable(), 'slug')) {
            $row = $tenantModel::withoutGlobalScopes()->where('slug', $value)->first();
        }

        if ($row === null) {
            $this->error("No existe el tenant '{$value}' en {$tenantModel} (se buscó por clave primaria y por slug).");

            return false;
        }

        return $row->getKey();
    }

    /**
     * ¿La columna de tenant admite null? Si el driver no sabe contestarlo, se
     * asume que sí: el freno duro lo pone igual la base con su NOT NULL.
     */
    private function tenantColumnIsNullable(string $tenantColumn): bool
    {
        foreach (Schema::getColumns($this->scopeTable) as $column) {
            if (($column['name'] ?? null) === $tenantColumn) {
                return (bool) ($column['nullable'] ?? true);
            }
        }

        return true;
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
        // Strategy 1: --{loginField} flag. `hasOption` porque `configure()` sólo
        // pudo agregarla si el modelo del scope ya existía al construir el comando.
        $dynamicFlag = $this->hasOption($this->loginField) ? $this->option($this->loginField) : null;
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
     * Invoca el `{Scope}RolesSeeder` scaffoldeado (namespace DDD, R-P-009) si existe.
     *
     * R-PKG-021 BUG-NEW-30 (MEDIUM):
     *  - El seeder scaffoldeado por `mk:make:auth-user {Scope}` (CRUD ON, el
     *    default) vive en `App\Modules\{Scope}\Database\Seeders\{Scope}RolesSeeder`
     *    (DDD estricto). Con `--scope=` es el del scope pedido (hallazgo #47).
     *  - El namespace NO es `Database\Seeders\AdminRolesSeeder` (eso era un
     *    bug previo silencioso — el comando asumía el namespace global).
     *  - Si el seeder existe, lo invoca. Si no, warning explícito indicando
     *    que las abilities no se asignaron a los roles (solo a users directos).
     *
     * Defense-in-depth: el seeder es idempotente (usa `firstOrCreate` y `sync`
     * sin detach), así que múltiples invocaciones no duplican filas.
     */
    private function seedScopeRolesIfAvailable(): void
    {
        $studly = Str::studly($this->scope);
        $seederClass = "App\\Modules\\{$studly}\\Database\\Seeders\\{$studly}RolesSeeder";

        if (! class_exists($seederClass)) {
            // Un scope scaffoldeado con --no-crud no tiene RolesSeeder: es normal,
            // no un error. Se avisa qué quedó sembrado y qué no.
            $this->warn("   ⚠️  Seeder DDD '{$seederClass}' no existe.");
            $this->warn('   Las abilities del role (`ability_role`) NO fueron sembradas — solo los grants directos al user (`ability_user`).');
            $this->warn('   Si querés las abilities asignadas a los roles, scaffoldeá el scope con CRUD:');
            $this->warn("     php artisan mk:make:auth-user {$studly}");

            return;
        }

        try {
            /** @var Seeder $seeder */
            $seeder = app($seederClass);
            $seeder->run();
            $this->info("   → {$studly}RolesSeeder corrió OK (ability_role + roles re-poblados).");
        } catch (\Throwable $e) {
            $this->warn("   ⚠️  {$studly}RolesSeeder falló: {$e->getMessage()}");
        }
    }
}
