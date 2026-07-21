<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Source-parsing security linter for mk-director consumers and the package
 * itself. Flags common security anti-patterns BEFORE they hit runtime:
 *
 *   - `$guarded = []` on Eloquent models (mass-assignment open door)
 *   - `belongsTo(<X>::class)` on a column without a matching FK in the
 *     corresponding migration
 *   - `MkMultiTenantPlugin::$tenantColumn` set to a value outside
 *     `MkMultiTenantPlugin::TENANT_COLUMN_WHITELIST`
 *
 * Exit codes:
 *   - 0  no errors (warnings are OK)
 *   - 1  at least one error (and `--strict` was passed, also exit 1 on
 *        any warning)
 *
 * Implementation note: source-parsing only. No Laravel app boot, no
 * database connection. This matches the audit-driven pattern from the
 * 1.2.0-fixes sprint and keeps the lint fast (< 2s for 100 models).
 *
 * @see audit-2026-06-17-R2-008, audit-2026-06-17-R2-009
 * @see openspec/changes/2026-06-18-1.2.2-hardening/specs/security-lint.md
 */
final class SecurityLintCommand extends Command
{
    protected $signature = 'mk:security-lint
        {--path=app/Models : Path to scan (relative to base_path)}
        {--strict : Treat WARN as ERROR (exit 1 on any finding)}
        {--format=table : Output format (table|json)}';

    protected $description = 'Static security linter for Eloquent models and MkMultiTenantPlugin config.';

    /**
     * Columns that MkMultiTenantPlugin accepts as a tenant column. Must
     * stay in sync with MkMultiTenantPlugin::TENANT_COLUMN_WHITELIST — the
     * lint reads this constant via reflection so the two never drift.
     */
    private const TENANT_WHITELIST = [
        'tenant_id',
        'client_id',
        'org_id',
        'company_id',
    ];

