<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\AuthScopeResolver;

/**
 * `php artisan mk:make:auth-user {Scope}` — scaffolder de un scope de
 * autenticación MK completo.
 *
 * El command NO reemplaza a `mk:module` (que crea módulos CRUD genéricos).
 * Este command es para cuando necesitás un **scope autenticable** —
 * un usuario con login propio, su propia tabla, sus propios endpoints
 * `/api/{scope}/auth/*`, su propio guard, y un `auth_scope` que lo aísla
 * del resto.
 *
 * Genera:
 *   - app/Modules/{Scope}/Models/{Scope}.php              (extends AuthUser)
 *   - app/Modules/{Scope}/Http/Controllers/AuthController.php (login/refresh/logout/me/forgot/reset)
 *   - app/Modules/{Scope}/Http/Routes/api.php              (con prefix /api/{scope}/auth)
 *   - app/Modules/{Scope}/Database/Migrations/{ts}_create_{scope_plural}_table.php
 *   - app/Modules/{Scope}/Providers/{Scope}ServiceProvider.php
 *   - Auto-registra el ServiceProvider en bootstrap/providers.php (Laravel 11+)
 *
 * Lo que NO hace (y debe hacer el dev a mano, decisión consciente):
 *   - Editar config/auth.php: el command imprime los snippets a agregar
 *     (guard + provider), pero no toca el archivo del consumer — modificar
 *     `config/auth.php` es invasivo y se respeta el principio de least surprise.
 *   - Configurar `mk_director.auth.user_model`: el command imprime la sugerencia,
 *     pero el default queda en null hasta que el consumer lo defina.
 *   - Implementar la lógica de login/refresh/forgot/reset: el AuthController
 *     se entrega como skeleton con TODOs explícitos. La integración con
 *     `Mk\Director\Auth\Services\TokenIssuer` queda en manos del consumer.
 *
 * ## Flags
 *
 * - `--login-field=<field>` (R-PKG-009): campo usado para login (default `email`).
 *   Valores comunes: `email`, `ci` (Bolivia), `phone`, `username`, `documento`.
 *
 * - `--with-auth-rbac` (R-PKG-010): integra RBAC + rate limit + audit log
 *   en el AuthController y routes generados. Por defecto el comportamiento
 *   es idéntico a v1.5.0-rc3 (sin RBAC). El flag es opt-in.
 *
 * Spec: MK-LAR-1.0.2 / MK-LAR-1.0.6.
 *
 * @see AuthUser
 * @see AuthScopeResolver
 * @see docs/guides/AUTH.md
 */
