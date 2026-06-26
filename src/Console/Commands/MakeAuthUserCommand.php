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
     */
    public const PROFILE_FIELD_TYPES = [
        'string' => [
            'column_method' => 'string',
            'column_args' => [],
            'cast' => null,
            'validation' => ['required', 'string', 'max:255'],
        ],
        'text' => [
            'column_method' => 'text',
            'column_args' => [],
            'cast' => null,
            'validation' => ['required', 'string'],
        ],
        'int' => [
            'column_method' => 'integer',
            'column_args' => [],
            'cast' => 'integer',
            'validation' => ['required', 'integer'],
        ],
        'decimal' => [
            'column_method' => 'decimal',
            'column_args' => [8, 2],
            'cast' => 'decimal:2',
            'validation' => ['required', 'numeric'],
        ],
        'bool' => [
            'column_method' => 'boolean',
            'column_args' => [],
            'cast' => 'boolean',
            'validation' => ['required', 'boolean'],
        ],
        'date' => [
            'column_method' => 'date',
            'column_args' => [],
            'cast' => 'date',
            'validation' => ['required', 'date'],
        ],
        'datetime' => [
            'column_method' => 'dateTime',
            'column_args' => [],
            'cast' => 'datetime',
            'validation' => ['required', 'date'],
        ],
        'json' => [
            'column_method' => 'json',
            'column_args' => [],
            'cast' => 'array',
            'validation' => ['required', 'array'],
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
        {--profile-fields= : Campos adicionales para el perfil del scope (CSV con sintaxis key[:type], default: ninguno = BC). Cada field se agrega como columna del tipo correspondiente en la tabla del scope, en $fillable del modelo, y se expone en /me + PATCH /me + /register. Sin tipo = string (BC con R-PKG-011). Tipos soportados: string, text, int, decimal, bool, date, datetime, json (R-PKG-012). Ortogonal con --login-field, --with-auth-rbac y --verify-email. Ej: --profile-fields=name,birthdate:date,age:int (R-PKG-011 + R-PKG-012).}
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
        $profileFields = $this->resolveProfileFields((string) $this->option('profile-fields'), $loginField);
        $verifyEmailRequested = (bool) $this->option('verify-email');

        if ($scope === '') {
            $this->error('El nombre del scope no puede estar vacío.');

            return self::FAILURE;
        }

        if ($loginField === null) {
            $this->error('El campo de login debe ser un identificador no-vacío (letras, números, guión bajo).');

            return self::FAILURE;
        }

        if ($profileFields === null) {
            // resolveProfileFields() ya imprimió el error específico.
            return self::FAILURE;
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
        ];

        // Placeholders condicionales R-PKG-011: profile fields per-scope + email verification opt-in.
        $profileFieldsReplacements = $this->buildProfileFieldsReplacements($profileFields);
        $verifyEmailReplacements = $this->buildVerifyEmailReplacements($verifyEmail);

        // R-PKG-011: register() y updateProfile() se generan condicionalmente.
        // - register(): si hay --profile-fields OR --verify-email (necesario para crear user).
        // - updateProfile(): solo si hay --profile-fields (PATCH /me sin razón si no hay fields custom).
        $rulesPhp = $profileFieldsReplacements['__rulesPhp__'] ?? '[]';
        unset($profileFieldsReplacements['__rulesPhp__']);

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
                $rulesPhp,
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

        $this->newLine();
        $this->info("✅ Scope {$scope} generado con el estándar MK-Director:");
        $this->line("   • Model:        app/Modules/{$scope}/Models/{$scope}.php (extends AuthUser, loginField={$loginField})");
        $this->line("   • Migration:    app/Modules/{$scope}/Database/Migrations/{$this->migrationFilename($scopePlural)}");
        $this->line("   • AuthCtrl:     app/Modules/{$scope}/Http/Controllers/AuthController.php".($withAuthRbac ? ' (RBAC enabled)' : ''));
        $this->line("   • Routes:       /api/{$scopeLower}/auth/{login,refresh,logout,me,forgot,reset}".($withAuthRbac ? ' (rate limited)' : ''));
        $this->line("   • ServiceProv:  app/Modules/{$scope}/Providers/{$scope}ServiceProvider.php");

        if ($withAuthRbac) {
            $this->newLine();
            $this->warn('🔐 RBAC integration habilitada. Siguientes pasos:');
            $this->line('   1. Configurar abilities en config/mk_director.php:');
            $this->line("      'abilities' => ['me' => 'auth.{$scopeLower}.me', 'logout' => 'auth.{$scopeLower}.logout'],");
            $this->line('   2. (Opcional) Customizar rate limits via MK_AUTH_RATE_LIMIT_* env vars.');
            $this->line('   3. (Opcional) Publicar config/mk_director.php si necesitás overrides granulares.');
            $this->line("   4. Registrar un listener para Mk\\Director\\Auth\\Events\\AuthEvent si querés audit log.");
        }

        // Imprimir snippets a mano (no modificar config/auth.php automáticamente)
        $this->newLine();
        $this->printAuthConfigSnippets($scope, $scopeLower, $scopePlural, $loginField);

        return self::SUCCESS;
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
            '{{rbacAbilityCheckMe}}' => "    \$this->authorizeAbility('me', \$request->user());\n",

            // ── Ability check en /logout ────────────────────────────────
            '{{rbacAbilityCheckLogout}}' => "    \$this->authorizeAbility('logout', \$user);\n",

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
     * Sintaxis extendida (R-PKG-012):
     *   - `key` (sin tipo) → default `string` (BC con R-PKG-011).
     *   - `key:type` → tipo explícito (tipos válidos en `PROFILE_FIELD_TYPES`).
     *
     * Reglas (R-PKG-011 ADR-007 + ADR-008 + REQ-1 + R-PKG-012 ADR-001..ADR-009):
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
     * @return array<string, string>|null Mapa key => type (type siempre presente, default `string`), o null si inválido.
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

            $fields[$key] = $type;
        }

        return $fields;
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
     * @param  array<string, string>  $fields  Mapa key => type (type siempre presente, default `string`).
     * @return array<string, string>
     */
    protected function buildProfileFieldsReplacements(array $fields): array
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

        foreach ($fields as $key => $type) {
            $config = self::PROFILE_FIELD_TYPES[$type];

            // $fillable entries: 8 espacios indent + 'name',\n
            $fillable .= "        '{$key}',\n";

            // Migration column: usa column_method + column_args (e.g. decimal('x', 8, 2)).
            $args = empty($config['column_args'])
                ? ''
                : ', '.implode(', ', $config['column_args']);
            $columns .= "        \$table->{$config['column_method']}('{$key}'{$args})->nullable();\n            ";

            // Docblock @property typed (phpstan-style hint).
            $phpType = $this->profileFieldPhpType($type);
            $docblock .= " * @property {$phpType} \${$key}\n";

            // Cast entry (solo si no es null — string/text no necesitan cast).
            if ($config['cast'] !== null) {
                $castEntries .= "        '{$key}' => '{$config['cast']}',\n";
            }

            // Validation rule del tipo (table-driven, R-PKG-012 ADR-007).
            $validationRules[$key] = $config['validation'];
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