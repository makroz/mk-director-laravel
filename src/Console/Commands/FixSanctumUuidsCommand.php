<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * `php artisan mk:fix:sanctum-uuids` — parchea la migration de Sanctum para
 * usar `uuidMorphs()` en vez de `morphs()` cuando el consumer usa `HasUuids`.
 *
 * **Contexto** (R-PKG-015 BUG-NEW-09):
 *
 * Laravel Sanctum 4 publica por default la migration
 * `database/migrations/{ts}_create_personal_access_tokens_table.php` con
 * `$table->morphs('tokenable')`, que crea columnas `unsignedBigInteger`.
 * Esto es incompatible con consumers que usan el trait `HasUuids` en sus
 * modelos de AuthUser (RETO Bolivia, proyectos multi-tenant con UUIDs).
 *
 * **Síntoma sin este parche**:
 * ```
 * SQLSTATE[22P02]: Invalid text representation
 * invalid input syntax for type bigint: "019f05cf-417e-7018-aa28-3f4cf4f10c0d"
 * ```
 *
 * **Fix manual** (lo que el dev tenía que hacer a mano antes):
 * Cambiar `$table->morphs('tokenable')` por `$table->uuidMorphs('tokenable')`
 * en la migration publicada por `php artisan install:api`.
 *
 * **Este command automatiza el fix**:
 * 1. Busca la migration de Sanctum en `database/migrations/`.
 * 2. Detecta si usa `morphs('tokenable')` y la parchea a `uuidMorphs()`.
 * 3. Es idempotente: si ya está parcheada, no hace nada.
 * 4. Si la migration no existe, sugiere correr `php artisan install:api` primero.
 *
 * **Ortogonal** con el resto del paquete — NO modifica tablas ni modelos del paquete.
 * Solo parchea la migration de un vendor externo (Sanctum) que el consumer ya publicó.
 *
 * Spec: MK-LAR-1.6.0-rc5 (R-PKG-015 BUG-NEW-09).
 */
class FixSanctumUuidsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mk:fix:sanctum-uuids
        {--dry-run : Solo mostrar qué se cambiaría, no escribir el archivo}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Parchea la migration de Sanctum para usar uuidMorphs() en vez de morphs(). Necesario cuando el consumer usa HasUuids en sus modelos AuthUser. Idempotente.';

    public function handle(): int
    {
        $migrationsPath = database_path('migrations');
        $dryRun = (bool) $this->option('dry-run');

        if (! File::isDirectory($migrationsPath)) {
            $this->error("No se encontró el directorio de migrations: {$migrationsPath}");

            return self::FAILURE;
        }

        // Buscar el archivo de migration de Sanctum. El nombre default publicado
        // por `php artisan install:api` es `create_personal_access_tokens_table`.
        $candidates = File::files($migrationsPath);
        $target = null;

        foreach ($candidates as $candidate) {
            if (str_contains($candidate->getFilename(), 'create_personal_access_tokens_table')) {
                $target = $candidate->getPathname();
                break;
            }
        }

        if ($target === null) {
            $this->error('No se encontró la migration de Sanctum (create_personal_access_tokens_table) en database/migrations/.');
            $this->newLine();
            $this->line('Pasos sugeridos:');
            $this->line('  1. composer require laravel/sanctum:^4.3');
            $this->line('  2. php artisan install:api --no-interaction');
            $this->line('  3. Volvé a correr `php artisan mk:fix:sanctum-uuids`');

            return self::FAILURE;
        }

        $content = File::get($target);

        // Detectar el estado actual.
        $hasMorphs = str_contains($content, "\$table->morphs('tokenable')");
        $hasUuidMorphs = str_contains($content, "\$table->uuidMorphs('tokenable')");

        if ($hasUuidMorphs && ! $hasMorphs) {
            $this->info("✅ La migration ya está parcheada (uuidMorphs). No se hicieron cambios.");
            $this->line("   Archivo: {$target}");

            return self::SUCCESS;
        }

        if (! $hasMorphs) {
            $this->warn("⚠️  La migration existe pero NO contiene `\$table->morphs('tokenable')` ni `\$table->uuidMorphs('tokenable')`.");
            $this->line("   Archivo: {$target}");
            $this->line('   Revisa manualmente si Sanctum usa una sintaxis distinta en tu versión.');

            return self::FAILURE;
        }

        // Estado: tiene `morphs('tokenable')`. Parchear a `uuidMorphs('tokenable')`.
        $newContent = str_replace(
            "\$table->morphs('tokenable')",
            "\$table->uuidMorphs('tokenable')",
            $content,
        );

        if ($dryRun) {
            $this->info('🔍 DRY RUN — no se escribió el archivo. Cambios que se aplicarían:');
            $this->newLine();
            $this->line('  - $table->morphs(\'tokenable\')');
            $this->line('  + $table->uuidMorphs(\'tokenable\')');
            $this->newLine();
            $this->line("   Archivo: {$target}");

            return self::SUCCESS;
        }

        File::put($target, $newContent);

        $this->info('✅ Migration de Sanctum parcheada: morphs() → uuidMorphs().');
        $this->newLine();
        $this->line("   Archivo: {$target}");
        $this->line('   Backup: NO se hizo backup automático (rollback manual posible desde git).');
        $this->newLine();
        $this->warn('⚠️  IMPORTANTE: si ya corriste `php artisan migrate` antes de este fix,');
        $this->line('   la tabla `personal_access_tokens` tiene columnas bigint y este cambio');
        $this->line('   en la migration NO la migra automáticamente. Tenés dos opciones:');
        $this->line('     A. `php artisan migrate:fresh` (DESTRUCTIVO — borra todos los datos).');
        $this->line('     B. Migration custom que altere las columnas:');
        $this->line('        Schema::table(\'personal_access_tokens\', function (Blueprint $t) {');
        $this->line("            \$t->string('tokenable_id')->change();");
        $this->line('        });');
        $this->newLine();
        $this->line('📌 Siguiente paso: `php artisan migrate` (si todavía no la corriste).');

        return self::SUCCESS;
    }
}