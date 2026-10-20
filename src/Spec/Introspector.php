<?php

declare(strict_types=1);

namespace Mk\Director\Spec;

use BackedEnum;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Console\Commands\DiscoverAbilitiesCommand;
use Mk\Director\Controllers\SmartController;
use PDO;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The running app's facts for `@makroz/spec` (`mk-studio spec import` / `mk-studio spec check`).
 *
 * Everything is read from the booted app, never parsed from source: `$fillable` comes from
 * `getFillable()`, casts from `getCasts()`, columns from the real database, `$mkConfig` by
 * reflection. A grep of a model file is exactly what loses `client_id` (Makromania CLAUDE.md).
 * Nothing is written. Relations are built on fresh instances, which only builds the query
 * object; the only rows read are the ability tables' and the distinct `*_type` values of each
 * polymorphic pair (its targets, {@see morphs()}).
 */
final class Introspector
{
    /** @param  list<string>  $modulePaths  folders whose children are modules (`app/Modules`) */
    public function __construct(private array $modulePaths) {}

    /** @return array{driver: string, version: string, models: list<array>, tables: list<array>, morphMap: array<string, array>, morphs: list<array>, enums: list<array>, abilities: list<array>, routes: list<array>, crud: list<array>, storedAbilities: list<array>} */
    public function collect(): array
    {
        $models = $this->models();
        $routes = $this->routes();
        $tables = $this->tables($models);
        $morphMap = $this->morphMap();
        $morphs = $this->morphs($tables, $models, $morphMap);
        // A `morphTo`'s targets are its pair's: the same evidence the database and naming sources read.
        foreach ($models as &$model) {
            foreach ($model['relations'] as &$relation) {
                if ($relation['type'] === 'MorphTo') {
                    $pair = array_find($morphs, static fn (array $m): bool => $m['table'] === $model['table'] && $m['type'] === $relation['morphType']);
                    $relation['targets'] = $pair['targets'] ?? array_keys($morphMap);
                }
            }
        }
        unset($model, $relation);
        $models = array_map(static fn (array $m): array => [...$m, 'relations' => (object) $m['relations']], $models);

        return [
            'driver' => $this->driver(),
            'version' => (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
            'models' => $models,
            'tables' => $tables,
            'morphMap' => (object) $morphMap,
            'morphs' => $morphs,
            'enums' => $this->enums(),
            'abilities' => $this->abilities(),
            'routes' => $routes,
            'crud' => $this->crud(),
            'storedAbilities' => $this->storedAbilities($routes),
        ];
    }

    /** @return list<array> */
    private function models(): array
    {
        $out = [];
        foreach ($this->classes('Models') as [$module, $class]) {
            $reflection = new ReflectionClass($class);
            // `Generated/Models/*Base` are abstract: the concrete hand class is the model.
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }
            /** @var Model $model */
            $model = new $class;
            $traits = array_values(class_uses_recursive($class));
            sort($traits);
            $out[] = [
                'class' => $class,
                'module' => $module,
                'table' => $model->getTable(),
                'keyType' => $model->getKeyType(),
                'incrementing' => $model->getIncrementing(),
                'timestamps' => $model->usesTimestamps(),
                'softDeletes' => in_array(SoftDeletes::class, $traits, true),
                'fillable' => $model->getFillable(),
                'guarded' => $model->getGuarded(),
                'casts' => (object) $model->getCasts(),
                'attributes' => (object) $model->getAttributes(),
                'traits' => $traits,
                'relations' => $this->relations($model),
                // Only a public one counts: `autoTransform()` reads `$model->apiResource`, and Eloquent
                // answers a protected property with a (null) attribute.
                'apiResource' => $reflection->hasProperty('apiResource') && $reflection->getProperty('apiResource')->isPublic()
                    ? $model->apiResource
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Public zero-argument methods whose declared return type is a `Relation`, called on a fresh
     * instance: that only builds the relation object (no query runs). A `morphTo` on a fresh instance has no type to
     * read, so Eloquent builds it on the model itself: its `related` is the model, and its targets come from the pair
     * ({@see morphs()}). The other morph relations name their pair and the alias they write (`morphClass`).
     *
     * @return array<string, array{type: string, related: string, table: string, foreignKey?: string, morphType?: string, morphId?: string, morphClass?: string}>
     */
    private function relations(Model $model): array
    {
        $out = [];
        foreach ((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $type = $method->getReturnType();
            if ($method->isStatic() || $method->getNumberOfRequiredParameters() > 0
                || ! $type instanceof ReflectionNamedType || $type->isBuiltin()
                || ! is_a($type->getName(), Relation::class, true)) {
                continue;
            }
            try {
                $relation = $model->{$method->getName()}();
            } catch (Throwable) {
                continue;
            }
            // `declaredIn`: a relation a package base class brings (AuthUser's `abilities()`) is not the module's.
            $entry = ['type' => class_basename($relation), 'related' => $relation->getRelated()::class, 'table' => $relation->getRelated()->getTable(), 'declaredIn' => $method->getDeclaringClass()->getName()];
            if (method_exists($relation, 'getForeignKeyName')) {
                $entry['foreignKey'] = $relation->getForeignKeyName();
            } elseif (method_exists($relation, 'getForeignPivotKeyName')) {
                $entry['foreignKey'] = $relation->getForeignPivotKeyName();
                $entry['pivot'] = $relation->getTable();
            }
            if ($relation instanceof MorphTo || $relation instanceof MorphOneOrMany) {
                $entry['morphType'] = $relation->getMorphType();
                $entry['morphId'] = $relation->getForeignKeyName();
            }
            if ($relation instanceof MorphOneOrMany) {
                $entry['morphClass'] = $relation->getMorphClass();
            }
            $out[$method->getName()] = $entry;
        }
        ksort($out);

        return $out;
    }

    /**
     * The models' tables and every other table of the database (the package's `mk_comments`, pivots): relations that
     * point at a module from a table no module owns are found there.
     *
     * @return list<array>
     */
    private function tables(array $models): array
    {
        $names = array_values(array_unique([...array_column($models, 'table'), ...Schema::getTableListing(Schema::getCurrentSchemaListing(), false)]));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            $partial = $this->partialIndexes($name);
            if (! Schema::hasTable($name)) {
                $out[] = ['name' => $name, 'missing' => true, 'columns' => [], 'indexes' => [], 'foreignKeys' => []];

                continue;
            }
            $out[] = [
                'name' => $name,
                'columns' => array_values(array_map(static fn (array $c): array => [
                    'name' => $c['name'],
                    'type_name' => $c['type_name'],
                    'type' => $c['type'],
                    'nullable' => (bool) $c['nullable'],
                    'default' => $c['default'],
                ], Schema::getColumns($name))),
                'indexes' => array_values(array_map(static fn (array $i): array => [
                    'name' => $i['name'],
                    'columns' => $i['columns'],
                    'unique' => (bool) $i['unique'],
                    'primary' => (bool) $i['primary'],
                    'partial' => in_array($i['name'], $partial, true),
                ], Schema::getIndexes($name))),
                'foreignKeys' => array_values(array_map(static fn (array $f): array => [
                    'columns' => $f['columns'],
                    'on' => $f['foreign_table'],
                    'references' => $f['foreign_columns'],
                ], Schema::getForeignKeys($name))),
            ];
        }

        return $out;
    }

    /**
     * Names of the table's partial indexes (`… WHERE status = 1`). `Schema::getIndexes()` reports a
     * partial unique index like a full one, and "one active row" is not "unique": importing it as
     * `unique: true` would generate the wrong constraint. MySQL has no partial indexes.
     *
     * @return list<string>
     */
    private function partialIndexes(string $table): array
    {
        $sql = match (DB::connection()->getDriverName()) {
            'pgsql' => "select indexname as name from pg_indexes where tablename = ? and indexdef ilike '% where %'",
            'sqlite' => "select name from sqlite_master where type = 'index' and tbl_name = ? and sql like '% where %'",
            default => null,
        };

        return $sql === null ? [] : array_map(static fn (object $r): string => $r->name, DB::select($sql, [$table]));
    }

    /** `pgsql`, `mariadb` (also behind Laravel's `mysql` driver), `mysql` or `sqlite`: what the plans and rules depend on. */
    private function driver(): string
    {
        $driver = DB::connection()->getDriverName();
        $version = (string) DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);

        return $driver === 'mysql' && str_contains($version, 'MariaDB') ? 'mariadb' : $driver;
    }

    /** @return array<string, array{class: string, table: ?string}> the morph map: the alias a `*_type` column stores => its model */
    private function morphMap(): array
    {
        $out = [];
        foreach (Relation::morphMap() as $alias => $class) {
            try {
                $table = (new $class)->getTable();
            } catch (Throwable) {
                $table = null;
            }
            $out[$alias] = ['class' => $class, 'table' => $table];
        }
        ksort($out);

        return $out;
    }

    /**
     * Every `<name>_type` + `<name>_id` pair of every table: one polymorphic relation, not two. Its targets are what is
     * known of it, in this order of evidence:
     *  - the aliases its rows store (read only: `select distinct … limit`) and those the same name stores in other
     *    tables (`author` is the same role in `posts`, `mk_comments` and `mk_reactions`);
     *  - the models that declare the inverse (`morphMany(MkComment::class, 'commentable')` makes `post` a target);
     *  - else every alias of the morph map (`guessed`).
     *
     * ponytail: the distinct read scans the column on a table without an index on it; fine on a dev database, the
     * place introspection runs. A name shared by two unrelated roles mixes their targets.
     *
     * @param  list<array>  $tables
     * @param  list<array>  $models
     * @param  array<string, array>  $morphMap
     * @return list<array{table: string, name: string, type: string, id: string, targets: list<string>, guessed: bool}>
     */
    private function morphs(array $tables, array $models, array $morphMap): array
    {
        $pairs = [];
        foreach ($tables as $table) {
            $columns = array_column($table['columns'], 'name');
            foreach ($columns as $column) {
                if (str_ends_with($column, '_type') && in_array($name = substr($column, 0, -5).'_id', $columns, true)) {
                    $pairs[] = ['table' => $table['name'], 'name' => substr($column, 0, -5), 'type' => $column, 'id' => $name];
                }
            }
        }
        $stored = [];
        $declared = [];
        foreach ($pairs as $p) {
            try {
                $values = DB::table($p['table'])->whereNotNull($p['type'])->distinct()->limit(50)->pluck($p['type'])->all();
            } catch (Throwable) {
                $values = [];
            }
            $stored[$p['name']] = [...($stored[$p['name']] ?? []), ...array_map('strval', $values)];
        }
        foreach ($models as $m) {
            foreach ($m['relations'] as $r) {
                if (isset($r['morphClass'])) {
                    $declared[$r['table'].'.'.$r['morphType']][] = $r['morphClass'];
                }
            }
        }
        $out = [];
        foreach ($pairs as $p) {
            $known = array_values(array_unique([...($stored[$p['name']] ?? []), ...($declared[$p['table'].'.'.$p['type']] ?? [])]));
            sort($known);
            $out[] = [...$p, 'targets' => $known ?: array_keys($morphMap), 'guessed' => $known === []];
        }

        return $out;
    }

    /** @return list<array> */
    private function enums(): array
    {
        $out = [];
        foreach ($this->classes('Enums') as [$module, $class]) {
            if (! enum_exists($class) || ! (new ReflectionEnum($class))->isBacked()) {
                continue;
            }
            /** @var class-string<BackedEnum> $class */
            $hasLabel = method_exists($class, 'label');
            $default = method_exists($class, 'default') && (new ReflectionMethod($class, 'default'))->isStatic()
                ? $class::default()
                : null;
            $out[] = [
                'class' => $class,
                'module' => $module,
                'doc' => self::docText((new ReflectionEnum($class))->getDocComment()),
                'cases' => array_map(static fn (BackedEnum $case): array => [
                    'name' => $case->name,
                    'value' => $case->value,
                    ...($hasLabel ? ['label' => $case->label()] : []),
                ], $class::cases()),
                'default' => $default instanceof BackedEnum ? $default->name : null,
            ];
        }

        return $out;
    }

    /** A `/** … *\/` comment as plain text: delimiters and ` * ` prefixes gone, line breaks kept. */
    public static function docText(string|false $doc): ?string
    {
        if ($doc === false) {
            return null;
        }
        $lines = explode("\n", trim(substr(trim($doc), 3, -2)));
        $lines = array_map(static fn (string $l): string => rtrim((string) preg_replace('/^\s*\* ?/', '', $l)), $lines);

        return trim(implode("\n", $lines), "\n");
    }

    /**
     * The very discovery `mk:discover-abilities` runs (provider `discoverAbilities()`, else the
     * attribute/docblock/`$mkConfig` fallback), as its own `--dry-run --json` report: reusing the
     * command keeps one definition of "the abilities of a module". Dry run: nothing is written.
     *
     * @return list<array{module: string, source: string, name: string, description: ?string, baseline: bool}>
     */
    private function abilities(): array
    {
        $command = new DiscoverAbilitiesCommand;
        $command->setLaravel(app());
        $buffer = new BufferedOutput;
        $input = new ArrayInput(['--dry-run' => true, '--json' => true]);
        $input->setInteractive(false);
        $command->setOutput(new OutputStyle($input, $buffer));
        $command->run($input, $buffer);

        // Warnings ("provider failed, fallback") may precede the JSON; it starts at a line `{`.
        $text = $buffer->fetch();
        $start = preg_match('/^\{$/m', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
        $report = $start === null ? [] : json_decode(substr($text, $start), true, flags: JSON_THROW_ON_ERROR);

        $out = [];
        foreach ($report as $module) {
            foreach ($module['abilities'] as $a) {
                $out[] = ['module' => $module['module'], 'source' => $module['source'], ...$a];
            }
        }

        return $out;
    }

    /**
     * The rows of the ability table each route scope writes to (`{scope}_abilities` when it exists,
     * else `abilities`, as `mk:discover-abilities` resolves it): the description of an ability that
     * only a seeder created. Read only.
     *
     * @param  list<array{scopes: list<string>}>  $routes
     * @return list<array{table: string, name: string, description: ?string}>
     */
    private function storedAbilities(array $routes): array
    {
        $scopes = array_unique(array_merge(...array_column($routes, 'scopes')));
        $tables = array_unique(array_map(static fn (string $s): string => Schema::hasTable("{$s}_abilities") ? "{$s}_abilities" : 'abilities', $scopes));
        sort($tables);
        $out = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (DB::table($table)->orderBy('name')->get(['name', 'description']) as $row) {
                $out[] = ['table' => $table, 'name' => $row->name, 'description' => $row->description];
            }
        }

        return $out;
    }

    /** @return list<array{uri: string, methods: list<string>, action: string, abilities: list<string>, scopes: list<string>}> */
    private function routes(): array
    {
        $out = [];
        /** @var LaravelRoute $route */
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $args = static function (string $prefix) use ($route): array {
                $found = [];
                foreach ($route->middleware() as $middleware) {
                    if (is_string($middleware) && str_starts_with($middleware, $prefix)) {
                        array_push($found, ...explode(',', substr($middleware, strlen($prefix))));
                    }
                }

                return $found;
            };
            $out[] = [
                'uri' => $route->uri(),
                'methods' => $route->methods(),
                'action' => ltrim($route->getActionName(), '\\'),
                'abilities' => $args('mk.ability:'),
                'scopes' => $args('mk.auth:'),
            ];
        }

        return $out;
    }

    /** @return list<array{controller: string, module: string, mkConfig: array}> */
    private function crud(): array
    {
        $out = [];
        foreach ($this->classes('Http/Controllers') as [$module, $class]) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(SmartController::class)) {
                continue;
            }
            // Inherited defaults included: a hand subclass of a generated `*ControllerBase` reports the base's config.
            $out[] = ['controller' => $class, 'module' => $module, 'mkConfig' => $reflection->getDefaultProperties()['mkConfig'] ?? []];
        }

        return $out;
    }

    /**
     * Classes under `<module>/<subdir>` of every module, as [module, FQCN]. The FQCN is the file's
     * `namespace` plus its name (PSR-4); a class the autoloader does not know is required, as
     * `mk:discover-abilities` does.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function classes(string $subdir): array
    {
        $out = [];
        foreach ($this->modulePaths as $root) {
            foreach (glob(rtrim($root, '/').'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
                $dir = $moduleDir.'/'.$subdir;
                if (! is_dir($dir)) {
                    continue;
                }
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
                $paths = [];
                foreach ($files as $file) {
                    if ($file->getExtension() === 'php') {
                        $paths[] = $file->getPathname();
                    }
                }
                sort($paths);
                foreach ($paths as $path) {
                    if (! preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($path), $ns)) {
                        continue;
                    }
                    $class = $ns[1].'\\'.basename($path, '.php');
                    if (! class_exists($class) && ! enum_exists($class)) {
                        require_once $path;
                    }
                    if (class_exists($class) || enum_exists($class)) {
                        $out[] = [basename($moduleDir), $class];
                    }
                }
            }
        }

        return $out;
    }
}
