<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Managers\ListManager;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * La búsqueda del CRUD generado sólo mira columnas que la tabla del scope TIENE.
 *
 * El Repository generado buscaba en `full_name` y `ci`, y filtraba por
 * `is_active`: columnas de un consumer viejo, no de un scope por default (name,
 * email, phone, status). Con la primera letra tipeada en el buscador, el SQL
 * reventaba contra una columna inexistente. Y el `searchable` del controller
 * (el que usa el listado de verdad, vía `ListManager`) estaba fijo en
 * `['name', 'email']`: con `--login-field=ci` no se podía buscar por CI.
 *
 * Ahora las dos listas salen de las columnas reales: nombre, campo de login y
 * los profile fields de texto (no los de archivo, no `status`, que es un filtro
 * de enum y no texto libre).
 *
 * 🔴 Contra sqlite real, con la migración GENERADA corrida. PERO sqlite no
 * alcanza para ver el bug: un identificador entre comillas dobles que no
 * resuelve a una columna lo toma como STRING LITERAL (`"full_name" like '%x%'`
 * compara la palabra `full_name`), así que la búsqueda rota corre sin error y
 * sin resultados falsos. En Postgres/MySQL es un 500. Por eso, además de
 * encontrar la fila, se registran las consultas y se exige que toda columna
 * citada en ellas exista en la tabla. Cada test usa un scope distinto porque
 * las clases generadas se cargan en este proceso.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    $this->cleanScaffolderTempDirs();
});

/**
 * Genera el scope, corre sus migraciones y carga las clases que la búsqueda usa.
 *
 * @param  array<string, mixed>  $args
 * @return array{0: string, 1: string} [módulo, tabla]
 */
function generateAndLoadSearchableScope(object $test, array $args, string $table): array
{
    [$exit, $output, $base] = $test->runScaffolderInTempDir($args);
    expect($exit)->toBe(0, $output);

    $scope = $args['scope'];
    $module = "{$base}/app/Modules/{$scope}";

    $migrations = array_merge(
        glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [],
        glob("{$module}/Database/Migrations/*.php") ?: [],
    );
    foreach ($migrations as $migration) {
        (require $migration)->up();
    }

    foreach (["Enums/{$scope}Status.php", "Models/{$scope}.php", "Repositories/Contracts/{$scope}RepositoryInterface.php", "Repositories/{$scope}Repository.php", "Http/Controllers/{$scope}Controller.php"] as $file) {
        require "{$module}/{$file}";
    }

    return [$module, $table];
}

/** @return array<int, string> el `searchable` que declara el controller generado */
function generatedSearchable(string $scope): array
{
    $mkConfig = (new ReflectionProperty("App\\Modules\\{$scope}\\Http\\Controllers\\{$scope}Controller", 'mkConfig'))->getDefaultValue();

    return $mkConfig['searchable'];
}

/**
 * Corre `$run` con el query log prendido y falla si alguna consulta cita una
 * columna de `$table` que la tabla no tiene.
 */
function assertQueriesOnlyReferenceExistingColumns(string $table, Closure $run): void
{
    $connection = app('db')->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();
    $run();
    $connection->disableQueryLog();

    $existing = Schema::getColumnListing($table);
    $checked = 0;
    foreach ($connection->getQueryLog() as $entry) {
        // Sólo las consultas sobre la tabla del scope (las de eager loading de
        // roles/abilities son de otras tablas), y sólo su WHERE: ahí vive la
        // búsqueda y los filtros.
        if (! str_contains($entry['query'], "from \"{$table}\"") || ! str_contains($entry['query'], ' where ')) {
            continue;
        }
        $where = substr($entry['query'], strpos($entry['query'], ' where ') + 7);
        preg_match_all('/(?:"'.preg_quote($table, '/').'"\.)?"(\w+)"/', $where, $m);
        foreach ($m[1] as $column) {
            $checked++;
            expect(in_array($column, $existing, true))->toBeTrue("la consulta cita `{$column}`, que `{$table}` no tiene: {$entry['query']}");
        }
    }
    // Contraprueba: se leyó al menos una columna (si no, el check no mide nada).
    expect($checked)->toBeGreaterThan(0);
}

function listSearch(string $modelClass, array $searchable, string $term): int
{
    return ListManager::apply(Request::create('/', 'GET', ['search' => $term]), new $modelClass, $searchable)->count();
}

test('scope por default: el Repository y el listado buscan por nombre y email sin tocar columnas inexistentes', function () {
    [, $table] = generateAndLoadSearchableScope($this, ['scope' => 'Seeker'], 'seekers');

    $model = 'App\\Modules\\Seeker\\Models\\Seeker';
    $model::create(['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'password' => 'secreto123']);
    $model::create(['name' => 'Otro Usuario', 'email' => 'otro@example.com', 'password' => 'secreto123']);

    // Toda columna buscable existe en la tabla que creó la migración generada.
    $searchable = generatedSearchable('Seeker');
    foreach ($searchable as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("`{$column}` no existe en `{$table}`");
    }

    // Repository: encuentra, y sus consultas no citan columnas inexistentes
    // (antes: `full_name`, `ci`, e `is_active` al filtrar).
    $repository = new ('App\\Modules\\Seeker\\Repositories\\SeekerRepository');
    expect($repository->paginate(['search' => 'Pérez'])->total())->toBe(1);
    expect($repository->paginate(['search' => 'ana@example'])->total())->toBe(1);
    assertQueriesOnlyReferenceExistingColumns($table, fn () => $repository->paginate(['search' => 'Pérez', 'is_active' => true]));

    // Listado (el camino de CRUDSmart): mismo resultado con el `searchable` generado.
    expect(listSearch($model, $searchable, 'Pérez'))->toBe(1);
    expect(listSearch($model, $searchable, 'otro@example'))->toBe(1);
});

test('--login-field=ci + profile fields: busca por CI y por los de texto; no por archivo, número ni status', function () {
    [, $table] = generateAndLoadSearchableScope($this, [
        'scope' => 'Finder',
        '--login-field' => 'ci',
        '--profile-fields' => 'nickname,bio:text,avatar:file,age:int',
    ], 'finders');

    $model = 'App\\Modules\\Finder\\Models\\Finder';
    $model::create(['name' => 'Beto', 'ci' => '4455667', 'nickname' => 'betito', 'bio' => 'cocinero de pizzas', 'password' => 'secreto123']);

    $searchable = generatedSearchable('Finder');
    expect($searchable)->toContain('name', 'ci', 'nickname', 'bio');
    expect($searchable)->not->toContain('avatar');
    expect($searchable)->not->toContain('age');
    expect($searchable)->not->toContain('status');
    foreach ($searchable as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue("`{$column}` no existe en `{$table}`");
    }

    $repository = new ('App\\Modules\\Finder\\Repositories\\FinderRepository');
    assertQueriesOnlyReferenceExistingColumns($table, fn () => $repository->paginate(['search' => 'betito', 'is_active' => true]));
    foreach (['4455667', 'betito', 'pizzas'] as $term) {
        expect($repository->paginate(['search' => $term])->total())->toBe(1, "Repository no encontró «{$term}»");
        expect(listSearch($model, $searchable, $term))->toBe(1, "el listado no encontró «{$term}»");
    }
});
