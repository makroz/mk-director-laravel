<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Enums\ScopeStatus;
use Throwable;
use ValueError;

/**
 * MkMigrateStatusToIntCommand — revert helper del backing type de `status`.
 *
 * Convierte la columna `status` STRING (la que dejó R-PKG-047 D4) a
 * INT-backed, alineada con `ScopeStatus` post-revert del 2026-07-19.
 * Ver el docblock de `ScopeStatus` para por qué se revirtió.
 *
 * SEGURIDAD — el orden de los pasos no es negociable
 * --------------------------------------------------
 * La conversión mapea cada value string al case int vía
 * `ScopeStatus::fromLegacyString()`. Si aparece un value que NO se puede
 * mapear (un estado custom que el consumer agregó post-scaffold, un typo,
 * un NULL en una columna que debería ser NOT NULL), el comando **aborta
 * ANTES de tocar la columna vieja** y lista los values huérfanos.
 *
 * Es la diferencia entre "no migré" y "perdí data": si convirtiéramos
 * primero y validáramos después, cada fila no mapeada terminaría en el
 * default y el usuario bloqueado pasaría a activo en silencio. Un fallo
 * ruidoso es infinitamente mejor que un `Blocked` que se volvió `Active`.
 *
 * Acepta también `'suspended'`, el nombre que tuvo el tercer estado antes
 * de que R-PKG-050 (F10-B12) lo renombrara a `'blocked'`.
 *
 * Uso:
 *   php artisan mk:migrate-status-to-int Admin --dry-run
 *   php artisan mk:migrate-status-to-int Admin
 *   php artisan mk:migrate-status-to-int --all
 */
class MkMigrateStatusToIntCommand extends Command
{
    protected $signature = 'mk:migrate-status-to-int {scope? : Nombre del scope en StudlyCase (ej: Admin, Member).}
        {--all : Procesar todos los scopes detectados en app/Modules/}
        {--dry-run : Solo mostrar el plan sin ejecutar (recomendado pre-prod).}';

    protected $description = 'Convierte la columna status string (R-PKG-047 D4) a int-backed, alineada con ScopeStatus.';

    public function handle(): int
    {
        $scopes = $this->resolveScopes();

        if ($scopes === []) {
            $this->error('No se detectaron scopes. Pasá uno explícito (ej: `Admin`) o usá `--all`.');

            return self::FAILURE;
        }

        $converted = 0;
        $failed = 0;

        foreach ($scopes as $scope) {
            $result = $this->migrateScope($scope);

            if ($result === false) {
                $failed++;
            } elseif ($result === true) {
                $converted++;
            }
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("❌ {$failed} scope(s) NO se migraron por values sin mapear. Nada se modificó en esos scopes.");

            return self::FAILURE;
        }

        if ($converted > 0) {
            $this->info("✅ {$converted} scope(s) convertido(s) a `status` int-backed.");
        } else {
            $this->warn('⚠️  Ningún scope requirió conversión (o ya estaban en int).');
        }

        return self::SUCCESS;
    }