class MakeAuthUserCommand extends Command
{
    /**
     * Tabla cerrada de tipos soportados en `--profile-fields=<csv>` (R-PKG-012).
     *
     * Cada tipo define:
     *   - `column_method`: nombre del método Blueprint (string, text, integer, decimal, boolean, date, dateTime, json).
     *   - `column_args`: argumentos extra para el método (e.g. [8, 2] para decimal precision).
     *   - `cast`: entry de `$casts` en el model (null = sin cast, Laravel default).
     *   - `validation`: rules que se inyectan en `register()` y `updateProfile()` del AuthController.
     *
     * Lista cerrada pineada (ADR-004). Tipos case-sensitive (lowercase only, ADR-003).
     *
     * Spec: MK-LAR-1.6.0-rc1.PFT.
     *
     * **BUG-03 fix (v1.6.0-rc4)**: validation default cambió de `required` a `nullable`
     * para ser consistente con la migration (`->nullable()`). Para forzar `required`
     * por profile field, pasar `--profile-fields-required=<csv>` (R-PKG-014).
     *
     * **BUG-09 fix (v1.6.0-rc4)**: prefijo `!` en el CSV marca el field como `unique`
     * (e.g. `--profile-fields=full_name,!ci,phone` → `ci` queda `->unique()->nullable()`).
     * Esto se resuelve en `resolveProfileFields()` + `buildProfileFieldsReplacements()`.
     */
    public const PROFILE_FIELD_TYPES = [
        'string' => [
            'column_method' => 'string',
            'column_args' => [],
            'cast' => null,
            'validation' => ['nullable', 'string', 'max:255'],
        ],
        'text' => [
            'column_method' => 'text',
            'column_args' => [],
            'cast' => null,
            'validation' => ['nullable', 'string'],
        ],
        'int' => [
            'column_method' => 'integer',
            'column_args' => [],
            'cast' => 'integer',
            'validation' => ['nullable', 'integer'],
        ],
        'decimal' => [
            'column_method' => 'decimal',
            'column_args' => [8, 2],
            'cast' => 'decimal:2',
            'validation' => ['nullable', 'numeric'],
        ],
        'bool' => [
            'column_method' => 'boolean',
            'column_args' => [],
            'cast' => 'boolean',
            'validation' => ['nullable', 'boolean'],
        ],
        'date' => [
            'column_method' => 'date',
            'column_args' => [],
            'cast' => 'date',
            'validation' => ['nullable', 'date'],
        ],
        'datetime' => [
            'column_method' => 'dateTime',
            'column_args' => [],
            'cast' => 'datetime',
            'validation' => ['nullable', 'date'],
        ],
        'json' => [
            'column_method' => 'json',
            'column_args' => [],
            'cast' => 'array',
            'validation' => ['nullable', 'array'],
        ],
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:make:auth-user {scope : Nombre del scope en StudlyCase singular (Ej: Member, Customer, Partner)}
        {--login-field=email : Campo usado para login (default: email). BC: si no se pasa, idéntico a v1.4.0. Valores comunes: email, ci, phone, username, documento.}
        {--with-auth-rbac : Habilita RBAC integration (ability checks en /me y /logout), rate limiting en /login, /forgot, /reset, y audit log via AuthEvent (R-PKG-010). Default BC: false. Configurar abilities + rate_limits en config/mk_director.php.}
        {--with-crud : Genera CRUD completo del scope + RBAC triada (AdminController + RoleController + AbilityController + DTOs + Repository + Service + Factory + Seeder + Requests + Resources + ServiceProvider binding). Default BC: false. Ortogonal con --with-auth-rbac, --login-field, --profile-fields. Spec: R-PKG-014.}
        {--profile-fields= : Campos adicionales para el perfil del scope (CSV con sintaxis key[:type], default: ninguno = BC). Cada field se agrega como columna del tipo correspondiente en la tabla del scope, en $fillable del modelo, y se expone en /me + PATCH /me + /register. Sin tipo = string (BC con R-PKG-011). Tipos soportados: string, text, int, decimal, bool, date, datetime, json (R-PKG-012). Ortogonal con --login-field, --with-auth-rbac y --verify-email. Ej: --profile-fields=name,birthdate:date,age:int (R-PKG-011 + R-PKG-012).}
        {--profile-fields-required= : Override del validation default a `required` para profile fields específicos (CSV). Default: ninguno (todos nullable). Ej: --profile-fields-required=full_name,email. Solo aplica si el field está en --profile-fields. (R-PKG-014 BUG-03 fix)}
        {--verify-email : Habilita verificación por email: columna email_verified_at, endpoints /email/verify/<id>/<hash> y /email/resend, dispatch de Illuminate\Auth\Notifications\VerifyEmail en /register. Default BC: false. Aplican cuando --login-field=email (R-PKG-011).}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera un scope de autenticación MK completo: Model (extends AuthUser), migration con auth_scope, AuthController (login/refresh/logout/me/forgot/reset), routes y ServiceProvider auto-registrado. Use --login-field=<field> para campos no-email (RETO: ci, genéricos: phone, username, etc.). Use --with-auth-rbac para integrar ability checks, rate limit y audit log (R-PKG-010). Use --profile-fields=<csv> para columnas adicionales del scope (e.g. dni, phone, birthdate). Use --verify-email para habilitar flujo completo de verificación por email (R-PKG-011).';

    public function handle(): int
    {
        $scope = Str::studly($this->argument('scope'));
        $scopeLower = Str::snake($scope);
        $scopePlural = Str::plural($scopeLower);
        $loginField = $this->resolveLoginField((string) $this->option('login-field'));
        $withAuthRbac = (bool) $this->option('with-auth-rbac');
        $withCrud = (bool) $this->option('with-crud');
        $profileFieldsRaw = $this->resolveProfileFields((string) $this->option('profile-fields'), $loginField);
        $verifyEmailRequested = (bool) $this->option('verify-email');
        $requiredFields = $this->resolveRequiredProfileFields((string) $this->option('profile-fields-required'), $profileFieldsRaw);

        if ($scope === '') {
            $this->error('El nombre del scope no puede estar vacío.');

            return self::FAILURE;
        }

        if ($loginField === null) {
            $this->error('El campo de login debe ser un identificador no-vacío (letras, números, guión bajo).');

            return self::FAILURE;
        }

        if ($profileFieldsRaw === null) {
            // resolveProfileFields() ya imprimió el error específico.
            return self::FAILURE;
        }

        if ($requiredFields === null) {
            // resolveRequiredProfileFields() ya imprimió el error específico.
            return self::FAILURE;
        }

        // Normalize: extract keys (sin prefijo !) para el resto del pipeline.
        // $profileFieldsRaw = ['key' => ['type' => 'string', 'unique' => false], ...]
        // $profileFields = ['key' => 'string', ...]  (formato legacy para PROFILE_FIELD_TYPES lookup)
        $profileFields = [];
        foreach ($profileFieldsRaw as $key => $meta) {
            $profileFields[$key] = $meta['type'];
        }

        // --verify-email solo aplica cuando --login-field=email (no tiene sentido
        // verificar un campo que no es email). Si se pidió con loginField != email,
        // log warning y desactivamos (R-PKG-011 ADR-009 simplificación).
        $isEmail = $loginField === 'email';
        $verifyEmail = $verifyEmailRequested && $isEmail;
        if ($verifyEmailRequested && ! $isEmail) {
            $this->warn("⚠️  --verify-email ignorado: solo aplica cuando --login-field=email (actual: {$loginField}). Si querés verificar {$loginField}, override el AuthController.");
        }

        // Placeholders condicionales (R-PKG-009 D5 + R-PKG-011 D5).
        //
        // Nota sobre `MustVerifyEmail`: el AuthUser base YA implementa esa interface
        // (línea 41 de AuthUser.php), por lo que la subclase NO necesita re-implementarla.
        // Para `loginField != email`, igual la hereda pero queda como interface "muerto"
        // (no se usa). Esto es preferible a sacarla del base (sería BC-breaking).
        //
        // El import `use Illuminate\Contracts\Auth\MustVerifyEmail` y la columna
        // `email_verified_at` solo se incluyen cuando `$verifyEmail` está activo
        // (R-PKG-011 ADR-009). Antes (R-PKG-009) dependía solo de `$isEmail` —
        // refactor para que sea opt-in via flag.

        // Placeholders condicionales (R-PKG-010) para `--with-auth-rbac`.
        //
        // Default mode (sin flag): todos los placeholders RBAC son string vacío
        // → BC preservada con v1.5.0-rc3 (idéntico a la versión sin RBAC).
        //
        // Con `--with-auth-rbac`: cada placeholder se popula con el código
        // correspondiente (ability checks, audit events, rate limit).
        $rbacReplacements = $withAuthRbac
            ? $this->buildRbacReplacements($scopeLower, $loginField)
            : array_fill_keys([
                '{{rbacImports}}',
                '{{rbacConstructor}}',
                '{{rbacAbilityCheckMe}}',
                '{{rbacAbilityCheckLogout}}',
                '{{rbacAuditLoginSuccess}}',
                '{{rbacAuditLoginFailed}}',
                '{{rbacAuditRefreshTodo}}',
                '{{rbacAuditLogout}}',
                '{{rbacAuditForgot}}',
                '{{rbacAuditResetTodo}}',
                '{{rbacAuthorizeAbilityMethod}}',
                '{{rbacLoginThrottle}}',
                '{{rbacForgotThrottle}}',
                '{{rbacResetThrottle}}',
            ], '');

        $loginFieldReplacements = [
            // R-PKG-011: `email_verified_at` column/cast/import ahora depende de
            // --verify-email (no solo de $isEmail). R-PKG-009 los activaba siempre
            // que loginField=email; refactor para opt-in via flag.
            '{{emailVerifiedAtColumn}}' => $verifyEmail
                ? "\$table->timestamp('email_verified_at')->nullable();\n            "
                : '',
            // Cast entry SIN trailing whitespace. El stub del model tiene el
            // `\n        'password'` después. Si --verify-email, queda
            // `[\n        'email_verified_at' => 'datetime',\n        'password'...`.
            // Si no, queda `[\n        'password'...` (line vacía al ppio).
            // Trim final se hace en generateStub() si es necesario.
            '{{emailVerifiedAtCastEntry}}' => $verifyEmail
                ? "        'email_verified_at' => 'datetime',\n"
                : '',
            '{{mustVerifyEmailUse}}' => $verifyEmail
                ? "use Illuminate\\Contracts\\Auth\\MustVerifyEmail;\n"
                : '',
            '{{loginFieldValidationRule}}' => $isEmail
                ? "['required', 'email']"
                : "['required', 'string']",
            // R-PKG-014 BUG-05: login() response incluye profile fields + roles + abilities.
            // Construido dinámicamente según si hay o no --profile-fields.
            // R-PKG-015 BUG-NEW-01+02: pasar $loginField resuelto (no el placeholder
            // {{loginField}}) y armar la estructura de array_merge correctamente para
            // que las keys 'roles'/'abilities' queden DENTRO de 'admin', no como
            // siblings con key numérica.
            '{{loginResponseArray}}' => $this->buildLoginResponseArray($profileFieldsRaw, $loginField),
        ];

        // Placeholders condicionales R-PKG-011: profile fields per-scope + email verification opt-in.
        // R-PKG-014: pasa $profileFieldsRaw (con metadata unique) + $requiredFields.
        $profileFieldsReplacements = $this->buildProfileFieldsReplacements($profileFieldsRaw, $requiredFields);
        $verifyEmailReplacements = $this->buildVerifyEmailReplacements($verifyEmail);

        // R-PKG-014 MEJORA-07: factory DDD helpers cuando --with-crud está activo.
        // Default mode: nada. --with-crud: agregar use HasFactory, trait HasFactory,
        // y override newFactory() que apunta a la factory DDD generada por
        // admin-factory.stub.
        //
        // R-PKG-015 BUG-NEW-06: cuando --with-crud está activo, también generar
        // overrides de `roles()` y `directAbilities()` con FKs explícitas. Sin
        // esto, Eloquent infiere `admin_id` del nombre del modelo y la pivot
        // `role_user` usa `user_id` → `SQLSTATE: no such column: role_user.admin_id`.
        // El polimorfismo via `wherePivot('user_type', static::class)` mantiene
        // la MME (R-MK-001): cada scope tiene su propio modelo, pero comparte
        // las pivots globales del paquete.
        $factoryReplacements = [
            // R-PKG-017 BUG-NEW-24 fix: cuando `--with-crud` está activo, el
            // modelo concreto usa `{$scope}Factory` en `newFactory()`'s return
            // type y `{$scope}Factory::new()` en el body. Sin el `use` import,
            // PHP no resuelve el FQCN desde `App\Modules\{$scope}\Models`
            // (namespace distinto) y cualquier test que use `Admin::factory()`
            // falla con `Class "{$scope}Factory" not found`.
            //
            // Antes solo importaba `HasFactory` trait — el `{$scope}Factory`
            // quedaba sin resolver. Ahora ambos imports se emiten juntos.
            '{{factoryHasFactoryUse}}' => $withCrud
                ? "use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse App\\Modules\\{$scope}\\Database\\Factories\\{$scope}Factory;\n"
                : '',
            '{{factoryHasFactoryTrait}}' => $withCrud
                ? "use HasFactory;\n    "
                : '',
            '{{factoryNewFactoryMethod}}' => $withCrud
                ? <<<PHP


    /**
     * Factory que vive DENTRO del módulo (DDD estricto, R-P-009).
     * Laravel la descubre vía este override. Apunta a
     * `App\\Modules\\{$scope}\\Database\\Factories\\{$scope}Factory`.
     */
    protected static function newFactory(): {$scope}Factory
    {
        return {$scope}Factory::new();
    }
PHP
                : '',
            // R-PKG-015 BUG-NEW-06: FK override para `roles()`.
            // R-PKG-022 BUG-NEW-33: extended with `->using(MkRoleUserPivot::class)`
            // + `MkBelongsToMany::from($relation)` so the auto user_type injection
            // applies out of the box (HALLAZGO-NEW-04: scaffolder should auto-apply
            // well-defined patterns, not just document them).
            //
            // Default: vacío (sin override). --with-crud: override con FK explícita.
            '{{rolesRelationOverride}}' => $withCrud
                ? <<<PHP

    /**
     * Override de `roles()` del trait HasRoles (R-PKG-015 BUG-NEW-06 + R-PKG-022 BUG-NEW-33).
     *
     * Eloquent infiere la foreign key pivot del nombre del modelo (`admin_id`
     * para `App\Modules\Admin\Models\Admin`), pero la pivot `role_user` del
     * paquete usa `user_id`. Sin este override, `syncRoles()` y `assignRoles()`
     * explotan con `no such column: role_user.admin_id`.
     *
     * El `wherePivot('user_type', static::class)` mantiene el polimorfismo: la
     * pivot es global pero cada modelo concreto filtra por su FQCN, respetando
     * MME (R-MK-001) sin necesidad de tablas separadas por scope.
     *
     * R-PKG-022: el `->using(MkRoleUserPivot::class)` registra el custom Pivot
     * class con listener `creating` que setea `user_type` automáticamente. Y
     * `MkBelongsToMany::from(\$relation)` promote la relation a nuestra subclass
     * custom que override `newPivot()` para inyectar `user_type` runtime ANTES
     * de instanciar la pivot (cubre `attach()`, `sync()`, `toggle()`, etc. directo,
     * no solo los helpers del trait). Sin esto, `\$admin->roles()->attach([1])`
     * falla con `SQLSTATE: null value in column "user_type" violates not-null constraint`.
     */
    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        \$relation = \$this->belongsToMany(
            \Mk\Director\Auth\Models\Role::class,
            'role_user',
            'user_id',
            'role_id',
        )
            ->using(\Mk\Director\Auth\Pivots\MkRoleUserPivot::class)
            ->wherePivot('user_type', static::class)
            ->withTimestamps();

        return \Mk\Director\Database\Eloquent\Relations\MkBelongsToMany::from(\$relation);
    }
PHP
                : '',
            // R-PKG-015 BUG-NEW-06: FK override para `directAbilities()`.
            // R-PKG-022 BUG-NEW-33: idem BUG-NEW-33 rationale.
            // Default: vacío. --with-crud: override con FK explícita.
            '{{directAbilitiesRelationOverride}}' => $withCrud
                ? <<<PHP

    /**
     * Override de `directAbilities()` del trait HasAbilities (R-PKG-015 BUG-NEW-06 + R-PKG-022 BUG-NEW-33).
     *
     * Idem rationale que `roles()`: la pivot `ability_user` usa `user_id` pero
     * Eloquent inferiría `admin_id` del nombre del modelo. Sin este override,
     * `syncDirectAbilities()` y `assignDirectAbilities()` explotan.
     *
     * R-PKG-022: ver `roles()` para explicación de `->using(MkAbilityUserPivot::class)`
     * + `MkBelongsToMany::from()`. Aplica idéntico para direct abilities.
     */
    public function directAbilities(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        \$relation = \$this->belongsToMany(
            \Mk\Director\Auth\Models\Ability::class,
            'ability_user',
            'user_id',
            'ability_id',
        )
            ->using(\Mk\Director\Auth\Pivots\MkAbilityUserPivot::class)
            ->wherePivot('user_type', static::class)
            ->withTimestamps();

        return \Mk\Director\Database\Eloquent\Relations\MkBelongsToMany::from(\$relation);
    }
PHP
                : '',
        ];

        // R-PKG-011: register() y updateProfile() se generan condicionalmente.
        // - register(): si hay --profile-fields OR --verify-email (necesario para crear user).
        // - updateProfile(): solo si hay --profile-fields (PATCH /me sin razón si no hay fields custom).
        //
        // R-PKG-014 BUG-04: el rulesPhp base que va al register() INCLUYE `password`
        // además de los profile fields. Esto es porque sin password el user no puede
        // hacer login después. Si el consumer quiere opt-out (e.g. flujo con magic link),
        // override el método register() en la subclase.
        $profileRulesPhp = $profileFieldsReplacements['__rulesPhp__'] ?? '[]';
        unset($profileFieldsReplacements['__rulesPhp__']);

        // R-PKG-014 BUG-04: rulesPhp para register() = profile fields + password base.
        // Para updateProfile(): solo profile fields (password es opcional).
        $passwordRuleForRegister = "        'password' => ['required', 'string', 'min:8', 'max:255'],\n";
        $rulesPhp = $this->mergeRulesPhp($profileRulesPhp, $passwordRuleForRegister);

        if (! empty($profileFields) || $verifyEmail) {
            $profileFieldsReplacements['{{registerMethod}}'] = $this->buildRegisterMethod(
                $scope,
                $scopeLower,
                $loginField,
                $rulesPhp,
                $verifyEmail,
            );
            $profileFieldsReplacements['{{registerRoute}}'] = "\n    Route::post('register', [AuthController::class, 'register']);";
        } else {
            $profileFieldsReplacements['{{registerMethod}}'] = '';
            $profileFieldsReplacements['{{registerRoute}}'] = '';
        }

        if (! empty($profileFields)) {
            $profileFieldsReplacements['{{updateProfileMethod}}'] = $this->buildUpdateProfileMethod(
                $scope,
                $scopeLower,
                $loginField,
                $profileRulesPhp,
            );
            $profileFieldsReplacements['{{updateProfileRoute}}'] = "\n        Route::patch('me', [AuthController::class, 'updateProfile']);";
        } else {
            $profileFieldsReplacements['{{updateProfileMethod}}'] = '';
            $profileFieldsReplacements['{{updateProfileRoute}}'] = '';
        }

        $extraReplacements = array_merge(
            $loginFieldReplacements,
            $rbacReplacements,
            $profileFieldsReplacements,
            $verifyEmailReplacements,
            $factoryReplacements,
        );

        $this->info("🔐 Generando scope de autenticación MK: {$scope}".($withAuthRbac ? ' (with RBAC)' : ''));

        $basePath = app_path("Modules/{$scope}");

        if (File::exists($basePath)) {
            $this->error("El módulo {$scope} ya existe en {$basePath}.");

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('📁 Creando estructura de directorios:');
        $directories = [
            'Models',
            'Http/Controllers',
            'Http/Routes',
            'Database/Migrations',
            'Providers',
        ];
        foreach ($directories as $dir) {
            File::makeDirectory("{$basePath}/{$dir}", 0755, true);
            $this->line("  📁 {$dir}/");
        }

        $this->newLine();
        $this->info('📄 Generando archivos desde stubs:');

        // Capa Auth
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.model.stub', 'Models', "{$scope}.php", $extraReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.migration.stub', 'Database/Migrations', $this->migrationFilename($scopePlural), $extraReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.auth-controller.stub', 'Http/Controllers', 'AuthController.php', $extraReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.routes.stub', 'Http/Routes', 'api.php', $extraReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.service-provider.stub', 'Providers', "{$scope}ServiceProvider.php");

        $this->newLine();
        $this->info('🔌 Auto-registrando ServiceProvider:');
        $this->registerServiceProvider($scope);

        // ── BUG-10 fix (R-PKG-014): check storage:link si disk=public ───
        $this->checkStorageLink();

        // ── BUG-NEW-10 fix (R-PKG-015): check Sanctum instalado ───────────
        // AuthController y AuthUser del paquete usan `HasApiTokens` trait de
        // Sanctum. Si el consumer no lo tiene instalado, el módulo crashea al
        // primer request. Verificamos + avisamos + sugerimos el comando de fix.
        $this->checkSanctumInstalled();

        // ── MEJORA-03 (R-PKG-014): auto-corrrer mk:discover-abilities con --with-auth-rbac ──
        if ($withAuthRbac) {
            $this->newLine();
            $this->info('🔍 Auto-corriendo mk:discover-abilities (MEJORA-03):');
            $this->call('mk:discover-abilities');
        }

        // ── MEJORA-02 / BUG-08 (R-PKG-014): generar CRUD completo si --with-crud ──
        if ($withCrud) {
            $this->generateCrudPack($scope, $scopeLower, $scopePlural, $loginField, $profileFieldsRaw, $requiredFields);
        }

        $this->newLine();
        $this->info("✅ Scope {$scope} generado con el estándar MK-Director:");
        $this->line("   • Model:        app/Modules/{$scope}/Models/{$scope}.php (extends AuthUser, loginField={$loginField})");
        $this->line("   • Migration:    app/Modules/{$scope}/Database/Migrations/{$this->migrationFilename($scopePlural)}");
        $this->line("   • AuthCtrl:     app/Modules/{$scope}/Http/Controllers/AuthController.php".($withAuthRbac ? ' (RBAC enabled)' : ''));
        $this->line("   • Routes:       /api/{$scopeLower}/auth/{login,refresh,logout,me,forgot,reset}".($withAuthRbac ? ' (rate limited)' : ''));
        $this->line("   • ServiceProv:  app/Modules/{$scope}/Providers/{$scope}ServiceProvider.php");
        if ($withCrud) {
            $this->line("   • CRUD:         17 archivos (AdminController + RoleController + AbilityController + DTOs + Repository + Service + Factory + Seeder + Requests + Resources)");
        }

        if ($withAuthRbac) {
            $this->newLine();
            $this->warn('🔐 RBAC integration habilitada. Siguientes pasos:');
            $this->line('   1. Configurar abilities en config/mk_director.php:');
            $this->line("      'abilities' => ['me' => 'auth.{$scopeLower}.me', 'logout' => 'auth.{$scopeLower}.logout'],");
            $this->line('   2. (Opcional) Customizar rate limits via MK_AUTH_RATE_LIMIT_* env vars.');
            $this->line('   3. (Opcional) Publicar config/mk_director.php si necesitás overrides granulares.');
            $this->line("   4. Registrar un listener para Mk\\Director\\Auth\\Events\\AuthEvent si querés audit log.");
        }

        if ($withCrud) {
            $this->newLine();
            $this->warn('📋 CRUD habilitado. Siguientes pasos:');
            $this->line('   1. php artisan migrate');
            $this->line("   2. Configurar abilities en config/mk_director.php (ver discover-abilities output arriba)");
            $this->line("   3. (Opcional) Override de StoreAdminRequest/UpdateAdminRequest para validation custom");
            $this->line("   4. (Opcional) Override de AdminService::beforeCreate() para photo upload logic");
        }

        // Imprimir snippets a mano (no modificar config/auth.php automáticamente)
        $this->newLine();
        $this->printAuthConfigSnippets($scope, $scopeLower, $scopePlural, $loginField);

        return self::SUCCESS;
    }

    /**
     * BUG-10 fix (R-PKG-014): check storage:link si el disk default es 'public'.
     *
     * Si el scaffolder generó un modelo con `photo_path` (vía --profile-fields=photo_path),
     * el consumer necesita `php artisan storage:link` ejecutado. Si no, las URLs
     * de las fotos van a romper. Warn no fatal — el consumer puede usar otro disk.
     */
    protected function checkStorageLink(): void
    {
        $disk = config('mk_director.storage.disk', 'public');

        if ($disk !== 'public') {
            return; // El consumer configuró otro disk — no asummos storage:link.
        }

        if (function_exists('public_path') && file_exists(public_path('storage'))) {
            return; // Ya está linkeado.
        }

        $this->newLine();
        $this->warn('⚠️  BUG-10: storage:link no ejecutado. Si usás photo_path en --profile-fields,');
        $this->line('   ejecutá `php artisan storage:link` o configurá `mk_director.storage.disk` con otro disk.');
    }

    /**
     * BUG-NEW-10 fix (R-PKG-015) + R-PKG-016 drift fix: verifica que `laravel/sanctum` esté instalado.
     *
     * El paquete usa `HasApiTokens` de Sanctum en `AuthUser` y emite tokens vía
     * `Mk\Director\Auth\Services\TokenIssuer`. Si el consumer no tiene Sanctum
     * instalado, el módulo crashea al primer request con
     * `Trait "Laravel\Sanctum\HasApiTokens" not found`.
     *
     * Además, si el consumer usa `HasUuids`, la migration default de Sanctum
     * (con `morphs()` bigint) va a romper al insertar el primer token
     * (SQLSTATE 22P02). Sugerimos correr `mk:fix:sanctum-uuids` después de
     * `php artisan install:api`.
     *
     * R-PKG-016 BUG-NEW-10 drift fix: en RETO fase 3, el comando seguía reportando
     * "BUG-NEW-10: laravel/sanctum no está instalado" DESPUÉS de que el consumer
     * corriera `composer require laravel/sanctum`. Causa: `class_exists()` falla
     * si el autoloader de Composer todavía no regeneró el classmap (común
     * cuando el scaffolder corre en la misma sesión que `composer require`
     * sin un `composer dump-autoload` intermedio). Fix: agregar fallback via
     * `file_exists()` en `vendor/laravel/sanctum` antes de considerar
     * "Sanctum no instalado". Si la carpeta existe, Sanctum SÍ está instalado
     * aunque el autoloader no haya picked up aún.
     *
     * Warn no fatal — el consumer puede usar otro package de tokens.
     */
    protected function checkSanctumInstalled(): void
    {
        $sanctumInstalled = $this->isSanctumInstalled();

        if ($sanctumInstalled) {
            // Sanctum instalado. Verificar si la migration usa uuidMorphs (R-PKG-015 BUG-NEW-09).
            $migrationsPath = function_exists('database_path') ? database_path('migrations') : null;
            if ($migrationsPath !== null && is_dir($migrationsPath)) {
                $needsUuidFix = false;
                foreach (glob($migrationsPath.'/*_create_personal_access_tokens_table.php') ?: [] as $migrationFile) {
                    $content = file_get_contents($migrationFile);
                    if (str_contains($content, "\$table->morphs('tokenable')")
                        && ! str_contains($content, "\$table->uuidMorphs('tokenable')")) {
                        $needsUuidFix = true;
                        break;
                    }
                }
                if ($needsUuidFix) {
                    $this->newLine();
                    $this->warn('⚠️  BUG-NEW-09: tu migration de Sanctum usa `morphs()` (bigint).');
                    $this->line('   Si tu modelo AuthUser usa `HasUuids` (UUID en `$table->id`),');
                    $this->line('   corré:');
                    $this->line('     php artisan mk:fix:sanctum-uuids');
                    $this->line('   ANTES de `php artisan migrate`.');
                }
            }

            return;
        }

        $this->newLine();
        $this->warn('⚠️  BUG-NEW-10: `laravel/sanctum` no está instalado en este proyecto.');
        $this->line('   El paquete `makroz/director-laravel` usa `HasApiTokens` de Sanctum');
        $this->line('   para emitir access/refresh tokens. Sin Sanctum, el módulo crashea');
        $this->line('   con `Trait "Laravel\\Sanctum\\HasApiTokens" not found`.');
        $this->newLine();
        $this->line('   Instalá Sanctum manualmente:');
        $this->line('     composer require laravel/sanctum:^4.3');
        $this->line('     php artisan install:api --no-interaction');
        $this->newLine();
        $this->line('   O, si tu modelo AuthUser usa UUIDs, agregá este paso extra:');
        $this->line('     php artisan mk:fix:sanctum-uuids');
    }

    /**
     * Detecta si Sanctum está instalado, con fallback robusto (R-PKG-016).
     *
     * Strategy:
     *   1. `class_exists(HasApiTokens::class)` — primary (autoloader debe estar al día).
     *   2. `file_exists(vendor/laravel/sanctum/composer.json)` — fallback para casos
     *      donde el consumer acaba de correr `composer require laravel/sanctum` pero
     *      el autoloader todavía no regeneró el classmap (R-PKG-016 BUG-NEW-10 drift).
     *
     * @return bool true si Sanctum está instalado por CUALQUIERA de las dos vías.
     */
    protected function isSanctumInstalled(): bool
    {
        if (class_exists(\Laravel\Sanctum\HasApiTokens::class)) {
            return true;
        }

        // Fallback: ¿está la carpeta vendor/? (composer require corrió pero
        // el autoloader todavía no picked up).
        $vendorPath = function_exists('base_path')
            ? base_path('vendor/laravel/sanctum/composer.json')
            : null;

        return $vendorPath !== null && file_exists($vendorPath);
    }

    /**
     * MEJORA-02 / BUG-08 (R-PKG-014): genera el CRUD completo del scope.
     *
     * Genera 17 archivos adicionales sobre la base ya creada por el scaffolder auth:
     *   - AdminController, RoleController, AbilityController (3)
     *   - StoreAdminRequest, UpdateAdminRequest, AssignRolesRequest, AssignDirectAbilitiesRequest (4)
     *   - AdminResource, RoleResource, AbilityResource (3)
     *   - AdminData, AdminFilterData DTOs (2)
     *   - AdminRepository + Contracts/AdminRepositoryInterface (2)
     *   - AdminService (1)
     *   - AdminFactory (1)
     *   - AdminRolesSeeder (1)
     *
     * Ortogonal con --with-auth-rbac, --login-field, --profile-fields.
     * Las rutas CRUD se agregan al routes stub existente.
     */
    protected function generateCrudPack(
        string $scope,
        string $scopeLower,
        string $scopePlural,
        string $loginField,
        array $profileFields,
        array $requiredFields,
    ): void {
        $this->newLine();
        $this->info('📄 Generando CRUD pack (17 archivos):');

        $basePath = app_path("Modules/{$scope}");

        // Crear directorios adicionales.
        $crudDirs = [
            'DTOs',
            'Repositories/Contracts',
            'Repositories',
            'Services',
            'Http/Requests',
            'Http/Resources',
            'Database/Factories',
            'Database/Seeders',
        ];
        foreach ($crudDirs as $dir) {
            if (! File::exists("{$basePath}/{$dir}")) {
                File::makeDirectory("{$basePath}/{$dir}", 0755, true);
                $this->line("  📁 {$dir}/");
            }
        }

        // ── Extra replacements para los stubs CRUD ──
        // Necesitan saber qué profile fields existen (para AdminResource, AdminData, etc.)
        // y cuáles son unique (para StoreAdminRequest / UpdateAdminRequest).
        $uniqueRules = $this->buildUniqueRules($profileFields, $requiredFields, $scopePlural);
        $crudReplacements = [
            '{{profileFieldsList}}' => $this->buildProfileFieldsList($profileFields),
            '{{profileFieldsFillable}}' => $this->buildProfileFieldsFillable($profileFields),
            '{{profileFieldsUniqueRules}}' => $uniqueRules['store'],
            '{{profileFieldsUniqueRulesUpdate}}' => $uniqueRules['update'],
            '{{loginFieldValidationRule}}' => $loginField === 'email'
                ? "['required', 'email', 'max:255', 'unique:{$scopePlural},{$loginField}']"
                : "['required', 'string', 'max:255', 'unique:{$scopePlural},{$loginField}']",
        ];

        // ── Controllers (3) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-controller.stub', 'Http/Controllers', "{$scope}Controller.php", $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/role-controller.stub', 'Http/Controllers', 'RoleController.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/ability-controller.stub', 'Http/Controllers', 'AbilityController.php', $crudReplacements);

        // ── Requests (4) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/store-admin-request.stub', 'Http/Requests', 'StoreAdminRequest.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/update-admin-request.stub', 'Http/Requests', 'UpdateAdminRequest.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/assign-roles-request.stub', 'Http/Requests', 'AssignRolesRequest.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/assign-abilities-request.stub', 'Http/Requests', 'AssignDirectAbilitiesRequest.php', $crudReplacements);

        // ── Resources (3) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-resource.stub', 'Http/Resources', 'AdminResource.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/role-resource.stub', 'Http/Resources', 'RoleResource.php', $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/ability-resource.stub', 'Http/Resources', 'AbilityResource.php', $crudReplacements);

        // ── DTOs (2) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-data-dto.stub', 'DTOs', "{$scope}Data.php", $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-filter-dto.stub', 'DTOs', "{$scope}FilterData.php", $crudReplacements);

        // ── Repository + Interface (2) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-repository.stub', 'Repositories', "{$scope}Repository.php", $crudReplacements);
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-repository-interface.stub', 'Repositories/Contracts', "{$scope}RepositoryInterface.php", $crudReplacements);

        // ── Service (1) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-service.stub', 'Services', "{$scope}Service.php", $crudReplacements);

        // ── Factory (1) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-factory.stub', 'Database/Factories', "{$scope}Factory.php", $crudReplacements);

        // ── Seeder (1) ──
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user/admin-roles-seeder.stub', 'Database/Seeders', "{$scope}RolesSeeder.php", $crudReplacements);

        // ── Extender routes/api.php con CRUD ──
        $this->extendRoutesWithCrud($basePath, $scope, $scopeLower, $scopePlural);

        // ── Extender ServiceProvider con Repository binding ──
        $this->extendServiceProviderWithBinding($basePath, $scope);
    }

    /**
     * Helper: genera la lista CSV de profile field keys (sin tipos) para inyectar
     * en stubs que necesitan solo los nombres (e.g. AdminData constructor).
     */
    protected function buildProfileFieldsList(array $profileFields): string
    {
        return implode(', ', array_map(
            static fn ($key) => "'{$key}'",
            array_keys($profileFields),
        ));
    }

    /**
     * Helper: genera declaraciones de parámetros del constructor de AdminData DTO.
     *
     * Formato: `public ?string $fullName = null,` (8 spaces indent).
     * El AdminData tiene `name` y `email` hardcoded en su constructor; este helper
     * emite solo los profile fields adicionales.
     *
     * Mapea tipos PHP:
     *   - string, text → `?string`
     *   - int → `?int`
     *   - decimal → `?float`
     *   - bool → `?bool`
     *   - date, datetime → `?\Carbon\Carbon`
     *   - json → `?array`
     */
    protected function buildProfileFieldsFillable(array $profileFields): string
    {
        $out = '';
        foreach ($profileFields as $key => $meta) {
            $type = $meta['type'];
            $phpType = match ($type) {
                'string', 'text' => '?string',
                'int' => '?int',
                'decimal' => '?float',
                'bool' => '?bool',
                'date', 'datetime' => '?\\Carbon\\Carbon',
                'json' => '?array',
                default => '?mixed',
            };

            // Camel case para el nombre del parámetro: full_name → fullName.
            $paramName = lcfirst(str_replace('_', '', ucwords($key, '_')));
            $out .= "        public {$phpType} \${$paramName} = null,\n";
        }

        return $out;
    }

    /**
     * Helper: genera rules unique para StoreAdminRequest / UpdateAdminRequest.
     *
     * StoreAdminRequest: `'key' => ['nullable', 'string', 'unique:table,key']`.
     * UpdateAdminRequest: `'key' => ['sometimes', 'nullable', 'string', Rule::unique(...)->ignore($id)]`.
     *
     * Solo emite reglas para fields que tengan `unique` en su metadata
     * (prefijo `!` en --profile-fields).
     *
     * @param  array<string, array{type: string, unique: bool}>  $profileFields
     * @param  array<string, bool>  $requiredFields
     * @param  string  $scopePlural  Nombre de la tabla del scope (e.g. `admins`).
     * @return array{store: string, update: string}
     */
    protected function buildUniqueRules(array $profileFields, array $requiredFields, string $scopePlural): array
    {
        $store = '';
        $update = '';
        foreach ($profileFields as $key => $meta) {
            $unique = $meta['unique'];
            $isRequired = isset($requiredFields[$key]);

            // Si NO es unique, no emitir.
            if (! $unique) {
                continue;
            }

            $requiredRule = $isRequired ? 'required' : 'nullable';
            $stringRule = 'string';

            // Store: simple unique sin ignore.
            $store .= "            '{$key}' => ['{$requiredRule}', '{$stringRule}', 'unique:{$scopePlural},{$key}'],\n";

            // Update: con Rule::unique()->ignore() del row actual.
            // El caller resuelve el id desde el route param.
            $update .= "            '{$key}' => ['sometimes', '{$requiredRule}', '{$stringRule}', \\Illuminate\\Validation\\Rule::unique('{$scopePlural}', '{$key}')->ignore(\$id)],\n";
        }

        return [
            'store' => $store,
            'update' => $update,
        ];
    }

    /**
     * Extiende routes/api.php con los endpoints CRUD del scope.
     * Lee el archivo generado por `auth-user.routes.stub` y agrega antes del cierre del group.
     *
     * R-PKG-016 BUG-NEW-13 fix: el código previo insertaba el CRUD stub COMPLETO
     * (con `<?php` opener + `use` statements + body) ANTES del cierre del último
     * grupo del routes principal. Esto dejaba DOS bloques PHP en el archivo
     * final (uno al inicio, otro en la mitad), y `loadRoutesFrom` crasheaba con
     * `ReflectionException: Class "AdminController" does not exist` porque
     * los `use` statements del segundo bloque no resolvían el FQCN.
     *
     * La fix correcta: extraer los `use` statements del CRUD stub e inyectarlos
     * al inicio del `routes/api.php` del scope (después del primer `<?php`),
     * y luego insertar SOLO el cuerpo de las rutas (sin `<?php` opener ni
     * `use` statements) ANTES del cierre del último grupo del routes principal.
     *
     * R-PKG-017 BUG-NEW-21 fix (REGRESIÓN del fix anterior): el `routes/api.php`
     * generado por `auth-user.routes.stub` tiene la estructura:
     *
     *     <?php
     *
     *     declare(strict_types=1);
     *
     *     use App\Modules\...\Http\Controllers\AuthController;
     *     ...
     *
     * `declare(strict_types=1)` DEBE ser la primera instrucción del archivo
     * (PHP strict). El fix anterior inyectaba los `use` statements NUEVOS
     * DESPUÉS del `<?php` opener y ANTES del `declare`, dejando el archivo así:
     *
     *     <?php
     *
     *     use App\Modules\Admin\Http\Controllers\AbilityController;  ← inyectado
     *     use App\Modules\Admin\Http\Controllers\RoleController;       ← inyectado
     *     declare(strict_types=1);                                     ← YA NO es la 1ra instrucción → PHP Fatal error
     *
     * El fix detecta si el archivo tiene `declare(strict_types=1)` (o cualquier
     * `declare(...)`) inmediatamente después del `<?php` opener (con whitespace
     * entre medio). Si lo hay, inserta los `use` statements DESPUÉS del bloque
     * `declare`, no antes. Si no hay `declare`, los inserta después del `<?php\n\n`
     * como antes. BC-safe: solo cambia el ordenamiento cuando el `declare` está
     * presente, que es lo que el scaffolder actual SIEMPRE emite.
     *
     * Esto produce UN solo bloque PHP en el archivo final con todos los
     * `use` statements consolidados (estilo PSR-12) + las rutas CRUD en el lugar
     * correcto + `declare(strict_types=1)` respetando la primera-instrucción rule.
     */
    protected function extendRoutesWithCrud(string $basePath, string $scope, string $scopeLower, string $scopePlural): void
    {
        $routesPath = "{$basePath}/Http/Routes/api.php";
        if (! File::exists($routesPath)) {
            $this->warn("   ⚠️  routes/api.php no existe, no se pudo extender con CRUD.");

            return;
        }

        $stubPath = __DIR__.'/../../Stubs/auth-user/auth-user.routes.with-crud.stub';
        if (! File::exists($stubPath)) {
            $this->warn("   ⚠️  auth-user.routes.with-crud.stub no existe.");

            return;
        }

        $content = File::get($routesPath);
        $crudStub = File::get($stubPath);
        $crudStub = str_replace('{{ModuleName}}', $scope, $crudStub);
        $crudStub = str_replace('{{moduleNameLower}}', $scopeLower, $crudStub);
        $crudStub = str_replace('{{moduleNamePluralLower}}', $scopePlural, $crudStub);

        // ── 1) Extraer `use` statements del CRUD stub ──
        // Pattern: `use App\Modules\...;` (una por línea, con o sin \).
        // Capturamos solo líneas que comienzan con `use ` y terminan en `;`.
        preg_match_all('/^use\s+[^;]+;\s*$/m', $crudStub, $useMatches);
        $useStatements = $useMatches[0];

        if (! empty($useStatements)) {
            // Inyectar los `use` statements al inicio del routes/api.php,
            // después del primer `<?php` opener. Si ya existe un `use` con
            // el mismo FQCN, no duplicarlo.
            $newUseLines = [];
            foreach ($useStatements as $useLine) {
                // Extraer el FQCN (entre `use` y `;`) para detectar duplicados.
                if (preg_match('/^use\s+([^;]+);/', $useLine, $fqcnMatch)) {
                    $fqcn = trim($fqcnMatch[1]);
                    if (! str_contains($content, "use {$fqcn};")) {
                        $newUseLines[] = $useLine;
                    }
                }
            }

            if (! empty($newUseLines)) {
                $useBlock = implode("\n", $newUseLines);

                // R-PKG-017 BUG-NEW-21 fix: detectar si el archivo tiene un
                // bloque `declare(...)` (ej: `declare(strict_types=1);`) entre
                // el `<?php` opener y el primer `use`. Si lo hay, insertar los
                // `use` statements NUEVOS DESPUÉS del bloque `declare`, no antes,
                // para no romper la regla PHP "declare must be the first
                // statement".
                //
                // Pattern: `<?php\n\ndeclare(strict_types=1);\n\n` o variantes
                // con whitespace flexible.
                $declarePattern = '/^(<\?php[ \t]*\R)((?:[ \t]*\R)*)(declare\s*\([^)]+\)\s*;\s*\R)((?:[ \t]*\R)*)/m';
                if (preg_match($declarePattern, $content, $declareMatch, PREG_OFFSET_CAPTURE)) {
                    // Insertar después del bloque `declare` (después de su
                    // newline final + cualquier whitespace siguiente).
                    $insertPos = $declareMatch[0][1] + strlen($declareMatch[0][0]);
                    $content = substr($content, 0, $insertPos).$useBlock."\n".substr($content, $insertPos);
                } elseif (preg_match('/^<\?php\s*\R/', $content, $phpMatch, PREG_OFFSET_CAPTURE)) {
                    // Sin `declare`: insertar después del `<?php\n` opener
                    // (como en R-PKG-016 BUG-NEW-13).
                    $insertPos = $phpMatch[0][1] + strlen($phpMatch[0][0]);
                    $content = substr($content, 0, $insertPos).$useBlock."\n".substr($content, $insertPos);
                } else {
                    // No `<?php` opener (raro pero defensivo): prepend.
                    $content = "<?php\n\n".$useBlock."\n\n".$content;
                }
            }
        }

        // ── 2) Remover `<?php` opener y `use` statements del CRUD stub ──
        // Dejar solo el cuerpo de las rutas (los `Route::xxx(...)` calls).
        $crudBody = preg_replace('/^<\?php\s*\n/', '', $crudStub);
        $crudBody = preg_replace('/^use\s+[^;]+;\s*\n/m', '', $crudBody);

        // ── 3) Insertar el cuerpo de las rutas ANTES del cierre del último grupo ──
        $content = preg_replace(
            '/(Route::middleware\([^)]*\)[^)]*->group\(function \(\) \{[\s\S]*?\n\}\);)\s*$/m',
            "$1\n{$crudBody}",
            $content,
            1,
            $count,
        );

        if ($count === 0) {
            // Fallback: append al final.
            $content .= "\n{$crudBody}";
        }

        File::put($routesPath, $content);
        $this->line('   ✅ Http/Routes/api.php (extended with CRUD, single PHP block, declare preserved)');
    }

    /**
     * Extiende el ServiceProvider del scope con binding del Repository.
     */
    protected function extendServiceProviderWithBinding(string $basePath, string $scope): void
    {
        $providerPath = "{$basePath}/Providers/{$scope}ServiceProvider.php";
        if (! File::exists($providerPath)) {
            $this->warn("   ⚠️  ServiceProvider no existe, no se pudo extender con binding.");

            return;
        }

        $content = File::get($providerPath);
        $binding = "        \$this->app->bind(\n            \\App\\Modules\\{$scope}\\Repositories\\Contracts\\{$scope}RepositoryInterface::class,\n            \\App\\Modules\\{$scope}\\Repositories\\{$scope}Repository::class,\n        );";

        // Insertar después de `public function register(): void\n    {\n`
        $content = preg_replace(
            '/(public function register\(\): void\s*\{\s*)\/\//',
            "$1{$binding}\n\n        //",
            $content,
            1,
            $count,
        );

        if ($count === 0) {
            // Fallback: insertar después de `public function register(): void {`
            $content = preg_replace(
                '/(public function register\(\): void\s*\{\s*)/',
                "$1\n{$binding}\n",
                $content,
                1,
            );
        }

        File::put($providerPath, $content);
        $this->line('   ✅ Providers/'.$scope.'ServiceProvider.php (extended with Repository binding)');
    }

    /**
     * Construye el array_merge dinámico para el response de login() (R-PKG-014 BUG-05).
     *
     * Sin profile fields: `$user->only([id, name, login]) + ['roles' => ..., 'abilities' => ...]`
     * Con profile fields: `array_merge($user->only([id, name, login]), $user->only([profile fields]), ['roles' => ..., 'abilities' => ...])`
     *
     ** R-PKG-015 BUG-NEW-01: el `,` después de `$base` quedaba AFUERA del `array_merge`
     * en la versión anterior, generando que PHP interpretara el sub-array como un
     * sibling del array padre (key `0`). Ahora el `['roles' => ..., 'abilities' => ...]`
     * queda SIEMPRE como último argumento del `array_merge` (DENTRO de los paréntesis)
     * — o como `+` cuando no hay profile fields, para mantener el shape consistente.
     *
     * R-PKG-015 BUG-NEW-02: el placeholder `{{loginField}}` se generaba literal porque
     * esta función se ejecuta ANTES del `str_replace('{{loginField}}', $loginField, ...)`
     * en `generateStub()`. Fix: pasar `$loginField` ya resuelto y emitir el valor directo.
     *
     * Output es PHP válido listo para inyectar en el stub.
     */
    protected function buildLoginResponseArray(array $profileFieldsRaw, string $loginField): string
    {
        $baseFields = "['id', 'name', '{$loginField}']";

        if (empty($profileFieldsRaw)) {
            // Sin profile fields: `$user->only([id, name, login]) + ['roles' => ..., 'abilities' => ...]`
            $profileKeys = [];
        } else {
            // Con profile fields: `array_merge($user->only([id, name, login]), $user->only([profile fields]), ['roles' => ..., 'abilities' => ...])`
            $profileKeys = array_keys($profileFieldsRaw);
        }

        // R-PKG-015 BUG-NEW-01: el sub-array de roles/abilities DEBE estar DENTRO
        // del array_merge como último argumento (precedido por `, `, no por `)`).
        $subArray = "[\n"
            ."            'roles' => \$user->roles->map(fn (\$r) => ['id' => \$r->id, 'name' => \$r->name]),\n"
            ."            'abilities' => \$user->abilities->pluck('name')\n"
            ."                ->merge(\$user->directAbilities->pluck('name'))\n"
            ."                ->unique()\n"
            ."                ->values(),\n"
            .'        ]';

        if (empty($profileKeys)) {
            // Sin profile fields: usar `+` para preservar keys de $user->only si coinciden
            // (prácticamente imposible porque solo emitimos id/name/loginField, pero es
            // defensivo). Más limpio que `array_merge` con un solo argumento.
            return "\$user->only({$baseFields}) + {$subArray}";
        }

        // Con profile fields: array_merge(..., ..., [...]) — el sub-array es el último
        // argumento DENTRO de los paréntesis. NO usar `{$baseExpression}, {$subArray}`
        // porque eso dejaría `, [...]` AFUERA del array_merge (el bug original).
        $profileFieldsArray = "['".implode("', '", $profileKeys)."']";
        return "array_merge(\$user->only({$baseFields}), \$user->only({$profileFieldsArray}), {$subArray})";
    }

/**
     * Helper: merge de dos arrays de rules PHP en formato string.
     *
     * Input: ambos strings como `'field' => ['rule'],...` arrays.
     * Output: array PHP válido combinando ambos, formateado en multi-línea para legibilidad.
     *
     * Usado por `buildRegisterMethod()` para combinar profile fields + password base.
     */
    protected function mergeRulesPhp(string $existing, string $additional): string
    {
        // Si existing está vacío, retornar solo additional envuelto.
        if (trim($existing) === '[]' || trim($existing) === '') {
            return '['.rtrim($additional).']';
        }

        // Parsear el existing (formato: ['key' => ['rule1', 'rule2'], ...]).
        // Usamos eval() controlado porque la salida viene del propio builder
        // (no es user input). Esto es seguro en este contexto porque:
        //   1. `$existing` viene de `phpArrayExport()` que solo emite arrays literales.
        //   2. `$additional` viene de la constante hardcoded.
        // Si en el futuro queremos ser más estrictos, podemos parsear manualmente.
        // Por ahora, regeneramos el array completo en formato multiline.
        $existingArray = eval('return '.$existing.';');
        $additionalArray = eval('return ['.rtrim($additional).'];');

        if (! is_array($existingArray)) {
            return $existing;
        }
        if (! is_array($additionalArray)) {
            return $existing;
        }

        $merged = array_merge($existingArray, $additionalArray);

        // Formatear en multi-línea: 8 espacios indent, un field por línea.
        $out = "[\n";
        foreach ($merged as $key => $value) {
            $valueStr = $this->phpArrayExport([$key => $value]);
            // phpArrayExport returns `['key' => ['rule']]`. Extract the inner.
            preg_match("/^\[('[^']+'\s*=>\s*\[.*?\])\]$/s", $valueStr, $matches);
            if (isset($matches[1])) {
                $out .= '        '.$matches[1].",\n";
            }
        }
        $out .= '    ]';

        return $out;
    }

    protected function migrationFilename(string $scopePlural): string
    {
        return now()->format('Y_m_d_His')."_create_{$scopePlural}_table.php";
    }

    protected function generateStub(
        string $scope,
        string $scopeLower,
        string $scopePlural,
        string $loginField,
        string $stubName,
        string $folder,
        string $fileName,
        array $extraReplacements = [],
    ): void {
        $stubPath = __DIR__.'/../../Stubs/'.$stubName;

        if (! File::exists($stubPath)) {
            $this->error("  ❌ Stub no encontrado: {$stubPath}");

            return;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}', $scope, $content);
        $content = str_replace('{{moduleNameLower}}', $scopeLower, $content);
        $content = str_replace('{{moduleNamePluralLower}}', $scopePlural, $content);
        $content = str_replace('{{loginField}}', $loginField, $content);
        $content = str_replace('{{migrationDate}}', now()->format('Y_m_d_His'), $content);

        // Placeholders condicionales (R-PKG-009 + R-PKG-010). Si el stub no usa alguno,
        // el str_replace no hace nada (string vacío o key ausente).
        foreach ($extraReplacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        $targetPath = app_path("Modules/{$scope}/{$folder}/{$fileName}");
        File::put($targetPath, $content);

        $displayName = ! empty($folder) ? "{$folder}/{$fileName}" : $fileName;
        $this->line("   ✅ {$displayName}");
    }

    /**
     * Resuelve y valida el campo de login pasado via --login-field.
     *
     * Reglas (R-PKG-009 D1+D4):
     *   - Solo letras, números y guión bajo. NO espacios, NO guiones.
     *   - Vacío o ausente → default `email` (BC con v1.4.0).
     *   - El stub de model decide si implementar `MustVerifyEmail` según el campo.
     *
     * @return string|null Nombre del campo validado, o null si inválido.
     */
    protected function resolveLoginField(string $raw): ?string
    {
        $field = trim($raw);

        if ($field === '') {
            return 'email';
        }

        // Solo identificador PHP-style (sin guión, espacio, caracteres especiales).
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
            return null;
        }

        return $field;
    }

    /**
     * Construye los placeholders RBAC (R-PKG-010) cuando `--with-auth-rbac` está activo.
     *
     * Cada placeholder se reemplaza por el bloque de código correspondiente:
     *   - imports adicionales (AbilityResolver, AuthEvent, AuthorizationException)
     *   - constructor con AbilityResolver inyectado (opcional via container)
     *   - ability checks en /me y /logout (configurables por endpoint)
     *   - audit events (AuthEvent::dispatch) en login success/fail, logout, forgot
     *   - rate limit middleware en /login, /forgot, /reset
     *   - helper method `authorizeAbility()` que delega a AbilityResolver
     *
     * Default values se leen de `config('mk_director.auth.*')` con fallback
     * seguro (idempotente con valores del package default).
     *
     * @return array<string, string>
     */
    protected function buildRbacReplacements(string $scopeLower, string $loginField): array
    {
        // Login field key for audit event payloads.
        $loginFieldKey = $loginField; // 'email', 'ci', etc.

        return [
            // ── Imports adicionales ─────────────────────────────────────
            '{{rbacImports}}' => "use Illuminate\\Auth\\Access\\AuthorizationException;\n".
                                 "use Mk\\Director\\Auth\\Events\\AuthEvent;\n".
                                 "use Mk\\Director\\Auth\\Services\\AbilityResolver;\n",

            // ── Constructor con AbilityResolver inyectado ───────────────
            '{{rbacConstructor}}' => <<<'PHP'
    /**
     * Resolver de abilities (cache + Sanctum short-circuit). Se inyecta
     * por container o se resuelve via `app()` para mantener compatibilidad
     * con tests que no bootean Laravel completo.
     */
    protected ?AbilityResolver $abilityResolver = null;

    public function __construct(?AbilityResolver $abilityResolver = null)
    {
        $this->abilityResolver = $abilityResolver
            ?? (function_exists('app') ? app(AbilityResolver::class) : null);
    }

PHP,

            // ── Ability check en /me ────────────────────────────────────
            // R-PKG-015 OBS-NEW-02: indentación correcta (8 espacios, no 4).
            // El stub ya provee 8 espacios antes del placeholder; emitimos 0
            // adicionales para que el código quede alineado con el resto del método.
            '{{rbacAbilityCheckMe}}' => "        \$this->authorizeAbility('me', \$request->user());\n",

            // ── Ability check en /logout ────────────────────────────────
            // R-PKG-015 OBS-NEW-02: idem.
            '{{rbacAbilityCheckLogout}}' => "        \$this->authorizeAbility('logout', \$user);\n",

            // ── Audit event: login success ──────────────────────────────
            '{{rbacAuditLoginSuccess}}' => <<<PHP
        AuthEvent::dispatch('auth.login.success', [
            'user_id' => \$user->id,
            'ip' => \$request->ip(),
            'user_agent' => \$request->userAgent(),
            'scope' => \$user->getAuthScope(),
        ]);

PHP,

            // ── Audit event: login failed ───────────────────────────────
            '{{rbacAuditLoginFailed}}' => <<<PHP
        AuthEvent::dispatch('auth.login.failed', [
            'login_field_value' => \$credentials['{$loginFieldKey}'] ?? null,
            'ip' => \$request->ip(),
            'user_agent' => \$request->userAgent(),
        ]);

PHP,

            // ── Audit event: refresh (todo marker) ──────────────────────
            '{{rbacAuditRefreshTodo}}' => "        // TODO(R-PKG-010): emitir auth.refresh.success cuando la impl del consumer esté lista.\n",

            // ── Audit event: logout ─────────────────────────────────────
            '{{rbacAuditLogout}}' => <<<'PHP'
        AuthEvent::dispatch('auth.logout', [
            'user_id' => $user->id,
            'token_id' => $token?->id,
        ]);

PHP,

            // ── Audit event: forgot ─────────────────────────────────────
            '{{rbacAuditForgot}}' => <<<PHP
        AuthEvent::dispatch('auth.password_reset.requested', [
            'login_field_value' => \$credentials['{$loginFieldKey}'] ?? null,
            'ip' => \$request->ip(),
        ]);

PHP,

            // ── Audit event: reset (todo marker) ────────────────────────
            '{{rbacAuditResetTodo}}' => "        // TODO(R-PKG-010): emitir auth.password_reset.success cuando la impl del consumer esté lista.\n",

            // ── authorizeAbility() helper method ────────────────────────
            '{{rbacAuthorizeAbilityMethod}}' => <<<'PHP'

    /**
     * Verifica que `$user` tenga la ability configurada para `$endpoint`.
     *
     * Config: `mk_director.auth.abilities.{endpoint}`.
     *   - `null` (default) → no check (modo BC).
     *   - string (`'auth.me.read'`) → check via AbilityResolver.
     *
     * Si la ability es requerida y el user no la tiene, lanza
     * `AuthorizationException` (HTTP 403 via exception handler de Laravel).
     *
     * R-PKG-010 ACR-002.
     */
    protected function authorizeAbility(string $endpoint, mixed $user): void
    {
        $ability = config("mk_director.auth.abilities.{$endpoint}");

        if ($ability === null || $ability === '') {
            return; // BC mode: no check.
        }

        if ($user === null) {
            throw new AuthorizationException("Missing user for ability check: {$ability}");
        }

        if ($this->abilityResolver !== null) {
            if (! $this->abilityResolver->can($user, $ability)) {
                throw new AuthorizationException("Missing ability: {$ability}");
            }

            return;
        }

        // Fallback sin container (unit tests). HasAbilities trait expone
        // canMk() que también funciona sin AbilityResolver (legacy inline).
        if (is_callable([$user, 'canMk']) && ! (bool) $user->canMk($ability)) {
            throw new AuthorizationException("Missing ability: {$ability}");
        }
    }
PHP,

            // ── Routes: rate limit en /login ─────────────────────────────
            // Inline placeholder: el stub tiene `Route::post('login', ...){{rbacLoginThrottle}};`.
            // Default: vacío (sin throttle). RBAC: `->middleware('throttle:...')`.
            '{{rbacLoginThrottle}}' => "->middleware('throttle:' . config('mk_director.auth.rate_limits.login', '5,1'))",

            // ── Routes: rate limit en /forgot ────────────────────────────
            '{{rbacForgotThrottle}}' => "->middleware('throttle:' . config('mk_director.auth.rate_limits.forgot', '3,1'))",

            // ── Routes: rate limit en /reset ─────────────────────────────
            '{{rbacResetThrottle}}' => "->middleware('throttle:' . config('mk_director.auth.rate_limits.reset', '3,1'))",
        ];
    }

    /**
     * Resuelve y valida los profile fields pasados via --profile-fields.
     *
     * Sintaxis extendida (R-PKG-012 + R-PKG-014):
     *   - `key` (sin tipo) → default `string` (BC con R-PKG-011).
     *   - `key:type` → tipo explícito (tipos válidos en `PROFILE_FIELD_TYPES`).
     *   - `!key` o `!key:type` → marca el field como `unique()` (BUG-09 fix, R-PKG-014).
     *
     * Reglas (R-PKG-011 ADR-007 + ADR-008 + REQ-1 + R-PKG-012 ADR-001..ADR-009 + R-PKG-014 BUG-09):
     *   - Vacío o ausente → [] (BC con v1.5.0-rc4).
     *   - CSV con PHP identifiers válidos (`/^[a-zA-Z_][a-zA-Z0-9_]*$/`).
     *   - No duplicados dentro del CSV (fail-fast).
     *   - No colisión con columnas reservadas (id, password, auth_scope, etc.)
     *     ni con el login field (que ya tiene su propia columna).
     *   - Tipos case-sensitive (lowercase only). `String`, `STRING`, `sTrInG` se rechazan.
     *   - Tipo desconocido → fail-fast con lista de tipos válidos.
     *
     * @param  string  $raw  Input crudo del CSV (puede tener espacios).
     * @param  string  $loginField  Login field del scope (para detectar colisión).
     * @return array<string, array{type: string, unique: bool}>|null Mapa key => metadata, o null si inválido.
     */
    protected function resolveProfileFields(string $raw, string $loginField): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return []; // BC mode: sin profile fields.
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($f) => $f !== ''));

        $reserved = [
            'id', 'name', 'password', 'auth_scope', 'client_id',
            'remember_token', 'created_at', 'updated_at',
            'email_verified_at', $loginField,
        ];

        $fields = [];
        foreach ($items as $item) {
            // R-PKG-014 BUG-09: prefijo `!` marca el field como unique.
            // Acepta tanto `!key` como `!key:type`.
            $unique = false;
            if (str_starts_with($item, '!')) {
                $unique = true;
                $item = substr($item, 1);
            }

            if (str_contains($item, ':')) {
                [$key, $type] = explode(':', $item, 2);
                $key = trim($key);
                $type = trim($type);

                // Validación fail-fast: tipo debe estar en la lista cerrada.
                if (! array_key_exists($type, self::PROFILE_FIELD_TYPES)) {
                    $validTypes = implode(', ', array_keys(self::PROFILE_FIELD_TYPES));
                    $this->error("El tipo \"{$type}\" no está soportado. Tipos válidos: {$validTypes}.");

                    return null;
                }
            } else {
                $key = trim($item);
                $type = 'string'; // BC default (R-PKG-011 + R-PKG-012 ADR-009).
            }

            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                $this->error("El campo \"{$key}\" no es un identificador PHP válido (solo letras, números y guión bajo).");

                return null;
            }

            if (in_array($key, $reserved, true)) {
                $this->error("Campo \"{$key}\" colisiona con columna reservada o con --login-field={$loginField}.");

                return null;
            }

            if (isset($fields[$key])) {
                $this->error("Campo \"{$key}\" duplicado en --profile-fields.");

                return null;
            }

            $fields[$key] = [
                'type' => $type,
                'unique' => $unique,
            ];
        }

        return $fields;
    }

    /**
     * Resuelve y valida los profile fields marcados como required via --profile-fields-required.
     *
     * Reglas (R-PKG-014 BUG-03 fix):
     *   - Vacío o ausente → [] (default: todos los profile fields son nullable).
     *   - CSV con PHP identifiers válidos.
     *   - Cada key DEBE existir en `$profileFields` (fail-fast si no).
     *
     * @param  string  $raw  CSV crudo del flag.
     * @param  array<string, array{type: string, unique: bool}>  $profileFields  Resultado de `resolveProfileFields`.
     * @return array<string, bool>|null Mapa key => true (todos true por diseño), o null si inválido.
     */
    protected function resolveRequiredProfileFields(string $raw, array $profileFields): ?array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return [];
        }

        $items = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($f) => $f !== ''));

        $required = [];
        foreach ($items as $key) {
            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
                $this->error("El campo \"{$key}\" en --profile-fields-required no es un identificador PHP válido.");

                return null;
            }

            if (! isset($profileFields[$key])) {
                $this->error("Campo \"{$key}\" en --profile-fields-required no existe en --profile-fields.");

                return null;
            }

            $required[$key] = true;
        }

        return $required;
    }

    /**
     * Construye los placeholders R-PKG-011 para profile fields + register + PATCH /me.
     *
     * Default mode (sin --profile-fields Y sin --verify-email): todos los placeholders
     * son string vacío → BC preservada con v1.5.0-rc4 (sin register, sin PATCH /me).
     *
     * Con `--profile-fields` o `--verify-email`: se genera register (necesario para
     * crear el user con profile fields o disparar VerifyEmail). PATCH /me solo si
     * hay profile fields.
     *
     * R-PKG-012: cada field tiene un tipo (default `string`). El column method,
     * cast entry, y validation rule se derivan de `PROFILE_FIELD_TYPES[$type]`.
     *
     * R-PKG-014 (v1.6.0-rc4):
     *   - `unique` se aplica desde la metadata (`'unique' => true` cuando el usuario
     *     usó prefijo `!` en --profile-fields). Migration: `->unique()->nullable()`.
     *   - `requiredFields` se aplica al primer elemento del validation rule
     *     (`required` reemplaza `nullable` cuando el key está en el set).
     *
     * @param  array<string, array{type: string, unique: bool}>  $fields  Mapa key => metadata.
     * @param  array<string, bool>  $requiredFields  Mapa key => true (override del default nullable).
     * @return array<string, string>
     */
    protected function buildProfileFieldsReplacements(array $fields, array $requiredFields = []): array
    {
        $hasProfile = ! empty($fields);

        if (! $hasProfile) {
            return [
                '{{profileFieldsFillableEntries}}' => '',
                '{{profileFieldsCastEntries}}' => '',
                '{{profileFieldsColumns}}' => '',
                '{{profileFieldsDocblock}}' => '',
                '{{registerRoute}}' => '',
                '{{registerMethod}}' => '',
                '{{updateProfileRoute}}' => '',
                '{{updateProfileMethod}}' => '',
            ];
        }

        $fillable = '';
        $columns = '';
        $docblock = '';
        $castEntries = '';
        $validationRules = [];

        foreach ($fields as $key => $meta) {
            $type = $meta['type'];
            $unique = $meta['unique'];
            $config = self::PROFILE_FIELD_TYPES[$type];

            // $fillable entries: 8 espacios indent + 'name',\n
            $fillable .= "        '{$key}',\n";

            // Migration column: usa column_method + column_args (e.g. decimal('x', 8, 2)).
            // R-PKG-014 BUG-09: si unique=true, agregar ->unique() a la cadena.
            $args = empty($config['column_args'])
                ? ''
                : ', '.implode(', ', $config['column_args']);
            $chain = $unique ? '->unique()->nullable()' : '->nullable()';
            $columns .= "        \$table->{$config['column_method']}('{$key}'{$args}){$chain};\n            ";

            // Docblock @property typed (phpstan-style hint).
            // R-PKG-014 BUG-02 fix: emite bloque completo /** ... */ cuando hay
            // profile fields. El stub antes tenía `/**` después del placeholder,
            // lo que resultaba en docblock suelto.
            //
            // R-PKG-015 BUG-NEW-11 fix: indentación de 5 espacios para alinear
            // con `     *` del docblock. La versión anterior emitía 1 espacio
            // (` * @property`) que resultaba en docblock desalineado y confuso
            // para IDEs/PHPStan. También se agregó el header descriptivo
            // "Profile fields per-scope." en el wrapper del docblock.
            $phpType = $this->profileFieldPhpType($type);
            $docblock .= "     * @property {$phpType} \${$key}\n";

            // Cast entry (solo si no es null — string/text no necesitan cast).
            if ($config['cast'] !== null) {
                $castEntries .= "        '{$key}' => '{$config['cast']}',\n";
            }

            // Validation rule del tipo (table-driven, R-PKG-012 ADR-007).
            // R-PKG-014 BUG-03: si el field está en --profile-fields-required, override
            // el primer rule de 'nullable' a 'required'.
            $rules = $config['validation'];
            if (isset($requiredFields[$key]) && $rules[0] === 'nullable') {
                $rules[0] = 'required';
            }
            $validationRules[$key] = $rules;
        }

        // R-PKG-014 BUG-02 fix: envolver el docblock en /** ... */ para que el
        // stub no genere un docblock suelto.
        // R-PKG-015 BUG-NEW-11 fix: agregar header "Profile fields per-scope."
        // descriptivo + alineación correcta de `     *` (5 espacios) + cerrar
        // con newline antes del `*/`.
        //
        // R-PKG-016 BUG-NEW-14 fix (3er ciclo): el `*/` del docblock generado
        // quedaba PEGADO al `/**` del siguiente bloque en el modelo, porque
        // el `auth-user.model.stub` tiene `{{profileFieldsDocblock}}/**` (sin
        // newline). El pineo previo de R-PKG-015 había alineado indentación
        // pero NO había agregado separación entre docblocks.
        //
        // R-PKG-017 BUG-NEW-25 fix (4to ciclo, drift nuevo con 5+ fields):
        // el pineo previo terminaba el docblock con `\n\n` (doble newline) para
        // insertar blank line entre el docblock y el siguiente bloque. PERO
        // cuando el consumer tiene 5+ profile fields, el último `$key` generaba
        // un `*/` con newline doble que se COMÍA la indentación del próximo
        // bloque del modelo (`{{profileFieldsDocblock}}/**` quedaba como
        // `... last */\n\n/**...` con blank lines acumuladas), produciendo
        // drift en la indentación que rompía el formato PSR-12 en modelos
        // con muchos profile fields.
        //
        // La fix robusta: el docblock generado termina en `\n` (un solo
        // newline) — el control de blank line entre docblocks vive en el
        // stub (`{{profileFieldsDocblock}}\n\n    /**` o similar). Esto
        // elimina el drift y mantiene el control de espaciado en UN lugar.
        if (! empty($docblock)) {
            $docblock = "    /**\n"
                ."     * Profile fields per-scope (R-PKG-011).\n"
                ."     *\n"
                .$docblock
                ."     */\n";
        }

        // Para el método register() y updateProfile(): las reglas se pasan via array.
        $rulesPhp = $this->phpArrayExport($validationRules);

        return [
            '{{profileFieldsFillableEntries}}' => $fillable,
            '{{profileFieldsCastEntries}}' => $castEntries,
            '{{profileFieldsColumns}}' => $columns,
            '{{profileFieldsDocblock}}' => $docblock,
            '{{registerRoute}}' => "\n    Route::post('register', [AuthController::class, 'register']);",
            '{{registerMethod}}' => '', // se setea después con scope + loginField
            '{{updateProfileRoute}}' => "\n        Route::patch('me', [AuthController::class, 'updateProfile']);",
            '{{updateProfileMethod}}' => '', // se setea después
            // Stash temporal para setear en handle() con scope/loginField.
            '__rulesPhp__' => $rulesPhp,
        ];
    }

    /**
     * Mapea un tipo de profile field a su PHP type hint para docblocks @property.
     *
     * Spec: R-PKG-012 ADR-006 (json → array cast), ADR-007 (validation table-driven).
     *
     * @return string PHP type hint (e.g. `string|null`, `int|null`, `\Carbon\Carbon|null`).
     */
    protected function profileFieldPhpType(string $type): string
    {
        return match ($type) {
            'string', 'text' => 'string|null',
            'int' => 'int|null',
            'decimal' => 'float|null',
            'bool' => 'bool|null',
            'date', 'datetime' => '\\Carbon\\Carbon|null',
            'json' => 'array|null',
            default => 'mixed',
        };
    }

    /**
     * Construye el código PHP del método `register()` que se inyecta en el stub.
     *
     * Default (sin profile fields, sin verify-email): no se genera register()
     * porque no hay razón de existir (BC con v1.5.0-rc4). Solo se genera cuando
     * hay profile fields o verify-email activos.
     *
     * Output incluye validación, creación de user, login opcional para no requerir
     * /login post-register, y dispatch de VerifyEmail notification si aplica.
     *
     * R-PKG-014 BUG-04: `$rulesPhp` YA incluye `password => ['required', 'string', 'min:8']`
     * (agregado por el caller en `handle()`). El método solo usa lo que recibe.
     */
    protected function buildRegisterMethod(string $scope, string $scopeLower, string $loginField, string $rulesPhp, bool $verifyEmail): string
    {
        $verifyDispatch = $verifyEmail
            ? "\n        // R-PKG-011: dispatch verification notification (queueable).\n        \$user->sendEmailVerificationNotification();"
            : '';

        // Render inline (no nowdoc) para que las variables se interpoleen.
        $code = <<<PHP

    /**
     * POST /api/{$scopeLower}/auth/register
     *
     * Crea un nuevo {$scope} con los profile fields declarados via
     * `--profile-fields`. Si el scope se generó con `--verify-email`,
     * dispatch de `Illuminate\\Auth\\Notifications\\VerifyEmail` queueable.
     *
     * R-PKG-011: register solo existe si hay `--profile-fields` o `--verify-email`.
     * Para custom validation (regex CI, date format, etc.), override este método
     * en la subclase generada.
     *
     * R-PKG-014 (v1.6.0-rc4): `$rulesPhp` incluye `password` por default
     * (BUG-04 fix). Override via StoreAdminRequest si necesitás custom logic
     * (e.g. confirmar password, validar contra breached passwords, etc.).
     *
     * BC: NO existe en v1.5.0-rc4 (este método es opt-in via flag).
     */
    public function register(\\Illuminate\\Http\\Request \$request): \\Illuminate\\Http\\JsonResponse
    {
        \$data = \$request->validate({$rulesPhp});

        /** @var {$scope} \$user */
        \$user = {$scope}::create(\$data);
        \$user->setAuthScope('{$scopeLower}');{$verifyDispatch}

        return \$this->sendResponse(
            \$user->only(['id', 'name', '{$loginField}']),
            'Registro exitoso. Verificá tu email para activar la cuenta.',
            201,
        );
    }

PHP;

        return $code;
    }

    /**
     * Construye el código PHP del método `updateProfile()` que se inyecta en el stub.
     *
     * Solo se genera cuando `--profile-fields` está activo. Valida y actualiza
     * los profile fields del user autenticado.
     */
    protected function buildUpdateProfileMethod(string $scope, string $scopeLower, string $loginField, string $rulesPhp): string
    {
        // Render inline (no nowdoc) para que las variables se interpoleen.
        $code = <<<PHP

    /**
     * PATCH /api/{$scopeLower}/auth/me
     *
     * Actualiza los profile fields del {$scope} autenticado.
     *
     * R-PKG-011: solo existe si el scope fue generado con `--profile-fields`.
     * Para custom validation (regex CI, date format, etc.), override este método
     * en la subclase generada.
     *
     * BC: NO existe en v1.5.0-rc4 (este método es opt-in via flag).
     */
    public function updateProfile(\\Illuminate\\Http\\Request \$request): \\Illuminate\\Http\\JsonResponse
    {
        \$user = \$request->user();
        \$data = \$request->validate({$rulesPhp});

        \$user->update(\$data);

        return \$this->sendResponse(
            \$user->fresh()->only(['id', 'name', '{$loginField}']),
            'Perfil actualizado.',
        );
    }

PHP;

        return $code;
    }

    /**
     * Construye los placeholders R-PKG-011 para email verification opt-in.
     *
     * Default mode (sin --verify-email): todos string vacío (BC con v1.5.0-rc4).
     * Con --verify-email: cada placeholder se popula con el código correspondiente
     * (column, cast, import, routes, methods, middleware).
     *
     * @return array<string, string>
     */
    protected function buildVerifyEmailReplacements(bool $enabled): array
    {
        if (! $enabled) {
            return [
                '{{emailVerifyRoutes}}' => '',
                '{{verifiedMiddleware}}' => '',
                '{{verifyEmailMethods}}' => '',
                '{{registerVerifyEmailDispatch}}' => '',
            ];
        }

        return [
            // Routes verify + resend (signed URL para verify, throttle para resend).
            '{{emailVerifyRoutes}}' => <<<'PHP'

    // ── Email verification (signed URLs) ──────────────────────────
    Route::get('email/verify/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('{{moduleNameLower}}.auth.verify');
    Route::post('email/resend', [AuthController::class, 'resendVerification'])
        ->middleware('throttle:6,1')
        ->middleware('mk.auth:{{moduleNameLower}}');
PHP,
            // Middleware 'verified' en el grupo protegido (opcional). Default vacío = sin verificación.
            // Para activarlo, el consumer puede setear MK_AUTH_VERIFIED_MIDDLEWARE=true en .env.
            // Por simplicidad v1.5.0-rc5: NO se aplica automáticamente. Consumer override.
            '{{verifiedMiddleware}}' => '',
            // Métodos verifyEmail() + resendVerification() en el AuthController.
            '{{verifyEmailMethods}}' => <<<'PHP'

    /**
     * GET /api/{{moduleNameLower}}/auth/email/verify/{id}/{hash}
     *
     * Marca el email como verificado si la signed URL es válida.
     *
     * R-PKG-011: solo existe si el scope fue generado con `--verify-email`.
     */
    public function verifyEmail(\Illuminate\Http\Request $request, string $id, string $hash): \Illuminate\Http\JsonResponse
    {
        if (! hash_equals((string) $id, (string) $request->user()->getKey())) {
            return $this->sendError('Invalid verification link.', [], 403);
        }

        if (! hash_equals(sha1($request->user()->getEmailForVerification()), (string) $hash)) {
            return $this->sendError('Invalid verification link.', [], 403);
        }

        if ($request->user()->hasVerifiedEmail()) {
            return $this->sendResponse(true, 'Email ya verificado.');
        }

        $request->user()->markEmailAsVerified();

        return $this->sendResponse(true, 'Email verificado exitosamente.');
    }

    /**
     * POST /api/{{moduleNameLower}}/auth/email/resend
     *
     * Re-envía el email de verificación. Throttled 6,1 por route middleware.
     *
     * R-PKG-011: solo existe si el scope fue generado con `--verify-email`.
     */
    public function resendVerification(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $this->sendError('Email ya verificado.', [], 400);
        }

        $request->user()->sendEmailVerificationNotification();

        return $this->sendResponse(true, 'Email de verificación re-enviado.');
    }
PHP,
            // Dispatch de VerifyEmail notification en register() (si register existe).
            // Si no hay register() (default mode), esto queda como string vacío sin efecto.
            '{{registerVerifyEmailDispatch}}' => <<<'PHP'

        // R-PKG-011: dispatch verification notification (queueable).
        $user->sendEmailVerificationNotification();
PHP,
        ];
    }

    /**
     * Helper: exporta un array PHP a string literal para inyectar en stubs.
     *
     * Usado por `buildRegisterMethod` y `buildUpdateProfileMethod` para generar
     * las validation rules. Output: `['name' => ['required', 'string'], ...]`.
     *
     * @param  array<string, array<int, string>>  $array
     */
    protected function phpArrayExport(array $array): string
    {
        $entries = [];
        foreach ($array as $key => $value) {
            $entries[] = var_export($key, true).' => ['.implode(', ', array_map(static fn ($v) => var_export($v, true), $value)).']';
        }

        return '['.implode(', ', $entries).']';
    }

    protected function registerServiceProvider(string $scope): void
    {
        // The provider lives at app/Modules/{Scope}/Providers/{Scope}ServiceProvider.php
        // (see auth-user.service-provider.stub), so the FQCN must include the
        // `Providers\` subnamespace. Bug 1.3.0-001: this used to emit
        // `App\Modules\{Scope}\{Scope}ServiceProvider` (missing the
        // subnamespace) which made `bootstrap/providers.php` reference a
        // class Laravel could not resolve — the module loaded zero routes.
        $providerClass = "App\\Modules\\{$scope}\\Providers\\{$scope}ServiceProvider::class";

        // Laravel 11+ — bootstrap/providers.php
        $bootstrapPath = base_path('bootstrap/providers.php');
        if (File::exists($bootstrapPath)) {
            $content = File::get($bootstrapPath);
            if (! str_contains($content, $providerClass)) {
                if (preg_match('/return\s*\[\s*(.*?)\s*\];/s', $content, $matches)) {
                    $providerLine = "    {$providerClass},";
                    $newContent = str_replace('];', "{$providerLine}\n];", $content);
                    File::put($bootstrapPath, $newContent);
                    $this->line('   ✅ Auto-registrado en bootstrap/providers.php');
                }
            }

            return;
        }

        // Laravel 10 and below — config/app.php
        $configPath = config_path('app.php');
        if (File::exists($configPath)) {
            $content = File::get($configPath);
            if (! str_contains($content, $providerClass)) {
                $search = "/*\n         * Application Service Providers...\n         */";
                if (str_contains($content, $search)) {
                    $replace = "/*\n         * Application Service Providers...\n         */\n        {$providerClass},";
                    $newContent = str_replace($search, $replace, $content);
                    File::put($configPath, $newContent);
                    $this->line('   ✅ Auto-registrado en config/app.php');
                } else {
                    $this->warn("   ⚠️  No se pudo auto-registrar. Agregá manualmente: {$providerClass}");
                }
            }
        }
    }

    /**
     * Imprime (no escribe) los snippets a agregar a config/auth.php.
     * Decisión consciente: el command no modifica config/auth.php del consumer
     * porque ese archivo es del proyecto y editarlo programáticamente es invasivo.
     * El dev revisa, ajusta scopes, y pega.
     *
     * @param  string  $loginField  Nombre del campo de login (email, ci, phone, etc.).
     *                              Se incluye en el comentario para que el dev sepa
     *                              que la columna se llama igual al campo.
     */
    protected function printAuthConfigSnippets(string $scope, string $scopeLower, string $scopePlural, string $loginField = 'email'): void
    {
        $modelClass = "App\\Modules\\{$scope}\\Models\\{$scope}::class";

        $this->warn('📋 Snippets para config/auth.php (NO se modifican automáticamente):');
        $this->newLine();
        $this->line('    // ── En el array \'guards\' ─────────────────────────────────');
        $this->line("    '{$scopeLower}' => [");
        $this->line("        'driver' => 'sanctum',");
        $this->line("        'provider' => '{$scopePlural}',");
        $this->line('    ],');
        $this->newLine();
        $this->line('    // ── En el array \'providers\' ──────────────────────────────');
        $this->line("    '{$scopePlural}' => [");
        $this->line("        'driver' => 'eloquent',");
        $this->line("        'model' => {$modelClass},");
        $this->line('    ],');
        $this->newLine();
        $this->warn('📌 Pasos siguientes:');
        $this->line('   1. php artisan migrate');
        $this->line('   2. Pegá los snippets de arriba en config/auth.php');
        $this->line('   3. Implementá los TODOs del AuthController (login, refresh, forgot, reset)');
        $this->line('      usando Mk\\Director\\Auth\\Services\\TokenIssuer');
        $this->line('   4. (Opcional) Definí mk_director.auth.user_model si querés que mk-director');
        $this->line('      apunte a este modelo. NO se hace automáticamente — respetar DDD.');
        if (func_num_args() >= 4) {
            // Print extra step for RBAC if applicable.
            // (Detected by checking the actual generated controller contents later.)
        }
    }
}