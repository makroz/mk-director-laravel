<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:module {name : El nombre del módulo en singular (Ej: Survey)} {--with-rbac : Genera un módulo con trío RBAC completo (User + Role + Ability + 2 pivots + 3 Policies + RbacService + ServiceProvider con Gate bindings)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scaffold completo de un módulo MK-API Standard (Controller, Model, Service, Repository, DTO, Contracts, etc.)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $name       = $this->argument('name');
        $moduleName = Str::studly($name);
        $withRbac   = (bool) $this->option('with-rbac');

        $this->info("🚀 Iniciando generación del módulo MK-API: {$moduleName}");

        $basePath = app_path("Modules/{$moduleName}");

        if (File::exists($basePath)) {
            $this->error("El módulo {$moduleName} ya existe en {$basePath}.");
            return Command::FAILURE;
        }

        // ─── Crear estructura de directorios (MK-API Standard R-A-001) ──────────
        $directories = [
            'Controllers',
            'Contracts',            // Interface del Repository — obligatoria (R-A-007)
            'DTOs',
            'Enums',
            'Models',
            'Repositories',        // Solo queries DB — sin lógica, sin caché (R-A-003)
            'Requests',
            'Resources',
            'Routes',
            'Services',            // Business logic + Cache (R-A-004)
            'Database/Migrations',
        ];

        if ($withRbac) {
            $directories[] = 'Policies';  // RBAC-001..005: policies por modelo
        }

        foreach ($directories as $dir) {
            File::makeDirectory("{$basePath}/{$dir}", 0755, true);
            $this->line("  📁 {$dir}/");
        }

        $this->newLine();
        $this->info('📄 Generando archivos...');

        if ($withRbac) {
            $this->generateRbacPack($moduleName);
        } else {
            $this->generateStandardPack($moduleName);
        }

        // ─── Auto-registrar el ServiceProvider ──────────────────────────────────
        $this->registerServiceProvider($moduleName);

        $this->newLine();
        if ($withRbac) {
            $this->info("✅ Módulo {$moduleName} con RBAC triad generado:");
            $this->line("   • 3 Models    (User, Role, Ability) — tablas `{scope}_users`, `{scope}_roles`, `{scope}_abilities`");
            $this->line("   • 3 Controllers (CRUD + assignRole/revokeRole/syncAbilities)");
            $this->line("   • 3 Policies  ({$moduleName}Policy, RolePolicy, AbilityPolicy) — default-deny + super-admin bypass");
            $this->line("   • 1 Service   (RbacService — singleton)");
            $this->line("   • 5 Migrations con FK constraints");
            $this->line("   • 1 ServiceProvider con Gate::policy + Gate::define auto-bind");
            $this->newLine();
            $this->warn("   ⚠️  Próximos pasos:");
            $this->line("      1. php artisan migrate (corre las 5 migrations en orden)");
            $this->line("      2. mk:discover-abilities --module={$moduleName} (crea las abilities en la tabla)");
            $this->line("      3. mk:auth:create-super-admin (bootstrap inicial)");
        } else {
            $this->info("✅ Módulo {$moduleName} generado con el estándar MK-API:");
            $this->line("   Flujo: FormRequest → Controller → DTO → Service → Repository → Model → Resource");
            $this->newLine();
            $this->warn("   ⚠️  Recuerda completar los TODOs en:");
            $this->line("      - DTOs/{$moduleName}Data.php (propiedades tipadas)");
            $this->line("      - Services/{$moduleName}Service.php (getSearchable, getWith)");
            $this->line("      - Crear la migración en Database/Migrations/");
        }

        return Command::SUCCESS;
    }

    /**
     * Genera el pack estándar (CRUD sin RBAC).
     */
    protected function generateStandardPack(string $moduleName): void
    {
        // Capa HTTP
        $this->generateFile($moduleName, 'controller.stub',           'Controllers',  "{$moduleName}Controller.php");
        $this->generateFile($moduleName, 'route.stub',                'Routes',       'api.php');

        // Capa de Dominio
        $this->generateFile($moduleName, 'model.stub',                'Models',       "{$moduleName}.php");
        $this->generateFile($moduleName, 'enum.stub',                 'Enums',        "{$moduleName}Status.php");

        // Capa de Datos / Contratos (MK-API Standard)
        $this->generateFile($moduleName, 'dto.stub',                  'DTOs',         "{$moduleName}Data.php");
        $this->generateFile($moduleName, 'repository-interface.stub', 'Contracts',    "{$moduleName}RepositoryInterface.php");
        $this->generateFile($moduleName, 'repository.stub',           'Repositories', "{$moduleName}Repository.php");
        $this->generateFile($moduleName, 'service.stub',              'Services',     "{$moduleName}Service.php");

        // ServiceProvider con binding Interface → Implementation
        $this->generateFile($moduleName, 'provider.stub',             '',             "{$moduleName}ModuleServiceProvider.php");
    }

    /**
     * Genera el pack RBAC (User + Role + Ability + 2 pivots + 3 Policies + RbacService).
     *
     * Spec: RBAC-001..005 (R-PKG-008).
     *
     * Total de archivos: 20
     *   - 3 Models (User, Role, Ability)
     *   - 3 Controllers (UserController, RoleController, AbilityController)
     *   - 3 Policies ({Name}Policy, RolePolicy, AbilityPolicy)
     *   - 1 Service (RbacService — reemplaza el service estándar)
     *   - 1 DTO (estándar, reusado)
     *   - 1 Repository Interface (estándar, reusado)
     *   - 1 Repository (estándar, reusado)
     *   - 1 Routes/api.php (RBAC-specific, reemplaza el route estándar)
     *   - 1 ServiceProvider (RBAC-specific, reemplaza el provider estándar)
     *   - 5 Migrations (con FK constraints, R-RISK-001)
     */
    protected function generateRbacPack(string $moduleName): void
    {
        $moduleNameLower       = Str::snake($moduleName);
        $moduleNamePluralLower = Str::plural(Str::snake($moduleName, '-'));
        $ts                    = now();
        $stubFolder            = 'module-rbac';

        // ─── Models (3) ─────────────────────────────────────────────────────
        $this->generateFile($moduleName, 'model-user.stub',    'Models', "{$moduleName}.php", $stubFolder);
        $this->generateFile($moduleName, 'model-role.stub',    'Models', 'Role.php',         $stubFolder);
        $this->generateFile($moduleName, 'model-ability.stub', 'Models', 'Ability.php',      $stubFolder);

        // ─── Controllers (3) ────────────────────────────────────────────────
        $this->generateFile($moduleName, 'controller-user.stub',    'Controllers', "{$moduleName}Controller.php", $stubFolder);
        $this->generateFile($moduleName, 'controller-role.stub',    'Controllers', 'RoleController.php',         $stubFolder);
        $this->generateFile($moduleName, 'controller-ability.stub', 'Controllers', 'AbilityController.php',      $stubFolder);

        // ─── Policies (3) ───────────────────────────────────────────────────
        $this->generateFile($moduleName, 'policy-user.stub',    'Policies', "{$moduleName}Policy.php", $stubFolder);
        $this->generateFile($moduleName, 'policy-role.stub',    'Policies', 'RolePolicy.php',         $stubFolder);
        $this->generateFile($moduleName, 'policy-ability.stub', 'Policies', 'AbilityPolicy.php',      $stubFolder);

        // ─── Service (RbacService, reemplaza el service estándar) ───────────
        $this->generateFile($moduleName, 'service-rbac.stub', 'Services', 'RbacService.php', $stubFolder);

        // ─── DTO + Repository + Repository Interface (estándar, reusados) ──
        $this->generateFile($moduleName, 'dto.stub',                  'DTOs',         "{$moduleName}Data.php");
        $this->generateFile($moduleName, 'repository-interface.stub', 'Contracts',    "{$moduleName}RepositoryInterface.php");
        $this->generateFile($moduleName, 'repository.stub',           'Repositories', "{$moduleName}Repository.php");

        // ─── Routes (RBAC-specific, reemplaza el route estándar) ────────────
        $this->generateFile($moduleName, 'routes-rbac.stub', 'Routes', 'api.php', $stubFolder);

        // ─── ServiceProvider (RBAC-specific, reemplaza el provider estándar) ─
        $this->generateFile($moduleName, 'provider-rbac.stub', '', "{$moduleName}ModuleServiceProvider.php", $stubFolder);

        // ─── Migrations (5, con timestamps secuenciales para orden FK) ─────
        // El orden importa: users → roles → abilities → role_user pivot → ability_role pivot.
        $migrations = [
            ['stub' => 'migration-user.stub',              'name' => "create_{$moduleNameLower}_users_table"],
            ['stub' => 'migration-role.stub',              'name' => "create_{$moduleNameLower}_roles_table"],
            ['stub' => 'migration-ability.stub',           'name' => "create_{$moduleNameLower}_abilities_table"],
            ['stub' => 'migration-role-user-pivot.stub',   'name' => "create_{$moduleNameLower}_role_user_table"],
            ['stub' => 'migration-ability-role-pivot.stub','name' => "create_{$moduleNameLower}_ability_role_table"],
        ];
        foreach ($migrations as $i => $migration) {
            $tsString = $ts->copy()->addSeconds($i)->format('Y_m_d_His');
            $this->generateMigration($moduleName, $migration['stub'], $migration['name'].'.php', $tsString, $stubFolder);
        }
    }

    protected function registerServiceProvider(string $moduleName): void
    {
        $providerClass = "App\\Modules\\{$moduleName}\\{$moduleName}ModuleServiceProvider::class";

        // Laravel 11+ checking
        $bootstrapPath = base_path('bootstrap/providers.php');
        if (File::exists($bootstrapPath)) {
            $content = File::get($bootstrapPath);
            if (!str_contains($content, $providerClass)) {
                if (preg_match('/return\s*\[\s*(.*?)\s*\];/s', $content, $matches)) {
                    $insertTarget = "];";
                    $providerLine = "    {$providerClass},";
                    $newContent   = str_replace($insertTarget, "{$providerLine}\n];", $content);
                    File::put($bootstrapPath, $newContent);
                    $this->info(" - Auto-registrado en bootstrap/providers.php");
                }
            }
            return;
        }

        // Laravel 10 and below checking
        $configPath = config_path('app.php');
        if (File::exists($configPath)) {
            $content = File::get($configPath);
            if (!str_contains($content, $providerClass)) {
                $search = "/*\n         * Application Service Providers...\n         */";
                if (str_contains($content, $search)) {
                    $replace    = "/*\n         * Application Service Providers...\n         */\n        {$providerClass},";
                    $newContent = str_replace($search, $replace, $content);
                    File::put($configPath, $newContent);
                    $this->info(" - Auto-registrado en config/app.php");
                } else {
                    $this->warn("No se pudo auto-registrar. Agrega manualmente: {$providerClass}");
                }
            }
            return;
        }
    }

    protected function generateFile(string $moduleName, string $stubName, string $folder, string $fileName, ?string $stubFolder = null): void
    {
        $stubBase = __DIR__ . '/../../Stubs/';
        $stubPath = $stubBase . ($stubFolder !== null ? $stubFolder . '/' : '') . $stubName;

        if (!File::exists($stubPath)) {
            $this->error("  ❌ Stub no encontrado: {$stubPath}");
            return;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}',            $moduleName,                               $content);
        $content = str_replace('{{moduleNameLower}}',       Str::snake($moduleName),                   $content);
        $content = str_replace('{{moduleNamePluralLower}}', Str::plural(Str::snake($moduleName, '-')), $content);

        $targetFolder = app_path("Modules/{$moduleName}");
        if (!empty($folder)) {
            $targetFolder .= "/{$folder}";
        }

        $targetPath  = "{$targetFolder}/{$fileName}";
        File::put($targetPath, $content);

        $displayName = !empty($folder) ? "{$folder}/{$fileName}" : $fileName;
        $this->line("   ✅ {$displayName}");
    }

    /**
     * Genera un archivo de migración con timestamp prefix.
     *
     * A diferencia de generateFile(), este método:
     *   - Toma el timestamp como parámetro (necesario cuando se generan
     *     múltiples migrations en el mismo run — sin colisión de filename).
     *   - Reemplaza el token {{migrationDate}} dentro del stub (no se usa
     *     por ahora pero queda disponible para migrations que necesiten
     *     referenciar la fecha internamente).
     */
    protected function generateMigration(string $moduleName, string $stubName, string $baseFileName, string $timestamp, ?string $stubFolder = null): void
    {
        $stubBase = __DIR__ . '/../../Stubs/';
        $stubPath = $stubBase . ($stubFolder !== null ? $stubFolder . '/' : '') . $stubName;

        if (!File::exists($stubPath)) {
            $this->error("  ❌ Stub no encontrado: {$stubPath}");
            return;
        }

        $content = File::get($stubPath);
        $content = str_replace('{{ModuleName}}',            $moduleName,                               $content);
        $content = str_replace('{{moduleNameLower}}',       Str::snake($moduleName),                   $content);
        $content = str_replace('{{moduleNamePluralLower}}', Str::plural(Str::snake($moduleName, '-')), $content);
        $content = str_replace('{{migrationDate}}',         $timestamp,                                $content);

        $targetPath = app_path("Modules/{$moduleName}/Database/Migrations/{$timestamp}_{$baseFileName}");
        File::put($targetPath, $content);

        $this->line("   ✅ Database/Migrations/{$timestamp}_{$baseFileName}");
    }
}