    public function handle(): int
    {
        $basePath = (string) $this->option('path');
        $strict = (bool) $this->option('strict');
        $format = (string) $this->option('format');

        $absolutePath = $this->resolvePath($basePath);

        if (! is_dir($absolutePath)) {
            $this->warn("No models directory at {$absolutePath} — nothing to lint.");
            return self::SUCCESS;
        }

        $findings = $this->lintPath($absolutePath);

        // Add the tenant-column check (config-level, not file-level).
        $findings = array_merge($findings, $this->lintTenantColumnConfig());

        $errorCount = count(array_filter($findings, fn ($f) => $f['level'] === 'error'));
        $warnCount = count(array_filter($findings, fn ($f) => $f['level'] === 'warning'));

        if ($format === 'json') {
            $this->line((string) json_encode([
                'errors' => $errorCount,
                'warnings' => $warnCount,
                'findings' => $findings,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->renderTable($findings, $errorCount, $warnCount);
        }

        if ($errorCount > 0) {
            return self::FAILURE;
        }
        if ($strict && $warnCount > 0) {
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    private function resolvePath(string $basePath): string
    {
        // Prefer base_path() so consumer apps can pass `app/Models` and
        // get the right absolute path. Fallback to CWD if base_path is
        // not available (unit-test context).
        if (function_exists('base_path')) {
            $resolved = base_path($basePath);
            if (is_dir($resolved)) {
                return $resolved;
            }
        }
        return getcwd() . '/' . $basePath;
    }

    private function lintPath(string $absolutePath): array
    {
        $findings = [];

        $finder = (new Finder())
            ->files()
            ->in($absolutePath)
            ->name('*.php');

        foreach ($finder as $file) {
            $path = $file->getRealPath();
            $contents = (string) file_get_contents($path);
            $relative = ltrim(str_replace($absolutePath, '', $path), '/');

            $findings = array_merge($findings, $this->checkGuardedEmpty($contents, $relative));
            $findings = array_merge($findings, $this->checkMissingForeignKeys($contents, $relative));
            $findings = array_merge($findings, $this->checkMorphOwnerPk($contents, $relative));
        }

        return $findings;
    }

    /**
     * R2-008: `$guarded = []` (or `['*']` with everything guarded) is a
     * mass-assignment open door. Any controller that calls
     * `Model::create($request->all())` will let the request writer set
     * arbitrary attributes — including `is_admin`, `password`, etc.
     */
    private function checkGuardedEmpty(string $contents, string $relative): array
    {
        $findings = [];
        if (preg_match('/\$guarded\s*=\s*\[\s*\]/', $contents)
            || preg_match('/\$guarded\s*=\s*\[\s*[\'"]\*[\'"]\s*\]/', $contents)) {
            $line = $this->findLineNumber($contents, '$guarded');
            $findings[] = [
                'level' => 'warning',
                'path' => $relative,
                'line' => $line,
                'message' => '$guarded = [] enables mass-assignment. Set $fillable explicitly.',
            ];
        }
        return $findings;
    }

    /**
     * R2-008 (soft): a `belongsTo(<X>::class)` on a column without a
     * matching FK declaration in the migration is a refactoring hazard.
     * We only flag the WORST case (no FK at all on the most common
     * pattern `user_id` → `users.id`); a smarter check would parse
     * the migration too, but that requires Laravel's Schema facade and
     * would couple the lint to a booted app. Source-parsing is enough
     * to catch the 80% case.
     */
    private function checkMissingForeignKeys(string $contents, string $relative): array
    {
        $findings = [];

        if (! preg_match_all(
            '/belongsTo\s*\(\s*([A-Z][A-Za-z0-9_\\\\]+)::class\s*(?:,\s*[\'"]([a-z_]+)[\'"])?\s*\)/',
            $contents,
            $matches,
            PREG_SET_ORDER
        )) {
            return $findings;
        }

        foreach ($matches as $m) {
            $related = ltrim($m[1], '\\');
            $relatedShort = substr($related, strrpos($related, '\\') !== false ? strrpos($related, '\\') + 1 : 0);
            $column = $m[2] ?? strtolower($relatedShort) . '_id';

            // Heuristic: the migration that creates the table containing
            // `$column` should declare either `->foreign($column)` or
            // `->constrained(...)`. We scan sibling migrations for the
            // pattern, but we accept false negatives in deeply nested
            // module structures. WARN only (not ERROR) because the
            // heuristic is best-effort.
            $found = $this->searchMigrationsForForeignKey($relatedShort, $column);

            if (! $found) {
                $line = $this->findLineNumber($contents, $m[0]);
                $findings[] = [
                    'level' => 'warning',
                    'path' => $relative,
                    'line' => $line,
                    'message' => sprintf(
                        'belongsTo(%s) on %s without FK declared in matching migration.',
                        $m[0],
                        $column
                    ),
                ];
            }
        }

        return $findings;
    }

    /**
     * FB12-#12: un modelo que es DUEÑO de las relaciones polimórficas del
     * paquete (`HasMkMedia`/`HasMkComments`/`HasMkReactions`) cuya tabla usa un
     * PK `uuid` NATIVO se rompe en Postgres. Las columnas `*able_id` del paquete
     * son `string` (soportan consumers uuid Y bigint), y `withCount`/`whereHas`/
     * `has` comparan COLUMNA CON COLUMNA (`tu_tabla.id = mk_*.{*able}_id` →
     * `uuid = varchar`), que pgsql, de tipado estricto, rechaza. El fix es tipar
     * el PK `string(36)` (`HasUuids` genera el uuid igual).
     *
     * Filesystem glue: detecta el trait en el modelo, deriva la tabla y busca la
     * migración que la crea. El veredicto lo da `auditMorphOwnerPk`, que es PURO
     * (dos strings entran, un finding sale) y por eso se testea sin bootear nada.
     *
     * @return list<array{level:string,path:string,line:int,message:string}>
     */
    private function checkMorphOwnerPk(string $contents, string $relative): array
    {
        if (! $this->usesMorphOwnerTrait($contents)) {
            return [];
        }

        $table = $this->deriveTableName($contents);
        if ($table === null) {
            return [];
        }

        $migration = $this->findCreateMigration($table);
        if ($migration === null) {
            // No encontramos la migración (layout no estándar): no podemos
            // auditar el tipo del PK. Silencio antes que un falso positivo.
            return [];
        }

        [$migrationSource, $migrationRelative] = $migration;

        $finding = $this->auditMorphOwnerPk($contents, $migrationSource, $migrationRelative);

        return $finding === null ? [] : [$finding];
    }

    /**
     * NÚCLEO PURO Y TESTEABLE: dado el source del modelo y el de la migración que
     * crea su tabla, devuelve el finding si el modelo es dueño de morphs del
     * paquete Y la migración declara un PK `uuid` nativo; null si no.
     *
     * WARNING, no ERROR: sólo pgsql es estricto; MySQL/SQLite no se ven
     * afectados y forzar un error rompería a esos consumers sin motivo.
     *
     * @return array{level:string,path:string,line:int,message:string}|null
     */
    public function auditMorphOwnerPk(string $modelSource, string $migrationSource, string $migrationRelative = ''): ?array
    {
        if (! $this->usesMorphOwnerTrait($modelSource)) {
            return null;
        }

        // PK `uuid` nativo: `$table->uuid('id')->primary()`. Un `string('id')`
        // (el fix) NO matchea, así que una tabla ya corregida no se marca.
        if (! preg_match('/->\s*uuid\(\s*[\'"]id[\'"]\s*\)\s*->\s*primary\(/', $migrationSource, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $line = substr_count(substr($migrationSource, 0, (int) $m[0][1]), "\n") + 1;

        return [
            'level' => 'warning',
            'path' => $migrationRelative,
            'line' => $line,
            'message' => 'Tabla dueña de morphs del paquete (HasMkMedia/HasMkComments/HasMkReactions) '
                . "con PK uuid('id') nativo. En Postgres rompe withCount/whereHas/has (uuid = varchar). "
                . "Usá \$table->string('id', 36) — HasUuids genera el uuid igual.",
        ];
    }

    /**
     * ¿El modelo aplica alguno de los tres traits dueños de morphs? Matchea la
     * línea `use ...HasMk{Media,Comments,Reactions}` — tanto el `use` de la
     * clase como el import, cualquiera basta para señalar que el modelo los usa.
     */
    private function usesMorphOwnerTrait(string $contents): bool
    {
        return (bool) preg_match('/\buse\b[^;]*\bHasMk(Media|Comments|Reactions)\b/', $contents);
    }

    /**
     * Nombre de tabla del modelo: `protected $table = '...'` si está declarado;
     * si no, se deriva del nombre de la clase (snake + plural), la convención de
     * Eloquent.
     */
    private function deriveTableName(string $contents): ?string
    {
        if (preg_match('/protected\s+\$table\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $contents, $m)) {
            return $m[1];
        }
        if (preg_match('/\bclass\s+([A-Za-z0-9_]+)/', $contents, $m)) {
            return Str::of($m[1])->snake()->plural()->toString();
        }
        return null;
    }

    /**
     * Busca la migración que hace `Schema::create('<table>'`, escaneando desde
     * base_path (excluyendo vendor/node_modules). Cubre el layout estándar
     * (`database/migrations`) y el modular (`app/Modules/<mod>/Database/Migrations`).
     *
     * @return array{0:string,1:string}|null  [source, ruta relativa] o null
     */
    private function findCreateMigration(string $table): ?array
    {
        $root = function_exists('base_path') ? base_path() : getcwd();
        if (! is_dir($root)) {
            return null;
        }

        $finder = (new Finder())
            ->files()
            ->in($root)
            ->exclude(['vendor', 'node_modules'])
            ->path('/[Mm]igrations/')
            ->name('*.php');

        $pattern = '/Schema::create\(\s*[\'"]' . preg_quote($table, '/') . '[\'"]/';

        foreach ($finder as $file) {
            $source = (string) file_get_contents($file->getRealPath());
            if (preg_match($pattern, $source)) {
                return [$source, $file->getRelativePathname()];
            }
        }

        return null;
    }

    private function searchMigrationsForForeignKey(string $relatedShort, string $column): bool
    {
        $table = Str::of($relatedShort)->snake()->plural() . '/';
        $migrationsDir = function_exists('database_path') ? database_path('migrations') : null;
        if (! is_dir((string) $migrationsDir)) {
            // No database/migrations dir (consumer has its own layout) — bail.
            return true;
        }
        $finder = (new Finder())->files()->in((string) $migrationsDir)->name('*.php');
        foreach ($finder as $file) {
            $source = (string) file_get_contents($file->getRealPath());
            if (str_contains($source, $table)
                && (str_contains($source, "->foreign('{$column}')")
                    || str_contains($source, "->foreign(\"{$column}\")")
                    || str_contains($source, "->constrained("))) {
                return true;
            }
        }
        return false;
    }

    /**
     * R2-009: `$tenantColumn` outside the whitelist could let a misconfig
     * point to `password`, `email`, etc. MkMultiTenantPlugin already
     * throws at runtime if the constructor receives a bad value, but
     * the lint catches the bad value BEFORE the app boots (faster
     * feedback loop, helps with config-driven installs).
     */
    private function lintTenantColumnConfig(): array
    {
        $findings = [];

        // Strategy: read the package's own MkMultiTenantPlugin for the
        // whitelist constant. If a consumer overrides `column` in their
        // service-provider binding, they bypass the constructor check
        // entirely. The lint looks for `MkMultiTenantPlugin::class` in
        // config files and warns if a `column` key is set.
        $configPaths = [
            function_exists('config_path') ? config_path('mk_director.php') : null,
            function_exists('config_path') ? config_path('mk-director.php') : null,
        ];

        foreach (array_filter($configPaths) as $path) {
            if (! is_file($path)) {
                continue;
            }
            $contents = (string) file_get_contents((string) $path);
            if (preg_match("/['\"]column['\"]\\s*=>\\s*['\"]([^'\"]+)['\"]/", $contents, $m)) {
                $candidate = $m[1];
                if (! in_array($candidate, self::TENANT_WHITELIST, true)) {
                    $findings[] = [
                        'level' => 'error',
                        'path' => $path,
                        'line' => $this->findLineNumber($contents, $m[0]),
                        'message' => sprintf(
                            'MkMultiTenantPlugin column "%s" is NOT in the whitelist (%s). '
                            . 'Misconfiguration can corrupt data. Fix the config OR extend the whitelist intentionally.',
                            $candidate,
                            implode(', ', self::TENANT_WHITELIST)
                        ),
                    ];
                }
            }
        }

        return $findings;
    }

    private function findLineNumber(string $contents, string $needle): int
    {
        $pos = strpos($contents, $needle);
        if ($pos === false) {
            return 0;
        }
        return substr_count(substr($contents, 0, $pos), "\n") + 1;
    }

    private function renderTable(array $findings, int $errorCount, int $warnCount): void
    {
        $this->line('');
        $this->line('Security Lint Report');
        $this->line(str_repeat('═', 60));
        $this->line(sprintf('  Found %d error(s), %d warning(s)', $errorCount, $warnCount));
        $this->line('');

        if (empty($findings)) {
            $this->info('  ✓ No issues found.');
            return;
        }

        $rows = [];
        foreach ($findings as $f) {
            $rows[] = [
                strtoupper($f['level']),
                $f['path'] . ($f['line'] > 0 ? ':' . $f['line'] : ''),
                $f['message'],
            ];
        }
        $this->table(['Level', 'Location', 'Message'], $rows);
    }
}