    /**
     * Convierte un scope.
     *
     * @return bool|null `true` migrado, `false` abortado por data inválida,
     *                   `null` skippeado (no aplica).
     */
    private function migrateScope(string $scope): ?bool
    {
        $table = strtolower($scope).'s';

        $this->newLine();
        $this->info("🔧 Procesando scope: {$scope} (tabla `{$table}`)");

        if (! Schema::hasTable($table)) {
            $this->warn("   ⚠️  Tabla `{$table}` no existe. Skip.");

            return null;
        }

        if (! Schema::hasColumn($table, 'status')) {
            $this->warn("   ⚠️  Tabla `{$table}` no tiene columna `status`. Skip.");

            return null;
        }

        // Idempotencia: si ya es numérica, no hay nada que hacer.
        $type = strtolower((string) Schema::getColumnType($table, 'status'));

        if (in_array($type, ['integer', 'smallint', 'bigint', 'tinyint', 'decimal'], true)) {
            $this->warn("   ⚠️  `{$table}.status` ya es numérica ({$type}). Skip (idempotente).");

            return null;
        }

        // ---------------------------------------------------------------
        // PASO 1 — VALIDAR TODO ANTES DE TOCAR NADA.
        // ---------------------------------------------------------------
        $distinct = DB::table($table)->select('status')->distinct()->pluck('status');

        $map = [];
        $orphans = [];

        foreach ($distinct as $value) {
            if ($value === null) {
                $orphans[] = 'NULL';

                continue;
            }

            try {
                $map[(string) $value] = ScopeStatus::fromLegacyString((string) $value)->value;
            } catch (ValueError) {
                $orphans[] = (string) $value;
            }
        }

        if ($orphans !== []) {
            $this->error('   ❌ Hay values de `status` que no se pueden mapear al enum:');

            foreach ($orphans as $orphan) {
                $count = $orphan === 'NULL'
                    ? DB::table($table)->whereNull('status')->count()
                    : DB::table($table)->where('status', $orphan)->count();

                $this->line("      - `{$orphan}` ({$count} fila(s))");
            }

            $this->warn('   ⚠️  ABORTADO. No se modificó ninguna fila de este scope.');
            $this->line('      Resolvé esos values a mano (o extendé ScopeStatus::fromLegacyString) y volvé a correr.');

            return false;
        }

        foreach ($map as $from => $to) {
            $count = DB::table($table)->where('status', $from)->count();
            $this->line("   📊 `{$from}` → {$to} ({$count} fila(s))");
        }

        if ($this->option('dry-run')) {
            $this->warn('   💧 DRY-RUN: no se ejecutaron cambios.');

            return null;
        }

        // ---------------------------------------------------------------
        // PASO 2 — CONVERTIR. Recién acá, con todo validado.
        // ---------------------------------------------------------------
        $default = ScopeStatus::default()->value;

        try {
            DB::transaction(function () use ($table, $map, $default): void {
                // Columna temporal: convertir in-place cambiaría el tipo con
                // data string adentro, que cada motor resuelve distinto (y
                // MySQL, en no-strict mode, la volvería 0 sin chistar).
                Schema::table($table, function ($blueprint) use ($default): void {
                    $blueprint->unsignedTinyInteger('status_int')->default($default);
                });

                foreach ($map as $from => $to) {
                    DB::table($table)->where('status', $from)->update(['status_int' => $to]);
                }

                Schema::table($table, function ($blueprint): void {
                    $blueprint->dropColumn('status');
                });

                Schema::table($table, function ($blueprint): void {
                    $blueprint->renameColumn('status_int', 'status');
                });
            });

            if (! $this->indexExists($table, 'status')) {
                try {
                    Schema::table($table, function ($blueprint): void {
                        $blueprint->index('status');
                    });
                    $this->line('   📇 Índice `status` agregado.');
                } catch (Throwable) {
                    // El índice es una optimización, no parte del contrato:
                    // si el driver se queja, la conversión de data ya es válida.
                    $this->warn('   ⚠️  No se pudo crear el índice en `status` (no bloqueante).');
                }
            }

            $this->info("   ✅ `{$table}.status` convertida a int-backed.");

            return true;
        } catch (Throwable $e) {
            $this->error("   ❌ Error durante la conversión: {$e->getMessage()}");
            $this->warn('   ⚠️  La transacción hizo rollback. Verificá el estado de la tabla antes de reintentar.');

            return false;
        }
    }

    /**
     * @return string[]
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

            $fullPath = $modulesPath.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($fullPath)) {
                continue;
            }

            // Misma heurística que mk:migrate-is-active: un scope scaffoldeado
            // tiene Models/, Http/ o Enums/. Filtra Common/, Shared/, etc.
            if (! is_dir($fullPath.'/Models')
                && ! is_dir($fullPath.'/Http')
                && ! is_dir($fullPath.'/Enums')
            ) {
                continue;
            }

            $scopes[] = $this->studly($entry);
        }

        sort($scopes);

        return $scopes;
    }

    private function studly(string $raw): string
    {
        $parts = preg_split('/[_\-\s]+/', $raw) ?: [];
        $studly = '';

        foreach ($parts as $part) {
            $studly .= ucfirst(strtolower($part));
        }

        return $studly;
    }

    private function indexExists(string $table, string $column): bool
    {
        try {
            $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Column_name = ?", [$column]);

            return count($indexes) > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
