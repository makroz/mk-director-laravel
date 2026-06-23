<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Composer\InstalledVersions;
use Symfony\Component\Process\Process;

class MkUpdateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:update {--dry-run : Ejecutar simulando los cambios}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Actualizar interactivamente el ecosistema MK-Director y auditar riesgos de compatibilidad';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("\n🚀 Iniciando actualización interactiva de MK-Director...\n");

        $oldVersion = $this->getInstalledVersion();
        $this->comment("Versión instalada actual: {$oldVersion}");

        if ($this->option('dry-run')) {
            $this->comment("[Simulación] Se buscarían actualizaciones en Packagist.");
        } else {
            if ($this->confirm('¿Querés buscar actualizaciones y actualizar el paquete makroz/director-laravel a la última versión estable?', true)) {
                $this->runComposerUpdate();
            }
        }

        // Re-read installed version after possible update
        $newVersion = $this->getInstalledVersion();
        
        if ($oldVersion !== $newVersion && $newVersion !== 'unknown' && $oldVersion !== 'unknown') {
            $this->info("📈 Transición de versión: {$oldVersion} -> {$newVersion}");
        } else {
            $this->info("✅ Versión del paquete: {$newVersion} (sin cambios).");
        }

        // 1. Verificar base de datos y correr migraciones evolutivas
        $this->runDatabaseMigrationsPipeline();

        // 2. Ejecutar las migraciones estándar de Laravel
        if (!$this->option('dry-run')) {
            $this->info("Corriendo migraciones pendientes de Laravel...");
            $this->call('migrate');
        }

        // 3. Auditoría de código estático (Riesgos de upgrade)
        $this->auditCodebaseRisks();

        // 4. Verificación de salud final
        $this->info("\nRunning final health check...");
        $this->call('mk:status');

        $this->info("🏁 Proceso de actualización finalizado.\n");
    }

    /**
     * Devuelve la versión instalada actualmente leyendo los metadatos de Composer.
     */
    protected function getInstalledVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('makroz/director-laravel')) {
                return InstalledVersions::getPrettyVersion('makroz/director-laravel');
            }
        } catch (\Throwable) {
            // fallback
        }
        return 'unknown';
    }

    /**
     * Ejecuta el comando composer update en segundo plano.
     */
    protected function runComposerUpdate()
    {
        $this->comment("Ejecutando 'composer update makroz/director-laravel'...");

        try {
            // Usamos Symfony Process nativo en Laravel/Illuminate
            $process = new Process(['composer', 'update', 'makroz/director-laravel']);
            $process->setTimeout(300); // 5 minutos de tiempo de espera
            
            $process->start();

            $this->output->write('Descargando y actualizando dependencias... ');
            while ($process->isRunning()) {
                $this->output->write('.');
                usleep(1000000); // esperar 1 segundo
            }
            $this->line('');

            if ($process->isSuccessful()) {
                $this->info("✅ Composer se ejecutó correctamente.");
            } else {
                $this->error("❌ Error al ejecutar composer update:");
                $this->line($process->getErrorOutput());
                $this->line($process->getOutput());
            }
        } catch (\Throwable $e) {
            $this->error("❌ No se pudo ejecutar composer de forma automática: " . $e->getMessage());
            $this->comment("Por favor, corre 'composer update makroz/director-laravel' manualmente en tu terminal.");
        }
    }

    /**
     * Ejecuta el pipeline de migraciones evolutivas basadas en el estado del esquema.
     */
    protected function runDatabaseMigrationsPipeline()
    {
        $this->info("Verificando estado del esquema de base de datos...");

        if (!Schema::hasTable('auth_users')) {
            $this->info("La tabla 'auth_users' no existe aún. Las migraciones se crearán con el último formato directamente.");
            return;
        }

        $idColumn = $this->getColumnInfo('auth_users', 'id');
        if (!$idColumn) {
            $this->error("La columna 'id' no fue encontrada en la tabla 'auth_users'.");
            return;
        }

        $type = strtolower($idColumn['type'] ?? '');
        $isUuid = ($type === 'uuid') || (str_contains($type, 'char') && ($idColumn['length'] ?? 0) === 36);

        if ($isUuid) {
            $this->info("✅ La base de datos ya utiliza el esquema UUID (v1.2+).");
            return;
        }

        if (str_contains($type, 'int')) {
            $this->warn("⚠️ DETECTADO: El esquema de base de datos actual es v1.1 (BIGINT id).");
            $this->warn("Es necesario migrar 'auth_users.id' a UUID (CHAR 36) para la v1.2.");
            $this->error("🚨 ADVERTENCIA: Esta migración es IRREVERSIBLE y reescribirá la columna de claves primarias.");

            if ($this->option('dry-run')) {
                $this->comment("[Simulación] Se ejecutaría la migración a UUID.");
                return;
            }

            if (!$this->confirm('¿Tenés un backup completo y actualizado de tu base de datos?', false)) {
                $this->error("Actualización cancelada. Por favor, realizá un backup antes de continuar.");
                exit(1);
            }

            if (!$this->confirm('¿Confirmás que querés proceder con la migración a UUID de auth_users.id?', false)) {
                $this->comment("Actualización cancelada.");
                exit(1);
            }

            $this->executeUuidMigration();
        } else {
            $this->error("Tipo de columna inesperado en auth_users.id: '{$type}'. No se puede realizar la auto-migración.");
        }
    }

    /**
     * Ejecuta los pasos para migrar de BIGINT a UUID.
     */
    protected function executeUuidMigration()
    {
        $this->comment("Ejecutando migración de BIGINT a UUID...");

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            DB::transaction(function () use ($connection, $driver) {
                // Paso 1: Agregar columna temporal id_uuid
                if (!Schema::hasColumn('auth_users', 'id_uuid')) {
                    $connection->statement("ALTER TABLE `auth_users` ADD COLUMN `id_uuid` CHAR(36) NULL");
                }

                // Paso 2: Generar UUIDs para filas existentes
                $uuidExpr = $driver === 'pgsql' ? 'gen_random_uuid()' : 'UUID()';
                $connection->statement("UPDATE `auth_users` SET `id_uuid` = {$uuidExpr} WHERE `id_uuid` IS NULL");

                // Paso 3: Dropear la clave primaria anterior y columna
                if ($driver === 'mysql') {
                    $connection->statement("ALTER TABLE `auth_users` DROP PRIMARY KEY");
                }
                $connection->statement("ALTER TABLE `auth_users` DROP COLUMN `id`");

                // Paso 4: Renombrar id_uuid a id
                if ($driver === 'mysql') {
                    $connection->statement("ALTER TABLE `auth_users` CHANGE COLUMN `id_uuid` `id` CHAR(36) NOT NULL");
                } else {
                    $connection->statement("ALTER TABLE `auth_users` RENAME COLUMN `id_uuid` TO `id`");
                    $connection->statement("ALTER TABLE `auth_users` ALTER COLUMN `id` SET NOT NULL");
                }

                // Paso 5: Agregar restricción de clave primaria
                $connection->statement("ALTER TABLE `auth_users` ADD PRIMARY KEY (`id`)");
            });

            $this->info("✅ Migración de base de datos a UUID completada con éxito.");
        } catch (\Throwable $e) {
            $this->error("❌ ERROR durante la migración a UUID: " . $e->getMessage());
            $this->error("Restaurá tu base de datos a partir del backup antes de intentar de nuevo.");
            exit(1);
        }
    }

    /**
     * Analiza el código del proyecto cliente buscando riesgos de compatibilidad.
     */
    protected function auditCodebaseRisks()
    {
        $this->info("\n🔍 Auditando código del proyecto buscando riesgos de compatibilidad...");

        $hasWarnings = false;

        // 1. Escanear modelos con HasTenantScope sin propiedad usesTenant
        $modelsPath = app_path();
        if (File::exists($modelsPath)) {
            $files = File::allFiles($modelsPath);
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getRealPath());
                if (str_contains($content, 'use HasTenantScope')) {
                    if (!str_contains($content, '$usesTenant') && !str_contains($content, 'protected static bool $usesTenant')) {
                        $className = $this->getClassNameFromFile($file->getRealPath(), $content);
                        $this->warn("⚠️  [Riesgo Tenancy] El modelo '{$className}' usa HasTenantScope pero no define \$usesTenant.");
                        $this->line("    -> En v1.2+ el tenant es opt-in. Para mantener el comportamiento anterior, agrega: protected static bool \$usesTenant = true;");
                        $hasWarnings = true;
                    }
                }
            }
        }

        // 2. Escanear middleware mk.ability vacíos en las rutas
        $routesPath = base_path('routes');
        if (File::exists($routesPath)) {
            $files = File::allFiles($routesPath);
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getRealPath());
                if (preg_match('/mk\.ability\s*:\s*[\'"]\s*[\'"]/i', $content) || str_contains($content, "mk.ability:''") || str_contains($content, 'mk.ability:""')) {
                    $this->error("❌ [Error Middleware] Uso de mk.ability sin permisos asociados en {$file->getRelativePathname()}.");
                    $this->line("    -> Esto provocará un error HTTP 500 en v1.2+. Especificá al menos una habilidad.");
                    $hasWarnings = true;
                }
            }
        }

        if (!$hasWarnings) {
            $this->info("✅ No se detectaron incompatibilidades o riesgos en el código fuente.");
        }
    }

    /**
     * Obtener el nombre completo de la clase a partir del archivo.
     */
    protected function getClassNameFromFile(string $path, string $content): string
    {
        $namespace = '';
        if (preg_match('/namespace\s+(.+?);/', $content, $matches)) {
            $namespace = $matches[1];
        }
        $class = str_replace('.php', '', basename($path));
        return $namespace ? $namespace . '\\' . $class : $class;
    }

    /**
     * Obtener información sobre el tipo de columna.
     */
    protected function getColumnInfo(string $table, string $column): ?array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();

        if ($driver === 'mysql') {
            $rows = $connection->select(
                'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS char_length
                 FROM information_schema.columns
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$database, $table, $column]
            );
            if (empty($rows)) {
                return null;
            }
            $row = (array) $rows[0];
            return [
                'type' => strtolower((string) ($row['data_type'] ?? '')),
                'length' => isset($row['char_length']) ? (int) $row['char_length'] : null,
            ];
        }

        if ($driver === 'pgsql') {
            $rows = $connection->select(
                'SELECT data_type, character_maximum_length
                 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
            if (empty($rows)) {
                return null;
            }
            $row = (array) $rows[0];
            return [
                'type' => strtolower((string) ($row['data_type'] ?? '')),
                'length' => isset($row['character_maximum_length']) ? (int) $row['character_maximum_length'] : null,
            ];
        }

        if ($driver === 'sqlite') {
            $connection = DB::connection();
            $rows = $connection->select("PRAGMA table_info({$table})");
            foreach ($rows as $row) {
                $row = (array) $row;
                if (strtolower((string) ($row['name'] ?? '')) === strtolower($column)) {
                    return [
                        'type' => strtolower((string) ($row['type'] ?? '')),
                        'length' => null,
                    ];
                }
            }
        }

        return null;
    }
}
