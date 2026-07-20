<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Enums\ScopeStatus;
use Throwable;

/**
 * MkMigrateIsActiveToStatusCommand — R-PKG-047 D4 migration helper.
 *
 * Convierte la columna `is_active` boolean de un scope pre-D4 a la columna
 * `status` INT-backed (4 estados canónicos pineados por `ScopeStatus`).
 *
 * NOTA (revert 2026-07-19): entre R-PKG-047 D4 y esta fecha, este comando
 * escribía una columna `ENUM(...)` string. Ver el docblock de `ScopeStatus`
 * para el análisis. Si ya corriste este comando en su versión string, usá
 * `php artisan mk:migrate-status-to-int {Scope}` para terminar de convertir.
 *
 * Pipeline:
 *   1. Resolver tabla del scope (`{moduleNamePluralLower}`) via argumento.
 *   2. Validar schema:
 *      - Tabla existe?
 *      - Tiene `is_active` boolean?
 *      - NO tiene `status` enum ya (idempotente: skip si existe).
 *   3. ALTER TABLE:
 *      - `is_active = true` → `status = ScopeStatus::Active->value` (1)
 *      - `is_active = false` → `status = ScopeStatus::Inactive->value` (2)
 *      - Renombrar columna a `status` (no se borra el dato, se conserva).
 *      - Si se pasa `--drop-is-active`, drop la columna original (BC: default false).
 *
 * BC: la columna `is_active` se renombra a `status` (NO se borra salvo flag
 * `--drop-is-active`). Consumers pre-D4 que pineaban queries a `is_active`
 * deben migrar a `status = ScopeStatus::Active->value`. Helper opcional.
 *
 * Uso:
 *   php artisan mk:migrate-is-active Admin
 *   php artisan mk:migrate-is-active Member --drop-is-active
 *
 * O `--all` para migrar todos los scopes detectados (vía `app_path('Modules')`).
 */
class MkMigrateIsActiveToStatusCommand extends Command
{
    protected $signature = 'mk:migrate-is-active {scope : Nombre del scope en StudlyCase (ej: Admin, Member). Default: detectar todos los scopes si --all.}
        {--all : Procesar todos los scopes detectados en app/Modules/}
        {--drop-is-active : Borrar la columna is_active después de renombrar (NO recomendado sin audit previo de queries custom).}
        {--dry-run : Solo mostrar el plan SQL sin ejecutar (recomendado pre-prod).}';

    protected $description = 'Migra la columna is_active boolean a status int-backed (4 estados canónicos pineados por ScopeStatus).';

    public function handle(): int
    {
        $scopes = $this->resolveScopes();
        if ($scopes === []) {
            $this->error('No se detectaron scopes. Pasá uno explícito (ej: `Admin`) o usá `--all` (con scopes en app/Modules/).');

            return self::FAILURE;
        }

        $totalConverted = 0;
        foreach ($scopes as $scope) {
            $converted = $this->migrateScope($scope);
            if ($converted) {
                $totalConverted++;
            }
        }

        $this->newLine();
        if ($totalConverted > 0) {
            $this->info("✅ {$totalConverted} scope(s) migrado(s). Pre-bumpear `php artisan mk:discover-abilities --force` si pineá nuevas abilities.");
        } else {
            $this->warn('⚠️  Ningún scope requirió migración (todos ya tienen `status` enum o no tienen `is_active`).');
        }

        return self::SUCCESS;
    }

