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
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:make:auth-user {scope : Nombre del scope en StudlyCase singular (Ej: Member, Customer, Partner)}
        {--login-field=email : Campo usado para login (default: email). BC: si no se pasa, idéntico a v1.4.0. Valores comunes: email, ci, phone, username, documento.}
        {--with-auth-rbac : Habilita RBAC integration (ability checks en /me y /logout), rate limiting en /login, /forgot, /reset, y audit log via AuthEvent (R-PKG-010). Default BC: false. Configurar abilities + rate_limits en config/mk_director.php.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera un scope de autenticación MK completo: Model (extends AuthUser), migration con auth_scope, AuthController (login/refresh/logout/me/forgot/reset), routes y ServiceProvider auto-registrado. Use --login-field=<field> para campos no-email (RETO: ci, genéricos: phone, username, etc.). Use --with-auth-rbac para integrar ability checks, rate limit y audit log (R-PKG-010).';

    public function handle(): int
    {
        $scope = Str::studly($this->argument('scope'));
        $scopeLower = Str::snake($scope);
        $scopePlural = Str::plural($scopeLower);
        $loginField = $this->resolveLoginField((string) $this->option('login-field'));
        $withAuthRbac = (bool) $this->option('with-auth-rbac');

        if ($scope === '') {
            $this->error('El nombre del scope no puede estar vacío.');

            return self::FAILURE;
        }

        if ($loginField === null) {
            $this->error('El campo de login debe ser un identificador no-vacío (letras, números, guión bajo).');

            return self::FAILURE;
        }

        // Placeholders condicionales (R-PKG-009 D5).
        //
        // Nota sobre `MustVerifyEmail`: el AuthUser base YA implementa esa interface
        // (línea 41 de AuthUser.php), por lo que la subclase NO necesita re-implementarla.
        // Para `loginField != email`, igual la hereda pero queda como interface "muerto"
        // (no se usa). Esto es preferible a sacarla del base (sería BC-breaking).
        //
        // El import `use Illuminate\Contracts\Auth\MustVerifyEmail` solo se incluye
        // cuando `loginField=email` para evitar lint warnings de import no usado.
        $isEmail = $loginField === 'email';

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
            '{{emailVerifiedAtColumn}}' => $isEmail
                ? "\$table->timestamp('email_verified_at')->nullable();\n            "
                : '',
            // Cast entry SIN trailing whitespace. El stub del model tiene el
            // `\n        'password'` después. Si loginField=email, queda
            // `[\n        'email_verified_at' => 'datetime',\n        'password'...`.
            // Si no es email, queda `[\n        'password'...` (line vacía al ppio).
            // Trim final se hace en generateStub() si es necesario.
            '{{emailVerifiedAtCastEntry}}' => $isEmail
                ? "        'email_verified_at' => 'datetime',\n"
                : '',
            '{{mustVerifyEmailUse}}' => $isEmail
                ? "use Illuminate\\Contracts\\Auth\\MustVerifyEmail;\n"
                : '',
            '{{loginFieldValidationRule}}' => $isEmail
                ? "['required', 'email']"
                : "['required', 'string']",
        ];

        $extraReplacements = array_merge($loginFieldReplacements, $rbacReplacements);

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