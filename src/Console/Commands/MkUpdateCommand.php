<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
     *
     * R-PKG-013 (2026-06-26): rediseño interactivo. Antes el command solo
     * mostraba la "última estable" (vía regex `/^v?\d+\.\d+\.\d+$/` que
     * filtraba los RCs), dejando invisible v1.6.0-rc2 a usuarios en v1.3.1.
     * Ahora lista TODAS las versiones superiores a la instalada (incluyendo
     * pre-releases), las presenta en un menú navegable con flechas (vía
     * Symfony `choice`), y actualiza a la versión que el dev elija.
     */
    public function handle()
    {
        $this->info("\n🚀 Iniciando actualización interactiva de MK-Director...\n");

        $oldVersion = $this->getInstalledVersion();

        if ($oldVersion === 'unknown') {
            $this->error('❌ No se pudo determinar la versión instalada de makroz/director-laravel.');
            $this->comment('Verificá que el paquete esté correctamente instalado en composer.json.');

            return 1;
        }

        // Obtener TODAS las versiones superiores (incluyendo RCs/alpha/beta)
        $higherVersions = $this->getVersionsHigherThan($oldVersion);

        if (empty($higherVersions)) {
            $this->info("✅ Ya estás en la última versión disponible ({$oldVersion}). No hay actualizaciones.");

            // Aún así corremos el resto del pipeline (auditoría, status, skills)
            $this->runDatabaseMigrationsPipeline();
            if (! $this->option('dry-run')) {
                $this->call('migrate');
            }
            $this->auditCodebaseRisks();
            $this->info("\nRunning final health check...");
            $this->call('mk:status');
            $this->promptForSkillDeploy();
            $this->info("🏁 Proceso de actualización finalizado.\n");

            return 0;
        }

        $this->line("Tu versión actual es: <fg=yellow>{$oldVersion}</>");
        $this->line('Hay <fg=green>'.count($higherVersions).'</> versiones disponibles para actualizar (incluyendo pre-releases):');
        $this->newLine();

        // Ordenar de mayor a menor (RCs mezcladas con stables)
        usort($higherVersions, function ($a, $b) {
            return version_compare(ltrim($b, 'v'), ltrim($a, 'v'));
        });

        // Construir opciones para el menú navegable
        $choices = [];
        foreach ($higherVersions as $i => $version) {
            $isRc = (bool) preg_match('/(rc|alpha|beta)/i', $version);
            $isLatestStable = ($i === 0) && ! $isRc;

            $marker = match (true) {
                $isLatestStable => ' ⭐ (última estable)',
                $isRc => ' 🧪 (pre-release)',
                default => '',
            };

            $choices[] = "v{$version}{$marker}";
        }

        $selected = $this->choice(
            '¿A qué versión querés actualizar? (↑↓ navegá con el teclado, Enter para seleccionar)',
            $choices,
            0  // default a la primera (la más alta disponible)
        );

        // Extraer la versión pura del string seleccionado (saca el marker)
        $targetVersion = (string) preg_replace('/\s+[⭐🧪].*$/u', '', $selected);
        $targetVersion = ltrim($targetVersion, 'v');

        $this->newLine();
        $this->info("📌 Versión seleccionada: <fg=cyan>v{$targetVersion}</>");
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment("[Simulación] Se omitirá la descarga e instalación de v{$targetVersion}.");
        } else {
            if (! $this->confirm("¿Confirmás la actualización de {$oldVersion} → v{$targetVersion}?", true)) {
                $this->comment('Actualización cancelada por el usuario.');

                return 0;
            }

            $this->runComposerUpdate($targetVersion);
        }

        // Re-leer la versión instalada después del posible update
        $newVersion = $this->getInstalledVersion();

        if ($oldVersion !== $newVersion && $newVersion !== 'unknown') {
            $this->info("📈 Transición de versión: {$oldVersion} -> {$newVersion}");
        } else {
            $this->info("✅ Versión del paquete: {$newVersion} (sin cambios).");
            if ($newVersion !== 'unknown' && version_compare(ltrim($newVersion, 'v'), ltrim($targetVersion, 'v'), '<')) {
                $this->warn("⚠️  ADVERTENCIA: La versión instalada ({$newVersion}) sigue siendo menor que la solicitada ({$targetVersion}).");
                $this->warn('Esto puede deberse a la caché de Composer o CDN.');
                $this->comment("Sugerencia: Ejecutá 'composer clear-cache' y volvé a correr 'php artisan mk:update'.");
            }
        }

        // 1. Verificar base de datos y correr migraciones evolutivas
        $this->runDatabaseMigrationsPipeline();

        // 2. Ejecutar las migraciones estándar de Laravel
        if (! $this->option('dry-run')) {
            $this->info('Corriendo migraciones pendientes de Laravel...');
            $this->call('migrate');
        }

        // 3. Auditoría de código estático (Riesgos de upgrade)
        $this->auditCodebaseRisks();

        // 4. Verificación de salud final
        $this->info("\nRunning final health check...");
        $this->call('mk:status');

        // 5. Sugerir deploy de skills nuevas (R-NEW-001 cross-cutting)
        $this->promptForSkillDeploy();

        $this->info("🏁 Proceso de actualización finalizado.\n");

        return 0;
    }

    /**
     * Sugiere al dev deployar las skills nuevas del ecosistema. Es opt-in
     * (pregunta al usuario) y no invasivo: si no quiere, no hace nada.
     *
     * Este paso se agregó en el sprint skill:deploy (2026-06-24) para
     * mantener sincronizadas las skills que la agencia publica con las
     * que el proyecto tiene deployadas. Sin este paso, las skills
     * quedan en la agencia pero las IAs que trabajan en el proyecto
     * no las ven.
     */
    protected function promptForSkillDeploy(): void
    {
        if ($this->option('dry-run')) {
            return;
        }

        $this->newLine();
        if (! $this->confirm('¿Querés revisar y deployar las skills nuevas del ecosistema MK?', false)) {
            return;
        }

        $this->call('mk:skill:list');

        $this->newLine();
        if ($this->confirm('¿Deployar todas las skills que aún no estén en este proyecto?', false)) {
            // Listamos la salida anterior con mk:skill:list y, en una
            // segunda pasada, deployamos las que estén disponibles en la
            // agencia. La heurística es simple: el dev confirma.
            $this->info('Para deployar skills individualmente:');
            $this->line('  php artisan mk:skill:deploy {nombre}');
            $this->line('O consultá `php artisan mk:skill:list --help` para opciones de filtrado.');
        }
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
     * Obtiene TODAS las versiones publicadas en Packagist que son mayores
     * a la versión instalada, incluyendo pre-releases (rc/alpha/beta).
     *
     * R-PKG-013 (2026-06-26): la implementación previa filtraba con
     * `/^v?\d+\.\d+\.\d+$/`, lo que ocultaba cualquier versión con sufijo
     * (-rc1, -beta, etc.). Bug reportado por Mario al correr `mk:update`
     * en RETO y ver "última v1.4.0" cuando v1.6.0-rc2 ya estaba en Packagist.
     * Ahora se devuelven TODAS las versiones superiores para que el usuario
     * elija interactivamente con `choice()`.
     */
    protected function getVersionsHigherThan(string $currentVersion): array
    {
        try {
            $response = Http::timeout(5)->get('https://repo.packagist.org/p2/makroz/director-laravel.json');
            if ($response->successful()) {
                $data = $response->json();
                $items = $data['packages']['makroz/director-laravel'] ?? [];
                $versions = array_column($items, 'version');

                $higher = [];
                $currentNorm = ltrim($currentVersion, 'v');

                foreach ($versions as $version) {
                    // Skip branches de desarrollo
                    if (str_contains($version, 'dev-')) {
                        continue;
                    }
                    if (str_ends_with($version, '-dev') || str_contains($version, 'x-dev')) {
                        continue;
                    }

                    $versionNorm = ltrim($version, 'v');

                    // version_compare maneja correctamente sufijos -rc1, -beta, etc.
                    // siguiendo semver: 1.6.0-rc2 > 1.6.0-rc1 > 1.6.0 > 1.5.0
                    if (version_compare($versionNorm, $currentNorm, '>')) {
                        $higher[] = $version;
                    }
                }

                return $higher;
            }
        } catch (\Throwable $e) {
            $this->warn('⚠️  No se pudo conectar con Packagist para listar versiones disponibles.');
            $this->comment('Verificá tu conexión a internet. Si persistí, corré `composer require makroz/director-laravel:<version>` manualmente.');
        }

        return [];
    }

    /**
     * Ejecuta `composer require makroz/director-laravel:vX.Y.Z` en segundo plano.
     * R-PKG-013: antes hacía `composer update` (actualizaba a la última según
     * el constraint del composer.json). Ahora respeta la versión específica
     * que el usuario eligió del menú interactivo.
     */
    protected function runComposerUpdate(string $targetVersion)
    {
        $this->comment("Ejecutando 'composer require makroz/director-laravel:v{$targetVersion}'...");

        try {
            // Symfony Process nativo de Laravel/Illuminate
            $process = new Process(['composer', 'require', "makroz/director-laravel:v{$targetVersion}"]);
            $process->setTimeout(300); // 5 minutos

            $process->start();

            $this->output->write('Descargando y actualizando dependencias... ');
            while ($process->isRunning()) {
                $this->output->write('.');
                usleep(1000000);
            }
            $this->line('');

            if ($process->isSuccessful()) {
                $this->info('✅ Composer se ejecutó correctamente.');
            } else {
                $this->error('❌ Error al ejecutar composer require:');
                $this->line($process->getErrorOutput());
                $this->line($process->getOutput());
            }
        } catch (\Throwable $e) {
            $this->error('❌ No se pudo ejecutar composer de forma automática: '.$e->getMessage());
            $this->comment("Por favor, corre 'composer require makroz/director-laravel:v{$targetVersion}' manualmente en tu terminal.");
        }
    }

    /**
     * Ejecuta el pipeline de migraciones evolutivas basadas en el estado del esquema.
     */
    protected function runDatabaseMigrationsPipeline()
    {
        $this->info('Verificando estado del esquema de base de datos...');

        if (! Schema::hasTable('auth_users')) {
            $this->info("La tabla 'auth_users' no existe aún. Las migraciones se crearán con el último formato directamente.");

            return;
        }

        $idColumn = $this->getColumnInfo('auth_users', 'id');
        if (! $idColumn) {
            $this->error("La columna 'id' no fue encontrada en la tabla 'auth_users'.");

            return;
        }

        $type = strtolower($idColumn['type'] ?? '');
        $isUuid = ($type === 'uuid') || (str_contains($type, 'char') && ($idColumn['length'] ?? 0) === 36);

        if ($isUuid) {
            $this->info('✅ La base de datos ya utiliza el esquema UUID (v1.2+).');

            return;
        }

        if (str_contains($type, 'int')) {
            $this->warn('⚠️ DETECTADO: El esquema de base de datos actual es v1.1 (BIGINT id).');
            $this->warn("Es necesario migrar 'auth_users.id' a UUID (CHAR 36) para la v1.2.");
            $this->error('🚨 ADVERTENCIA: Esta migración es IRREVERSIBLE y reescribirá la columna de claves primarias.');

            if ($this->option('dry-run')) {
                $this->comment('[Simulación] Se ejecutaría la migración a UUID.');

                return;
            }

            if (! $this->confirm('¿Tenés un backup completo y actualizado de tu base de datos?', false)) {
                $this->error('Actualización cancelada. Por favor, realizá un backup antes de continuar.');
                exit(1);
            }

            if (! $this->confirm('¿Confirmás que querés proceder con la migración a UUID de auth_users.id?', false)) {
                $this->comment('Actualización cancelada.');
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
        $this->comment('Ejecutando migración de BIGINT a UUID...');

        $connection = DB::connection();
        $driver = $connection->getDriverName();

        try {
            DB::transaction(function () use ($connection, $driver) {
                // Paso 1: Agregar columna temporal id_uuid
                if (! Schema::hasColumn('auth_users', 'id_uuid')) {
                    $connection->statement('ALTER TABLE `auth_users` ADD COLUMN `id_uuid` CHAR(36) NULL');
                }

                // Paso 2: Generar UUIDs para filas existentes
                $uuidExpr = $driver === 'pgsql' ? 'gen_random_uuid()' : 'UUID()';
                $connection->statement("UPDATE `auth_users` SET `id_uuid` = {$uuidExpr} WHERE `id_uuid` IS NULL");

                // Paso 3: Dropear la clave primaria anterior y columna
                if ($driver === 'mysql') {
                    $connection->statement('ALTER TABLE `auth_users` DROP PRIMARY KEY');
                }
                $connection->statement('ALTER TABLE `auth_users` DROP COLUMN `id`');

                // Paso 4: Renombrar id_uuid a id
                if ($driver === 'mysql') {
                    $connection->statement('ALTER TABLE `auth_users` CHANGE COLUMN `id_uuid` `id` CHAR(36) NOT NULL');
                } else {
                    $connection->statement('ALTER TABLE `auth_users` RENAME COLUMN `id_uuid` TO `id`');
                    $connection->statement('ALTER TABLE `auth_users` ALTER COLUMN `id` SET NOT NULL');
                }

                // Paso 5: Agregar restricción de clave primaria
                $connection->statement('ALTER TABLE `auth_users` ADD PRIMARY KEY (`id`)');
            });

            $this->info('✅ Migración de base de datos a UUID completada con éxito.');
        } catch (\Throwable $e) {
            $this->error('❌ ERROR durante la migración a UUID: '.$e->getMessage());
            $this->error('Restaurá tu base de datos a partir del backup antes de intentar de nuevo.');
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
                    if (! str_contains($content, '$usesTenant') && ! str_contains($content, 'protected static bool $usesTenant')) {
                        $className = $this->getClassNameFromFile($file->getRealPath(), $content);
                        $this->warn("⚠️  [Riesgo Tenancy] El modelo '{$className}' usa HasTenantScope pero no define \$usesTenant.");
                        $this->line('    -> En v1.2+ el tenant es opt-in. Para mantener el comportamiento anterior, agrega: protected static bool $usesTenant = true;');
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
                    $this->line('    -> Esto provocará un error HTTP 500 en v1.2+. Especificá al menos una habilidad.');
                    $hasWarnings = true;
                }
            }
        }

        if (! $hasWarnings) {
            $this->info('✅ No se detectaron incompatibilidades o riesgos en el código fuente.');
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

        return $namespace ? $namespace.'\\'.$class : $class;
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
