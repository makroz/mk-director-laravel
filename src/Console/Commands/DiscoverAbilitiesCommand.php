<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mk\Director\Auth\Attributes\Ability;
use Mk\Director\Controllers\SmartController;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * mk:discover-abilities — auto-pobla {scope}_abilities desde providers / atributos / docblocks.
 *
 * Spec: R-PKG-007 (design.md D1..D7).
 *
 * Source-of-truth (Q1 = hybrid):
 *   1. Si el ServiceProvider del módulo implementa `discoverAbilities(): array`,
 *      ese array es el ÚNICO source. Atributos y docblocks se IGNORAN.
 *   2. Si el provider NO implementa `discoverAbilities()`, fallback combinado:
 *      atributos PHP 8.4 (#[\Mk\Director\Auth\Attributes\Ability]) + docblock
 *      `@mk-ability name|description`.
 *
 * Write policy (Q3 = interactive prompt con escape hatch):
 *   - `--dry-run`        → skip prompt, never write.
 *   - `--force`          → skip prompt, always write.
 *   - Sin flags + TTY    → $this->confirm(..., false), default = No.
 *   - Sin flags + CI     → --no-interaction global flag hace que confirm retorne false.
 *
 * Ejemplos:
 *   php artisan mk:discover-abilities --module=admin                # prompt (default dry)
 *   php artisan mk:discover-abilities --module=admin --force        # escribe sin prompt
 *   php artisan mk:discover-abilities --module=admin --dry-run      # preview sin escribir
 *   php artisan mk:discover-abilities --module=admin --force --json # CI: write + JSON output
 *   php artisan mk:discover-abilities                               # scan all modules
 */
class DiscoverAbilitiesCommand extends Command
{
    protected $signature = 'mk:discover-abilities
                            {--module=* : Scope(s) a procesar. Vacío = todos los módulos descubiertos en paths.modules}
                            {--dry-run : Preview sin escribir a DB (skip prompt, never write)}
                            {--force : Escribir/actualizar filas en {scope}_abilities (skip prompt, always write)}
                            {--json : Output en JSON en vez de tabla humana}';

    protected $description = 'Auto-descubre abilities desde module providers (preferred), atributos PHP, o docblock @mk-ability. UPSERT idempotente en {scope}_abilities.';

    public function handle(): int
    {
        // 1. Validate --dry-run + --force aren't both set.
        if ($this->option('dry-run') && $this->option('force')) {
            $this->error('No combines --dry-run y --force. Elegí uno.');

            return self::FAILURE;
        }

        // 2. Resolve modules.
        $modulesPath = $this->modulesPath();
        if (! is_dir($modulesPath)) {
            $this->error("No se encontró el directorio de módulos: {$modulesPath}. Configurá mk_director.paths.modules.");

            return self::FAILURE;
        }

        $moduleArgs = $this->option('module');
        $allModules = $this->discoverModules($modulesPath);

        $modules = empty($moduleArgs)
            ? $allModules
            : array_intersect_key($allModules, array_flip($moduleArgs));

        if (empty($modules)) {
            $this->warn('No hay módulos para procesar.');

            return self::SUCCESS;
        }

        $isJson = (bool) $this->option('json');
        $shouldWrite = $this->resolveWriteIntent();

        $report = [];

        foreach ($modules as $moduleName => $moduleInfo) {
            $report[$moduleName] = $this->processModule($moduleName, $moduleInfo, $shouldWrite, $isJson);
        }

        if ($isJson) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->printTable($report);
        }

        return self::SUCCESS;
    }

    /**
     * Decide si el comando escribe a DB.
     *
     * Precedencia (Q3):
     *   - --dry-run   → false (skip prompt)
     *   - --force     → true  (skip prompt)
     *   - interactive → $this->confirm(..., false)  (default No)
     *
     * @return bool true = escribir UPSERT, false = preview
     */
    private function resolveWriteIntent(): bool
    {
        if ($this->option('dry-run')) {
            return false;
        }

        if ($this->option('force')) {
            return true;
        }

        // Default No (Q3 = interactive con safety net).
        return $this->confirm(
            '¿Escribir las abilities a las tablas {scope}_abilities? [y/N]',
            false
        );
    }

    /**
     * Procesa un módulo individual: descubre abilities y (opcionalmente) UPSERT.
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @return array{module: string, scope: string, source: string, abilities: array<int, array{name: string, description: ?string}>, action: string, count: int}
     */
    private function processModule(string $moduleName, array $moduleInfo, bool $shouldWrite, bool $isJson): array
    {
        $scope = Str::snake(Str::plural($moduleName));

        // D1 (hybrid): provider primario; attribute+docblock como fallback único.
        $discovery = $this->discoverAbilitiesFromProvider($moduleName, $moduleInfo);

        if ($discovery['source'] === 'provider') {
            $abilities = $discovery['abilities'];
        } else {
            // Fallback: atributos PHP + docblock combinados.
            $abilities = $this->discoverAbilitiesFromAttributesAndDocblocks($moduleInfo);

            // R-PKG-015 OBS-NEW-01: además leer `$mkConfig` de los SmartController
            // del módulo y generar abilities CRUD estándar del estilo
            // `{scope}.{model}.{verb}` (e.g. `admin.admins.viewAny`).
            //
            // Por qué: cuando el scaffolder genera el CRUD via `--with-crud`, los
            // controllers (AdminController, RoleController, AbilityController)
            // extienden `SmartController` y exponen `$mkConfig['model']`, pero NO
            // tienen `#[Ability]` attributes ni `@mk-ability` docblocks. Sin este
            // path, el fallback no descubre nada y `mk:discover-abilities` reporta
            // "No se descubrieron abilities." (lo que RETO observó).
            //
            // Merge con dedup por name: si un controller ya tiene un attribute
            // con la misma ability, gana el attribute (viene primero en el array).
            $mkConfigAbilities = $this->discoverAbilitiesFromMkConfig($moduleInfo, $moduleName);
            foreach ($mkConfigAbilities as $mkAbility) {
                $alreadyExists = false;
                foreach ($abilities as $existing) {
                    if ($existing['name'] === $mkAbility['name']) {
                        $alreadyExists = true;
                        break;
                    }
                }
                if (! $alreadyExists) {
                    $abilities[] = $mkAbility;
                }
            }
        }

        // Determine action.
        $action = $shouldWrite ? 'upsert' : 'preview';

        if ($shouldWrite && ! empty($abilities)) {
            $this->upsertAbilities($scope, $abilities);
        }

        return [
            'module' => $moduleName,
            'scope' => $scope,
            'source' => $discovery['source'],
            'abilities' => array_values(array_map(
                fn (array $a): array => ['name' => $a['name'], 'description' => $a['description']],
                $abilities
            )),
            'action' => $action,
            'count' => count($abilities),
        ];
    }

    /**
     * Source-of-truth: provider.
     *
     * Busca `{Name}ModuleServiceProvider` (per R-PKG-008 convention) o
     * `{Name}ServiceProvider` (legacy) en el módulo. Si implementa
     * `discoverAbilities(): array`, retorna esas abilities.
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @return array{source: string, abilities: array<int, array{name: string, description: ?string}>}
     */
    private function discoverAbilitiesFromProvider(string $moduleName, array $moduleInfo): array
    {
        $providerClass = $this->resolveProviderClass($moduleName, $moduleInfo);

        if ($providerClass === null) {
            return ['source' => 'fallback', 'abilities' => []];
        }

        if (! method_exists($providerClass, 'discoverAbilities')) {
            return ['source' => 'fallback', 'abilities' => []];
        }

        try {
            // Use app()->make() to honor container bindings (the provider
            // may be a singleton with DI dependencies).
            /** @var object $instance */
            $instance = app($providerClass);
            $names = $instance->discoverAbilities();
        } catch (Throwable $e) {
            $this->warn("Provider {$providerClass}::discoverAbilities() falló: {$e->getMessage()}. Fallback a atributos.");

            return ['source' => 'fallback', 'abilities' => []];
        }

        if (! is_array($names)) {
            $this->warn("{$providerClass}::discoverAbilities() no retornó array. Fallback a atributos.");

            return ['source' => 'fallback', 'abilities' => []];
        }

        $abilities = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $abilities[] = ['name' => $name, 'description' => null];
        }

        return ['source' => 'provider', 'abilities' => $abilities];
    }

    /**
     * Source fallback: atributos PHP + docblock combinados.
     *
     * Atributos son primary; docblocks son secundarios dentro del fallback.
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @return array<int, array{name: string, description: ?string}>
     */
    private function discoverAbilitiesFromAttributesAndDocblocks(array $moduleInfo): array
    {
        $abilities = [];

        // Walk controllers in the module.
        foreach ($this->findControllerClasses($moduleInfo) as $class) {
            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            foreach ($reflection->getMethods() as $method) {
                // 1. PHP 8.4 attributes (primary within fallback).
                foreach ($method->getAttributes(Ability::class) as $attr) {
                    try {
                        $instance = $attr->newInstance();
                        $abilities[] = [
                            'name' => $instance->name,
                            'description' => $instance->description,
                        ];
                    } catch (Throwable) {
                        continue;
                    }
                }

                // 2. Docblock @mk-ability (secondary within fallback).
                $doc = $method->getDocComment();
                if ($doc !== false && preg_match('/@mk-ability\s+([a-z0-9._*-]+)(?:\s+(.+))?/i', $doc, $m)) {
                    $abilities[] = [
                        'name' => $m[1],
                        'description' => $m[2] ?? null,
                    ];
                }
            }
        }

        // Dedup by name (first occurrence wins — attributes before docblocks).
        $unique = [];
        foreach ($abilities as $a) {
            if (! isset($unique[$a['name']])) {
                $unique[$a['name']] = $a;
            }
        }

        return array_values($unique);
    }

    /**
     * UPSERT idempotente de abilities.
     *
     * R-PKG-021 BUG-NEW-29 (HIGH): el scaffolder tiene dos rutas que generan
     * schema distinto:
     *  - `mk:module X --with-rbac`         → tabla `{scope}_abilities` per-scope.
     *  - `mk:make:auth-user X --with-crud` → tabla `abilities` global (del paquete).
     *
     * Antes, este método SIEMPRE escribía en `{scope}_abilities`. Para
     * consumers que usan `--with-crud`, la tabla per-scope NO existe y el
     * UPSERT falla con `relation "{scope}_abilities" does not exist`.
     *
     * Fix R-PKG-021: schema-aware. Detecta cuál tabla existe y escribe ahí:
     *  - Si `{scope}_abilities` existe → UPSERT per-scope (caso `--with-rbac`).
     *  - Si NO existe → UPSERT en `abilities` global (caso `--with-crud`).
     *
     * Idempotente: múltiples ejecuciones actualizan `description` y `updated_at`.
     *
     * Si NINGUNA tabla existe (caso patológico: consumer no migró), lanza
     * excepción con mensaje accionable para que el consumer sepa qué migración
     * le falta.
     *
     * @param  array<int, array{name: string, description: ?string}>  $abilities
     */
    private function upsertAbilities(string $scope, array $abilities): void
    {
        if (empty($abilities)) {
            return;
        }

        $table = $this->resolveAbilitiesTable($scope);

        $now = now();
        $rows = array_map(static fn (array $a): array => [
            'name' => $a['name'],
            'description' => $a['description'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $abilities);

        DB::table($table)->upsert(
            $rows,
            ['name'],                  // unique key
            ['description', 'updated_at'] // columns to update on conflict
        );

        $this->info("   → {$table}: ".count($rows).' abilities UPSERT-ed.');
    }

    /**
     * Resuelve cuál tabla usar para escribir abilities.
     *
     * R-PKG-021 BUG-NEW-29: schema-aware — per-scope si existe, global si no.
     *
     * @return string Nombre de la tabla a usar.
     *
     * @throws \RuntimeException Si ninguna tabla existe.
     */
    private function resolveAbilitiesTable(string $scope): string
    {
        $perScopeTable = "{$scope}_abilities";
        $globalTable = 'abilities';

        $perScopeExists = $this->tableExists($perScopeTable);
        $globalExists = $this->tableExists($globalTable);

        if ($perScopeExists) {
            return $perScopeTable;
        }

        if ($globalExists) {
            return $globalTable;
        }

        throw new \RuntimeException(
            "Ninguna tabla de abilities existe. Esperaba '{$perScopeTable}' (caso `mk:module X --with-rbac`) "
            ."o '{$globalTable}' (caso `mk:make:auth-user X --with-crud`). "
            .'Corriste `php artisan migrate` después de scaffoldear?'
        );
    }

    /**
     * Check si una tabla existe en la conexión default.
     *
     * Helper aislado para que el test source-parsing pueda pinear el patrón.
     *
     * Usa `DB::connection()->getSchemaBuilder()` directamente en vez del facade
     * `Schema::hasTable()`. Razón: el facade `Schema` requiere que el container
     * tenga bindeado `db.schema` correctamente. Algunos setups de testing (e.g.
     * los end-to-end tests del paquete, que usan `Capsule` con un container
     * recién creado) NO bindean `db.schema` automáticamente, así que el facade
     * falla silenciosamente y retorna `false` aún cuando la tabla existe.
     *
     * `DB::connection()->getSchemaBuilder()` es más robusto: usa el
     * DatabaseManager bindeado en `db` y delega al schema builder de esa conexión.
     */
    private function tableExists(string $table): bool
    {
        try {
            if (! function_exists('app')) {
                return false;
            }

            $app = app();
            if (! $app->bound('db')) {
                return false;
            }

            $connection = $app->make('db')->connection();
            $schema = $connection->getSchemaBuilder();

            return $schema->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Resolve el provider class del módulo.
     *
     * Convención R-PKG-008: `App\Modules\{Name}\{Name}ModuleServiceProvider`.
     * Legacy: `App\Modules\{Name}\Providers\{Name}ServiceProvider`.
     * O cualquier `{Name}*ServiceProvider` en el módulo (heurística).
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     */
    private function resolveProviderClass(string $moduleName, array $moduleInfo): ?string
    {
        $candidates = [
            "App\\Modules\\{$moduleName}\\{$moduleName}ModuleServiceProvider",
            "App\\Modules\\{$moduleName}\\{$moduleName}ServiceProvider",
            "App\\Modules\\{$moduleName}\\Providers\\{$moduleName}ServiceProvider",
        ];

        // Heuristic: search for any *ServiceProvider in the module classes.
        foreach ($moduleInfo['classes'] as $class) {
            if (Str::endsWith($class, 'ServiceProvider') && ! str_contains($class, 'Auth\\')) {
                // Prefer classes that match the module name.
                if (str_contains($class, $moduleName)) {
                    return $class;
                }
                $candidates[] = $class;
            }
        }

        foreach ($candidates as $candidate) {
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Descubre los módulos en `mk_director.paths.modules`.
     *
     * Cada carpeta inmediata que contenga `Http/Controllers/` o `Models/`
     * cuenta como módulo.
     *
     * @return array<string, array{path: string, classes: array<int, string>}>
     */
    private function discoverModules(string $modulesPath): array
    {
        $modules = [];

        foreach (new \DirectoryIterator($modulesPath) as $item) {
            if (! $item->isDir() || $item->isDot()) {
                continue;
            }

            $moduleName = $item->getFilename();
            $modulePath = $item->getPathname();
            $classes = $this->discoverClassesInDir($modulePath);

            // Only treat as a module if it has Controllers or Models.
            $hasControllers = is_dir($modulePath.'/Http/Controllers');
            $hasModels = is_dir($modulePath.'/Models');

            if (! $hasControllers && ! $hasModels) {
                continue;
            }

            $modules[$moduleName] = [
                'path' => $modulePath,
                'classes' => $classes,
            ];
        }

        return $modules;
    }

    /**
     * Encuentra todas las clases PHP declaradas en un directorio (PSR-4 esperado).
     *
     * Estrategia (R-PKG-019 OBS-NEW-02 fix):
     *   1. `get_declared_classes()` SOLO retorna clases ya loaded por el
     *      autoloader. En contexto artisan command (CLI), las controllers
     *      scaffoldeadas típicamente NO están loaded hasta que route:list o
     *      el bootstrap del framework las referencia.
     *   2. Por eso, ANTES de iterar `get_declared_classes()`, hacemos
     *      `require_once` de cada archivo PHP encontrado. Esto fuerza la
     *      declaración de la clase sin depender del autoload trigger.
     *   3. Después del require, el matching por suffix contra
     *      `get_declared_classes()` funciona correctamente (convención PSR-4).
     *
     * Side-effects del require_once: en proyectos Laravel siguiendo la
     * convención PSR-4 (cada archivo = una clase, sin código top-level),
     * el require_once es seguro. Si un consumer tiene archivos con código
     * top-level (helpers, registro de side-effects), esos side-effects
     * ocurrirán. Trade-off documentado; alternativa sería parsear el
     * namespace via regex en vez de require_once, pero requiere conocer
     * el root namespace.
     *
     * @return array<int, string>
     */
    private function discoverClassesInDir(string $dir): array
    {
        $classes = [];

        if (! is_dir($dir)) {
            return $classes;
        }

        // Resolver symlinks (e.g. /private/var/folders en macOS) para que
        // el matching por suffix sea consistente con $realPath de cada file.
        // Sin esto, str_replace($dir.DIRECTORY_SEPARATOR, ...) falla porque
        // $dir puede ser `/var/folders/...` pero $realPath es
        // `/private/var/folders/...` (Symfony Finder resuelve symlinks).
        $dir = realpath($dir) ?: $dir;

        $finder = (new Finder)
            ->files()
            ->in($dir)
            ->name('*.php')
            ->notName('*.stub')
            ->ignoreVCS(true)
            ->ignoreDotFiles(true);

        foreach ($finder as $file) {
            $realPath = $file->getRealPath();

            // Force-require para declarar la clase antes del scan
            // (R-PKG-019 OBS-NEW-02). try/catch porque algunos archivos
            // pueden ser helpers (no son clases) o tener dependencias
            // que solo se resuelven en runtime Laravel completo.
            try {
                require_once $realPath;
            } catch (Throwable) {
                continue;
            }

            $relativePath = str_replace([$dir.DIRECTORY_SEPARATOR, '.php'], '', $realPath);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            // Heuristic: el prefijo `App\Modules` es el default para apps
            // Laravel consumer (R-MK-001), pero lo hacemos overridable
            // via `classesNamespacePrefix()` para que los tests puedan
            // usar namespaces custom (e.g. `TestNs`). El matching por
            // suffix es lo que de verdad filtra — el prefix es solo una
            // sanity check contra falsos positivos.
            $prefix = $this->classesNamespacePrefix();
            foreach (get_declared_classes() as $declared) {
                if (str_ends_with($declared, '\\'.$relativePath)
                    && ($prefix === null || str_starts_with($declared, $prefix))) {
                    $classes[] = $declared;
                    break;
                }
            }
        }

        return $classes;
    }

    /**
     * Find controller classes in a module.
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @return array<int, string>
     */
    private function findControllerClasses(array $moduleInfo): array
    {
        return array_values(array_filter(
            $moduleInfo['classes'],
            static fn (string $class): bool => str_contains($class, '\\Http\\Controllers\\') && str_ends_with($class, 'Controller')
        ));
    }

    /**
     * Descubre abilities desde `$mkConfig` de los `SmartController` del módulo (R-PKG-015 OBS-NEW-01).
     *
     * Para cada controller que extienda `SmartController` y declare `$mkConfig['model']`,
     * genera las 5 abilities CRUD estándar con naming `{scope}.{model}.{verb}`:
     *   - `{scope}.{model}.viewAny`
     *   - `{scope}.{model}.view`
     *   - `{scope}.{model}.create`
     *   - `{scope}.{model}.update`
     *   - `{scope}.{model}.delete`
     *
     * Scope: derivado del nombre del módulo (`Admin` → `admin`).
     * Resource (modelo): derivado del FQCN en `$mkConfig['model']`
     *   (`App\Modules\Admin\Models\Admin` → `admins`).
     *
     * Si el controller NO extiende `SmartController` o no tiene `$mkConfig['model']`,
     * se ignora silenciosamente (otros paths pueden haber encontrado abilities).
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @param  string  $moduleName  Nombre del módulo (e.g. `Admin`).
     * @return array<int, array{name: string, description: ?string}>
     */
    private function discoverAbilitiesFromMkConfig(array $moduleInfo, string $moduleName): array
    {
        $abilities = [];
        $scope = Str::snake($moduleName);

        $smartControllerClass = SmartController::class;

        foreach ($this->findControllerClasses($moduleInfo) as $class) {
            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }

            if (! $reflection->isSubclassOf($smartControllerClass)) {
                continue;
            }

            if (! $reflection->hasProperty('mkConfig')) {
                continue;
            }

            try {
                $property = $reflection->getProperty('mkConfig');
                $defaults = $property->getDefaultValue();
            } catch (Throwable) {
                continue;
            }

            if (! is_array($defaults) || empty($defaults['model'])) {
                continue;
            }

            $modelClass = $defaults['model'];
            if (! is_string($modelClass) || ! class_exists($modelClass)) {
                continue;
            }

            try {
                $modelReflection = new ReflectionClass($modelClass);
                $resource = Str::snake(Str::plural($modelReflection->getShortName()));
            } catch (Throwable) {
                continue;
            }

            // Generar las 5 abilities CRUD estándar.
            foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $verb) {
                $abilities[] = [
                    'name' => "{$scope}.{$resource}.{$verb}",
                    'description' => ucfirst($verb).' '.$resource.'.',
                ];
            }
        }

        return $abilities;
    }

    /**
     * Path al directorio de módulos.
     *
     * Overridable en tests via subclassing (pattern R-PKG-008 D7).
     */
    protected function modulesPath(string $moduleName = ''): string
    {
        $base = config('mk_director.paths.modules', app_path('Modules'));

        return $moduleName !== '' ? $base.DIRECTORY_SEPARATOR.$moduleName : $base;
    }

    /**
     * Prefijo de namespace que filtra las clases discovered.
     *
     * Default `App\Modules` (regla R-MK-001 — módulos bounded context
     * viven bajo `app/Modules/`). Overridable en tests para usar
     * namespaces custom. Si retorna `null`, se salta el prefix check
     * (cualquier clase que matchee por suffix entra).
     */
    protected function classesNamespacePrefix(): ?string
    {
        return 'App\\Modules';
    }

    /**
     * Imprime tabla human-readable con el reporte.
     *
     * @param  array<string, array{module: string, scope: string, source: string, abilities: array<int, array{name: string, description: ?string}>, action: string, count: int}>  $report
     */
    private function printTable(array $report): void
    {
        $rows = [];
        foreach ($report as $moduleName => $entry) {
            foreach ($entry['abilities'] as $a) {
                $rows[] = [
                    $entry['scope'],
                    $entry['source'],
                    $entry['action'],
                    $a['name'],
                    $a['description'] ?? '—',
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('No se descubrieron abilities.');

            return;
        }

        $this->table(['Scope', 'Source', 'Action', 'Ability', 'Description'], $rows);
    }
}
