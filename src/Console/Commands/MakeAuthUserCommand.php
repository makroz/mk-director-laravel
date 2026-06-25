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
    protected $signature = 'mk:make:auth-user {scope : Nombre del scope en StudlyCase singular (Ej: Member, Customer, Partner)} {--login-field=email : Campo usado para login (default: email). BC: si no se pasa, idéntico a v1.4.0. Valores comunes: email, ci, phone, username, documento.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Genera un scope de autenticación MK completo: Model (extends AuthUser), migration con auth_scope, AuthController (login/refresh/logout/me/forgot/reset), routes y ServiceProvider auto-registrado. Use --login-field=<field> para campos no-email (RETO: ci, genéricos: phone, username, etc.).';

    public function handle(): int
    {
        $scope = Str::studly($this->argument('scope'));
        $scopeLower = Str::snake($scope);
        $scopePlural = Str::plural($scopeLower);
        $loginField = $this->resolveLoginField((string) $this->option('login-field'));

        if ($scope === '') {
            $this->error('El nombre del scope no puede estar vacío.');

            return self::FAILURE;
        }

        if ($loginField === null) {
            $this->error('El campo de login debe ser un identificador no-vacío (letras, números, guión bajo).');

            return self::FAILURE;
        }

        $this->info("🔐 Generando scope de autenticación MK: {$scope}");

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
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.model.stub', 'Models', "{$scope}.php");
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.migration.stub', 'Database/Migrations', $this->migrationFilename($scopePlural));
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.auth-controller.stub', 'Http/Controllers', 'AuthController.php');
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.routes.stub', 'Http/Routes', 'api.php');
        $this->generateStub($scope, $scopeLower, $scopePlural, $loginField, 'auth-user.service-provider.stub', 'Providers', "{$scope}ServiceProvider.php");

        $this->newLine();
        $this->info('🔌 Auto-registrando ServiceProvider:');
        $this->registerServiceProvider($scope);

        $this->newLine();
        $this->info("✅ Scope {$scope} generado con el estándar MK-Director:");
        $this->line("   • Model:        app/Modules/{$scope}/Models/{$scope}.php (extends AuthUser, loginField={$loginField})");
        $this->line("   • Migration:    app/Modules/{$scope}/Database/Migrations/{$this->migrationFilename($scopePlural)}");
        $this->line("   • AuthCtrl:     app/Modules/{$scope}/Http/Controllers/AuthController.php");
        $this->line("   • Routes:       /api/{$scopeLower}/auth/{login,refresh,logout,me,forgot,reset}");
        $this->line("   • ServiceProv:  app/Modules/{$scope}/Providers/{$scope}ServiceProvider.php");

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
    }
}