    /**
     * Migra un scope individual.
     *
     * @return bool `true` si se ejecutó la migración, `false` si se skippeó.
     */
    private function migrateScope(string $scope): bool
    {
        $table = strtolower($scope) . 's'; // Convention: plural snake_case.

        $this->newLine();
        $this->info("🔧 Procesando scope: {$scope} (tabla `{$table}`)");

        if (! Schema::hasTable($table)) {
            $this->warn("   ⚠️  Tabla `{$table}` no existe. Skip.");

            return false;
        }

        if (! Schema::hasColumn($table, 'is_active')) {
            $this->warn("   ⚠️  Tabla `{$table}` no tiene columna `is_active`. Skip (probablemente ya migrado).");

            return false;
        }

        if (Schema::hasColumn($table, 'status')) {
            $this->warn("   ⚠️  Tabla `{$table}` ya tiene columna `status`. Skip (idempotente — no se duplica).");

            return false;
        }

        // Compute counts before ALTER (audit log).
        try {
            $trueCount = (int) DB::table($table)->where('is_active', true)->count();
            $falseCount = (int) DB::table($table)->where('is_active', false)->count();
            $nullCount = (int) DB::table($table)->whereNull('is_active')->count();
        } catch (Throwable $e) {
            $this->error("   ❌ Error contando rows: {$e->getMessage()}");

            return false;
        }

        $this->line("   📊 Pre-migration: {$trueCount} activos, {$falseCount} inactivos, {$nullCount} null.");

        // Los valores salen del enum y no van hardcodeados: si algún día se
        // renumeran los cases, este comando sigue escribiendo lo correcto.
        // (Distinto criterio que el de las migraciones generadas, que sí van
        // con el literal porque son artefactos congelados en el tiempo.)
        $active = ScopeStatus::Active->value;
        $inactive = ScopeStatus::Inactive->value;

        if ($this->option('dry-run')) {
            $this->warn('   💧 DRY-RUN: no se ejecutaron cambios. SQL plan abajo:');
            $this->line("      ALTER TABLE `{$table}` ADD COLUMN `status` TINYINT UNSIGNED NOT NULL DEFAULT {$active} AFTER `is_active`;");
            $this->line("      UPDATE `{$table}` SET `status` = {$active} WHERE `is_active` = 1;");
            $this->line("      UPDATE `{$table}` SET `status` = {$inactive} WHERE `is_active` = 0;");
            if ($this->option('drop-is-active')) {
                $this->line("      ALTER TABLE `{$table}` DROP COLUMN `is_active`;");
            } else {
                $this->line("      -- Columna `is_active` se conserva por BC. Drop manual con `--drop-is-active`.");
            }

            return true;  // DRY-RUN cuenta como "procesado" para sumar al total.
        }

        try {
            // Step 1: agregar columna TINYINT con default Active (BC: sin romper
            // inserts existentes). Revert 2026-07-19: antes era una columna
            // ENUM string. Además de la razón de fondo (ver el docblock de
            // ScopeStatus), el ENUM traía dos problemas propios: no lo soporta
            // mysql < 5.7, y el literal que se escribía acá incluía
            // 'suspended', un estado que el enum PHP ya no define — la columna
            // aceptaba un valor que la app no podía castear.
            DB::statement("ALTER TABLE `{$table}` ADD COLUMN `status` TINYINT UNSIGNED NOT NULL DEFAULT {$active} AFTER `is_active`");

            // Step 2: migrar data — true → Active, false → Inactive.
            // Null/otros quedan en el default de la columna (Active).
            DB::statement("UPDATE `{$table}` SET `status` = {$active} WHERE `is_active` = 1");
            DB::statement("UPDATE `{$table}` SET `status` = {$inactive} WHERE `is_active` = 0");

            // Step 3 (opcional): drop la columna original.
            if ($this->option('drop-is-active')) {
                DB::statement("ALTER TABLE `{$table}` DROP COLUMN `is_active`");
                $this->line('   🗑️  Columna `is_active` eliminada (flag --drop-is-active).');
            } else {
                $this->line('   ♻️  Columna `is_active` CONSERVADA por BC. Drop manual con `--drop-is-active` o auditoría primero.');
            }

            // Step 4: índice en `status` para queries rápidas (status_id LIKE scopes).
            if (! $this->indexExists($table, 'status')) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_status_index` (`status`)");
                $this->line('   📇 Índice `status` agregado.');
            }

            $this->info("   ✅ Scope `{$scope}` migrado exitosamente a `status` enum.");

            return true;
        } catch (Throwable $e) {
            $this->error("   ❌ Error ejecutando migración: {$e->getMessage()}");
            $this->warn('   ⚠️  La tabla puede estar en estado parcial. Verificá con `SHOW CREATE TABLE {$table}` y cleanup manual.');

            return false;
        }
    }

    /**
     * Resolver lista de scopes: explícito, --all (escanea app/Modules), o si
     * no se pasa ninguno, intentar auto-detectar.
     *
     * @return string[] Lista de scopes en StudlyCase.
     */
    private function resolveScopes(): array
    {
        $scopeArg = trim((string) $this->argument('scope'));

        if ($scopeArg !== '') {
            return [$this->studly($scopeArg)];
        }

        if ($this->option('all')) {
            return $this->detectAllScopes();
        }

        return [];
    }

    /**
     * Escanea `app_path('Modules')` y devuelve StudlyCase de cada subdir directo.
     *
     * @return string[]
     */
    private function detectAllScopes(): array
    {
        if (! function_exists('app_path')) {
            return [];
        }

        $modulesPath = app_path('Modules');
        if (! is_dir($modulesPath)) {
            return [];
        }

        $scopes = [];
        foreach (scandir($modulesPath) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $modulesPath . DIRECTORY_SEPARATOR . $entry;
            if (! is_dir($fullPath)) {
                continue;
            }
            // Filtrar directorios que NO son scopes (Common, Shared, etc.) — heurística:
            // cada scope scaffoldeado tiene `Models/`, `Enums/`, o `Http/`.
            if (! is_dir($fullPath . '/Models')
                && ! is_dir($fullPath . '/Http')
                && ! is_dir($fullPath . '/Enums')
            ) {
                continue;
            }
            $scopes[] = $this->studly($entry);
        }

        sort($scopes);

        return $scopes;
    }

    /**
     * StudlyCase snake_case-skipping helper que pinea primer letra uppercase
     * y conserva el resto lowercase (similar a `Str::studly()` de Laravel pero
     * sin requerir facade en scripts CLI minimalistas).
     */
    private function studly(string $raw): string
    {
        $parts = preg_split('/[_\-\s]+/', $raw) ?: [];
        $studly = '';
        foreach ($parts as $part) {
            $studly .= ucfirst(strtolower($part));
        }

        return $studly;
    }

    /**
     * Helper: chequea si un índice existe en una tabla. Evita error de
     * `ADD INDEX` cuando ya existe el índice (depende del driver).
     */
    private function indexExists(string $table, string $column): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$column]);
            return count($indexes) > 0;
        } catch (Throwable) {
            // Driver no soporta SHOW INDEX (SQLite no, por ej). Asumir false
            // y dejar que ADD INDEX falle (mongo el warning y seguimos).
            return false;
        }
    }
}
