<?php

declare(strict_types=1);

namespace Mk\Director\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
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
                            {--force : Escribir/actualizar filas en <scope>_abilities (skip prompt, always write)}
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

        // F10-B08 (R-PKG-050): type-coerce el option `module` a array.
        // Symfony auto-parsea `--module=Admin` (CLI) a `['Admin']` (array de 1),
        // pero cuando se llama programáticamente con
        // `$this->call('mk:discover-abilities', ['--module' => $scope])`,
        // Symfony envía string. `array_flip('Admin')` revienta con
        // `TypeError: array_flip(): Argument #1 must be of type array, string given`.
        //
        // Pre-fix, el scaffolder (MakeAuthUserCommand:1192) pineaba
        // `'--module' => $scope` (string) y el receiver reventaba al primer
        // `--discover`. Post-fix: normalizamos a array (defense-in-depth,
        // idempotente para callers que ya pasan array).
        $moduleArgs = $this->option('module');
        if (! is_array($moduleArgs)) {
            $moduleArgs = $moduleArgs === null ? [] : [$moduleArgs];
        }
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
        // 🔴 SINGULAR, Y ANTES ESTABA PLURALIZADO. `Str::snake(Str::plural(...))`
        // convertía el módulo `Member` en el scope `members`, y de ahí salía TODO
        // torcido:
        //
        //  - El placeholder `{scope}` de `#[Ability('{scope}.auth.login')]` se
        //    resolvía a `members.auth.login`, mientras la ruta exige
        //    `mk.ability:member.auth.login`. La ability quedaba escrita en la
        //    tabla y NINGUNA ruta la miraba: permisos muertos, sin error.
        //  - `resolveAbilitiesTable()` buscaba `members_abilities`, pero
        //    `mk:module X --with-rbac` crea la tabla en SINGULAR
        //    (`create_{Str::snake($moduleName)}_abilities_table`), así que el
        //    camino per-scope no matcheaba nunca y caía al global por accidente.
        //  - `discoverAbilitiesFromMkConfig()`, en ESTE MISMO COMANDO, ya usaba
        //    `Str::snake($moduleName)` singular. Una sola corrida escribía con
        //    dos vocabularios: `members.auth.login` al lado de
        //    `member.members.viewAny`.
        //
        // Y ahora que existe el rol base, el precio subió: `AssignsBaseRole`
        // resuelve el rol por `guard = $user->getAuthScope()` (`member`), así que
        // un rol base creado con guard `members` no lo encontraría JAMÁS — la
        // feature entera muerta y en silencio.
        //
        // El scope es el prefijo de las abilities y el `guard` de los roles, y en
        // los dos lugares es singular. Ver `DiscoverAbilitiesScopeSingularTest`.
        $scope = Str::snake($moduleName);

        // D1 (hybrid): provider primario; attribute+docblock como fallback único.
        $discovery = $this->discoverAbilitiesFromProvider($moduleName, $moduleInfo);

        if ($discovery['source'] === 'provider') {
            $abilities = $discovery['abilities'];
        } else {
            // Fallback: atributos PHP + docblock combinados.
            // F10-B11 (R-PKG-050): pasar `$scope` para que el helper reemplace
            // el placeholder `{scope}` literal en `#[Ability('{scope}.auth.{action}')]`
            // attributes (pineados en BaseAuthController y demás clases del paquete)
            // con el scope real (`admin`, `member`, etc.) antes de pinear en la
            // DB. Pre-fix, el scaffolder pineaba `'{scope}.auth.login'` literal
            // (string con corchetes) como nombre de ability, requiriendo un
            // workaround tinker post-scaffold para replace.
            $abilities = $this->discoverAbilitiesFromAttributesAndDocblocks($moduleInfo, $scope);

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

            // El rol base va DESPUÉS del upsert y no antes: necesita los `id`
            // de las abilities, y una recién declarada todavía no existe hasta
            // que el upsert la escribe.
            $this->sincronizarRolBase($scope, $abilities);
        }

        return [
            'module' => $moduleName,
            'scope' => $scope,
            'source' => $discovery['source'],
            // 🔴 `baseline` VIAJA HASTA ACÁ A PROPÓSITO. Este mapper alimenta la
            // tabla humana Y el `--json` que consume el CI. Antes recortaba a
            // name+description, así que agregar la columna al reporte sin tocar
            // esto la habría mostrado vacía SIEMPRE — y el JSON del CI nunca se
            // habría enterado de que una ability concede permisos a todo el scope.
            'abilities' => array_values(array_map(
                fn (array $a): array => [
                    'name' => $a['name'],
                    'description' => $a['description'],
                    'baseline' => (bool) ($a['baseline'] ?? false),
                ],
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
            /** @var object $instance */
            $instance = $this->instanciarProvider($providerClass);
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
            // Forma clásica: un string pelado. Sigue andando igual que siempre.
            if (is_string($name)) {
                $abilities[] = ['name' => $name, 'description' => null, 'baseline' => false];

                continue;
            }

            // 🔴 FORMA EXTENDIDA, Y NO ES UN CAPRICHO.
            // Cuando un módulo implementa `discoverAbilities()`, ese array es el
            // ÚNICO source-of-truth: los atributos se IGNORAN por completo. Sin
            // esta forma, un módulo que use el provider NO PODRÍA declarar una
            // baseline NUNCA — la feature tendría un agujero justo en los
            // módulos más grandes, que son los que usan el provider.
            //
            //   return [
            //       'admin.posts.viewAny',                                  // role-gated
            //       ['name' => 'admin.profile.view', 'baseline' => true],   // baseline
            //   ];
            if (is_array($name) && isset($name['name']) && is_string($name['name'])) {
                $abilities[] = [
                    'name' => $name['name'],
                    'description' => isset($name['description']) && is_string($name['description'])
                        ? $name['description']
                        : null,
                    'baseline' => (bool) ($name['baseline'] ?? false),
                ];
            }
        }

        return ['source' => 'provider', 'abilities' => $abilities];
    }

    /**
     * Instancia el provider del módulo para poder preguntarle sus abilities.
     *
     * 🔴 `app($providerClass)` NO ALCANZA, Y ÉSTE ERA EL BUG. El constructor de
     * `Illuminate\Support\ServiceProvider` pide `$app`, un parámetro que el
     * contenedor NO puede autowirear (no hay binding para el tipo `Application`
     * como parámetro posicional sin nombre). Todo provider real —o sea, todo el
     * que extiende `ServiceProvider`, que es la totalidad de los providers de
     * módulo— reventaba con:
     *
     *     Unresolvable dependency resolving [Parameter #0 [ <required> $app ]]
     *
     * Y el `catch` de arriba lo convertía en un warning y seguía por el
     * fallback. O sea: el camino que la documentación llama FUENTE AUTORITATIVA
     * no funcionaba para nadie, y en vez de fallar escribía OTRAS abilities.
     * Silencioso, que es la peor forma.
     *
     * Los tests del paquete no lo veían porque instancian una clase `eval`-uada
     * sin constructor, que el contenedor resuelve sin problema. Un provider de
     * mentira que no comparte lo único que podía fallar.
     *
     * `resolveProvider()` es lo que usa el propio Laravel para instanciar
     * providers (`new $provider($this)`). Se prueba primero el contenedor para
     * no romper a un consumidor que tenga su provider bindeado con
     * dependencias propias.
     */
    private function instanciarProvider(string $providerClass): object
    {
        $app = app();

        if (is_subclass_of($providerClass, ServiceProvider::class)) {
            // `resolveProvider()` vive en `Application`, no en `Container`: en
            // un contenedor pelado (tests del paquete) hay que construirlo a
            // mano igual que lo hace Laravel.
            return method_exists($app, 'resolveProvider')
                ? $app->resolveProvider($providerClass)
                : new $providerClass($app);
        }

        return $app->make($providerClass);
    }

    /**
     * Source fallback: atributos PHP + docblock combinados.
     *
     * Atributos son primary; docblocks son secundarios dentro del fallback.
     *
     * F10-B11 (R-PKG-050): el parámetro `$scope` se usa para reemplazar el
     * placeholder `{scope}` en los `#[Ability('{scope}.auth.{action}')]`
     * attributes (pineados en BaseAuthController del paquete). Pre-fix, el
     * command leía el attribute literal y pineaba `'{scope}.auth.login'`
     * (string con corchetes) en la DB. Post-fix: str_replace('{scope}',
     * $scope, $attr->name) para que el nombre final sea `admin.auth.login`
     * (o `member.auth.login`, etc.) — matchea el `mk.ability:{scope}.auth.*`
     * route middleware pineado por el scaffolder.
     *
     * @param  array{path: string, classes: array<int, string>}  $moduleInfo
     * @return array<int, array{name: string, description: ?string}>
     */
    private function discoverAbilitiesFromAttributesAndDocblocks(array $moduleInfo, string $scope = ''): array
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
                        // F10-B11: reemplazar `{scope}` con el scope real.
                        $name = $scope !== '' ? str_replace('{scope}', $scope, $instance->name) : $instance->name;
                        $description = $scope !== '' && $instance->description !== null
                            ? str_replace('{scope}', $scope, $instance->description)
                            : $instance->description;
                        $abilities[] = [
                            'name' => $name,
                            'description' => $description,
                            'baseline' => $instance->baseline,
                        ];
                    } catch (Throwable) {
                        continue;
                    }
                }

                // 2. Docblock @mk-ability (secondary within fallback).
                $doc = $method->getDocComment();
                if ($doc !== false && preg_match('/@mk-ability\s+([a-z0-9._*-]+)(?:\s+(.+))?/i', $doc, $m)) {
                    // F10-B11: idem reemplazo en docblock @mk-ability.
                    $name = $scope !== '' ? str_replace('{scope}', $scope, $m[1]) : $m[1];
                    $description = $scope !== '' && isset($m[2]) ? str_replace('{scope}', $scope, $m[2]) : ($m[2] ?? null);
                    $abilities[] = [
                        'name' => $name,
                        'description' => $description,
                        // El docblock `@mk-ability` no tiene forma de expresar
                        // baseline, y no se la vamos a inventar: es el camino
                        // legacy. Quien necesite baseline usa el atributo.
                        'baseline' => false,
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

        // 🔴 LA COLUMNA `is_baseline` NO SE DA POR SENTADA.
        //
        // La migración del paquete sólo puede agregarla a la tabla `abilities`
        // global. Las `{scope}_abilities` per-scope las creó el consumidor con
        // `mk:module X --with-rbac`, y el paquete no sabe ni cuáles son. Escribir
        // la columna a ciegas revienta con un error de SQL que no nombra el
        // problema real ("column is_baseline does not exist" no le dice a nadie
        // que le falta una migración del paquete).
        //
        // Si no está, se persiste todo lo demás y se AVISA — pero sólo si había
        // algo que perder. Avisar cuando no hay ninguna baseline declarada sería
        // ruido en cada corrida.
        $conBaseline = $this->tieneColumnaBaseline($table);
        $baselines = array_values(array_filter($abilities, static fn (array $a): bool => (bool) ($a['baseline'] ?? false)));

        if (! $conBaseline && $baselines !== []) {
            $this->warn(
                "   ⚠ {$table} no tiene la columna `is_baseline`: se declararon "
                .count($baselines).' abilities con `baseline: true` y NO se van a persistir como tales. '
                .($table === 'abilities'
                    ? 'Corré `php artisan migrate` (falta la migración del paquete).'
                    : "La tabla per-scope `{$table}` la generó el scaffolder: agregale la columna con una migración propia.")
            );
        }

        $now = now();
        $rows = array_map(static function (array $a) use ($now, $conBaseline): array {
            $fila = [
                'name' => $a['name'],
                'description' => $a['description'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($conBaseline) {
                $fila['is_baseline'] = (bool) ($a['baseline'] ?? false);
            }

            return $fila;
        }, $abilities);

        // 🔴 `is_baseline` VA EN LA LISTA DE UPDATE, y esa es la mitad del valor.
        // Sin eso, marcar `baseline: true` en una ability que ya existía no haría
        // nada: el UPSERT la encontraría por `name` y se saltearía la columna. El
        // flag sólo funcionaría en abilities nuevas — y peor, SACAR el flag no lo
        // revocaría jamás. Tiene que ser una asignación, no un alta.
        $alActualizar = ['description', 'updated_at'];
        if ($conBaseline) {
            $alActualizar[] = 'is_baseline';
        }

        DB::table($table)->upsert($rows, ['name'], $alActualizar);

        $this->info(
            "   → {$table}: ".count($rows).' abilities UPSERT-ed'
            .($conBaseline ? ' ('.count($baselines).' baseline).' : '.')
        );
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
    /**
     * Deja el ROL BASE del scope igual a lo que declara el código.
     *
     * El rol base junta las abilities marcadas `#[Ability(..., baseline: true)]`:
     * las que tiene cualquier usuario autenticado del scope por existir. Hay uno
     * por scope, distinguidos por `guard`.
     *
     * 🔴 LA REGLA ES DE UNA LÍNEA: SÓLO TOCA LO QUE PUSO ÉL MISMO.
     *
     * Cada vinculación que crea queda marcada con `ability_role.is_baseline = 1`.
     * Al reconciliar, agrega las baseline que faltan y saca las suyas que dejaron
     * de serlo. Las filas con 0 —las que sembró el `{Scope}RolesSeeder`, las que
     * agregó un admin desde la UI— no las mira nunca.
     *
     * Sin esa marca la reconciliación sería imposible de hacer bien: una ability
     * que perdió el flag y una que un admin agregó a mano terminan las dos con
     * `abilities.is_baseline = false` dentro del rol base, indistinguibles. Ver
     * el porqué largo en la migración `..._add_is_baseline_to_ability_role_table`.
     *
     * 🔴 NUNCA CREA UN ROL VACÍO. Si el scope no declaró ninguna baseline y no
     * hay un rol base de antes, no pasa nada. Un rol sin permisos colgando en la
     * UI de todos los scopes que no usan la feature es basura, y peor: invita a
     * que alguien le cuelgue cosas creyendo que hace algo.
     *
     * 🔴 EL GUARD SALE DEL NOMBRE DE LA ABILITY, NO DEL MÓDULO. Un módulo puede
     * declarar abilities de VARIOS scopes: en RETO, `Communications` declara
     * `admin.posts.*` (backoffice) y `admin.wall.*` + `member.wall.*` (el muro,
     * espejado por scope). Atar el guard al módulo le pondría guard
     * `communications` a un rol base que tienen que encontrar usuarios cuyo
     * `getAuthScope()` dice `member` — o sea, nunca. El scope es el PRIMER
     * SEGMENTO del nombre, que es la convención que el `mk.ability:` middleware
     * ya usa en todo el ecosistema. Por eso agrupa y puede tocar más de un rol
     * base en la misma corrida.
     *
     * Una ability sin punto (`*`) no nombra ningún scope y se ignora: no hay de
     * dónde sacarle un guard, y adivinárselo sería conceder permisos por descarte.
     *
     * @param  array<int, array{name: string, description: ?string, baseline?: bool}>  $abilities
     */
    private function sincronizarRolBase(string $scope, array $abilities): void
    {
        $tablaAbilities = $this->resolveAbilitiesTable($scope);

        // Mismo criterio que en el upsert: sin las columnas/tablas no se
        // adivina. Y acá el silencio alcanza — `upsertAbilities()` ya avisó.
        if (! $this->tieneColumnaBaseline($tablaAbilities)
            || ! $this->tableExists('roles')
            || ! $this->tableExists('ability_role')
            || ! $this->tieneColumnaBaselineEnPivot()) {
            return;
        }

        // Agrupadas por el scope que nombran. `$scope` (el del módulo) queda sólo
        // para resolver la TABLA, que sí es per-módulo.
        $porScope = [];
        foreach ($abilities as $a) {
            if (! (bool) ($a['baseline'] ?? false)) {
                continue;
            }

            $delNombre = $this->scopeDelNombre($a['name']);
            if ($delNombre === null) {
                $this->warn("   ⚠ `{$a['name']}` está marcada baseline pero su nombre no dice a qué scope pertenece (falta el prefijo `{scope}.`): se ignora.");

                continue;
            }

            $porScope[$delNombre][$a['name']] = true;
        }

        // Un scope sin baselines declaradas puede tener igual un rol base de
        // antes que hay que vaciar — por eso también entra el guard del módulo,
        // que es el que se venía sincronizando.
        if (! isset($porScope[$scope])) {
            $porScope[$scope] = [];
        }

        foreach ($porScope as $guard => $nombres) {
            // `$nombres` ya no se pasa: la reconciliación lee la tabla, no lo
            // que declaró este módulo. Ver `reconciliarRolBase()`.
            $this->reconciliarRolBase($guard, $tablaAbilities);
        }
    }

    /**
     * El scope que nombra una ability: `member.wall.viewAny` → `member`.
     *
     * `null` si el nombre no lleva prefijo de scope (`*`, `admin` a secas).
     */
    private function scopeDelNombre(string $name): ?string
    {
        $pos = strpos($name, '.');

        if ($pos === false || $pos === 0) {
            return null;
        }

        return substr($name, 0, $pos);
    }

    /**
     * Deja el rol base de UN guard igual a TODAS las baselines de ese guard.
     *
     * 🔴 NO RECONCILIA CONTRA `$declaradas`, Y ESA ES LA CORRECCIÓN.
     * ==============================================================
     *
     * Este método corre UNA VEZ POR MÓDULO. Cuando reconciliaba contra lo que
     * declaraba el módulo de turno, cada módulo BORRABA las baselines de los
     * otros y dejaba sólo las suyas. El rol base terminaba con las del ÚLTIMO
     * módulo procesado, y el orden lo decide el registro de providers.
     *
     * Medido en RETO, corrida completa con dos módulos que declaran baselines:
     *
     *     módulo A ....... rol base: +0 / -4  → queda con 0
     *     módulo Events .. rol base: +2 / -0  → queda con 2
     *     módulo Comms ... rol base: +4 / -2  → queda con 4
     *
     * Los members quedaban SIN las abilities de Eventos aunque el reporte del
     * comando dijera `BASELINE` en verde para las dos. La feature funcionaba
     * mientras hubiera UN solo módulo con baselines, que es exactamente el caso
     * en que se construyó y se probó.
     *
     * La lista correcta sale de `abilities.is_baseline`, que el upsert mantiene
     * por módulo y que por lo tanto conoce a TODOS los módulos ya descubiertos
     * — incluso en una corrida acotada con `--module=X`, porque las filas de
     * los demás ya están en la tabla con su flag.
     *
     * 🔴 Esto NO contradice que la marca de reconciliación viva en la
     * VINCULACIÓN (`ability_role.is_baseline`). Son dos preguntas distintas:
     * `abilities.is_baseline` dice CUÁLES deberían estar en el rol;
     * `ability_role.is_baseline` dice cuáles puse YO y por lo tanto puedo
     * sacar, para no tocar las que un admin agregó a mano. Las dos hacen falta.
     */
    private function reconciliarRolBase(string $scope, string $tablaAbilities): void
    {
        $nombreRol = (string) config('mk_director.auth.base_role', 'base');

        $rol = DB::table('roles')->where('name', $nombreRol)->where('guard', $scope)->first();

        // Todas las baselines de este guard, las declare el módulo que las
        // declare. El prefijo del nombre ES el guard, misma convención que usa
        // `mk.ability:` y que `scopeDelNombre()` implementa.
        $todasLasDelGuard = DB::table($tablaAbilities)
            ->where('is_baseline', true)
            ->where('name', 'like', $scope.'.%')
            ->pluck('id')
            ->all();

        if ($todasLasDelGuard === [] && $rol === null) {
            return;
        }

        if ($rol === null) {
            $rolId = DB::table('roles')->insertGetId([
                'name' => $nombreRol,
                'guard' => $scope,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->info("   → rol base `{$nombreRol}` (guard {$scope}) creado.");
        } else {
            $rolId = $rol->id;
        }

        // Ver el docblock: la lista sale de la TABLA, no del módulo de turno.
        $idsDeclaradas = $todasLasDelGuard;

        // Lo que YO puse antes en este rol.
        $idsMias = DB::table('ability_role')
            ->where('role_id', $rolId)
            ->where('is_baseline', true)
            ->pluck('ability_id')
            ->all();

        $aAgregar = array_diff($idsDeclaradas, $idsMias);
        $aSacar = array_diff($idsMias, $idsDeclaradas);

        if ($aAgregar !== []) {
            DB::table('ability_role')->insert(array_map(static fn ($id): array => [
                'ability_id' => $id,
                'role_id' => $rolId,
                'is_baseline' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ], array_values($aAgregar)));
        }

        if ($aSacar !== []) {
            // El `where` de `is_baseline` es redundante —los ids salieron de ahí—
            // y se deja igual: es la invariante del método y hace que un DELETE
            // suelto no pueda llevarse por delante lo que puso una persona.
            DB::table('ability_role')
                ->where('role_id', $rolId)
                ->where('is_baseline', true)
                ->whereIn('ability_id', array_values($aSacar))
                ->delete();
        }

        if ($aAgregar !== [] || $aSacar !== []) {
            $this->info(
                "   → rol base `{$nombreRol}`: +".count($aAgregar).' / -'.count($aSacar)
                .' (queda con '.count($idsDeclaradas).' baseline).'
            );
        }
    }

    /**
     * ¿La pivot `ability_role` tiene la marca `is_baseline`?
     *
     * Sin ella el rol base NO se toca. Podría sincronizarse igual, pero sin
     * poder distinguir lo propio de lo ajeno el precio de equivocarse es sacarle
     * permisos a alguien — y eso no se hace a las apuradas por una columna que
     * falta. Falta la migración: que se corra.
     */
    private function tieneColumnaBaselineEnPivot(): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }

            return app('db')->connection()->getSchemaBuilder()->hasColumn('ability_role', 'is_baseline');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ¿La tabla de abilities tiene la columna `is_baseline`?
     *
     * Mismo criterio que `tableExists()`: ante la duda, `false`. Y esa asimetría
     * es la correcta acá — no persistir el flag deja permisos SIN dar, que se ve
     * y se reclama. Escribir una columna que no existe rompe el comando entero y
     * no persiste NADA, ni siquiera las abilities que sí se podían guardar.
     */
    private function tieneColumnaBaseline(string $table): bool
    {
        try {
            if (! function_exists('app') || ! app()->bound('db')) {
                return false;
            }

            return app('db')->connection()->getSchemaBuilder()->hasColumn($table, 'is_baseline');
        } catch (Throwable) {
            return false;
        }
    }

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
            } catch (Throwable $e) {
                // R-PKG-034 BUG-NEW-34: antes el catch era silencioso y el
                // consumer no tenía forma de diagnosticar por qué un
                // controller scaffoldeado no aparecía en `mk:discover-abilities`.
                // Ahora logueamos a nivel `warning` con path + error message.
                // El archivo se skipea (continue) igual que antes para mantener
                // BC-safe — solo agregamos observabilidad.
                Log::warning(sprintf(
                    '[mk-director] require_once falló durante class discovery. Path: %s. Error: %s',
                    $realPath,
                    $e->getMessage()
                ));

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
                    // El CRUD generado es role-gated por definición: crear y
                    // borrar recursos no es la línea de base de nadie.
                    'baseline' => false,
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
                    // 🔴 BASELINE SE MUESTRA, Y NO ES DECORACIÓN.
                    // Es la diferencia entre "esto lo puede hacer quien tenga el
                    // rol" y "esto lo puede hacer CUALQUIERA que esté logueado".
                    // Un flag que concede permisos a todo un scope y no aparece
                    // en ningún lado sólo se audita abriendo controllers de a uno.
                    ($a['baseline'] ?? false) ? 'BASELINE' : '',
                    $a['description'] ?? '—',
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('No se descubrieron abilities.');

            return;
        }

        $this->table(['Scope', 'Source', 'Action', 'Ability', 'Base', 'Description'], $rows);

        // BACK-02 (FEEDBACK5): la columna `Source` confundía ("¿lee las rutas reales
        // o deriva por nombre?"). Leyenda explícita de los dos valores posibles:
        $this->newLine();
        $this->line('  <comment>Source</comment>:');
        $this->line('    • <info>provider</info> — el {Scope}ModuleServiceProvider::discoverAbilities() declaró');
        $this->line('      las abilities explícitamente (fuente autoritativa).');
        $this->line('    • <info>fallback</info> — el provider NO implementa discoverAbilities(); las abilities');
        $this->line('      se derivaron de atributos #[MkAbility] + docblocks @mk-ability en los');
        $this->line('      controllers (convención). NO es introspección de rutas: `route:list` es');
        $this->line('      sólo informativo, el descubrimiento NO lee la routing table.');
    }
}
