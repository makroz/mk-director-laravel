<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Export\AsyncExportManager;
use Mk\Director\Export\Contracts\ExportConfigInterface;
use Mk\Director\Export\ExportConfigRegistry;
use Mk\Director\Export\FilasDelExport;
use Mk\Director\Export\Support\ColumnDefinition;
use Mk\Director\Export\Support\ExportConfigDefaults;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El listado y el export no hacen una query POR FILA.
 *
 * Cada caso corre con 3 y con 10 filas y afirma la MISMA cantidad de queries:
 * un N+1 se ve como una cuenta que crece con las filas.
 *
 * 🔴 Dos agujeros, cada uno con su forma:
 *
 *  - **El index de un `AuthUser`** hacía `$item->loadMissing(['roles',
 *    'directAbilities'])` dentro de un `foreach`: 2 queries por fila. Es carga
 *    EXPLÍCITA, así que el guard de lazy loading (`tests/Pest.php`) no la ve —
 *    sólo la atrapa la cuenta de queries.
 *
 *  - **El export con una columna con punto** (`category.name`) cuya relación
 *    no está en el `with` del controller: `RowValue` leía `$fila->category`
 *    y Eloquent la traía de a una, fila por fila. Ésa sí la ve el guard: con
 *    2+ modelos tira `LazyLoadingViolationException`.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class NpAdmin extends AuthUser
{
    protected $table = 'np_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }

    /** Las pivots del paquete usan `user_id` (ver MakeAuthUserCommand). */
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

final class NpAdminController extends SmartController
{
    // Sin `with`: el index tiene que cargar roles y abilities igual, en lote.
    protected array $mkConfig = [
        'model' => NpAdmin::class,
        'features' => ['authorize_with_policy' => false],
    ];
}

final class NpCategory extends Model
{
    protected $table = 'np_categories';

    protected $guarded = [];

    public $timestamps = false;
}

final class NpExpense extends Model
{
    protected $table = 'np_expenses';

    protected $guarded = [];

    public $timestamps = false;

    public function category(): BelongsTo
    {
        return $this->belongsTo(NpCategory::class, 'np_category_id');
    }
}

final class NpExpenseController extends SmartController
{
    // Sin `with` a propósito: `category` la pide sólo la columna del export.
    protected array $mkConfig = [
        'model' => NpExpense::class,
        'features' => ['authorize_with_policy' => false],
    ];
}

final class NpExpenseExportConfig implements ExportConfigInterface
{
    use ExportConfigDefaults;

    public function module(): string
    {
        return 'np_expenses';
    }

    public function title(): string
    {
        return 'Gastos';
    }

    public function columns(): array
    {
        return [
            ColumnDefinition::make('name')->label('Gasto'),
            ColumnDefinition::make('category.name')->label('Categoría'),
        ];
    }
}

beforeEach(function () {
    $this->bootHttpApp(NpAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
        'list' => ['default_per_page' => 15, 'max_per_page' => 100],
    ]);

    // La caché de "¿la pivot tiene `user_type`?" es por nombre de tabla y por
    // proceso: sin vaciarla, este archivo hereda la respuesta del test que
    // creó su `role_user` antes, con otro esquema.
    MorphPivot::flushSchemaCache();

    Schema::create('np_admins', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->timestamps();
    });
    Schema::create('roles', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('guard')->nullable();
        $t->timestamps();
    });
    Schema::create('abilities', function ($t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->unsignedBigInteger($fk);
            $t->unsignedBigInteger('user_id');
            $t->timestamps();
        });
    }

    Schema::create('np_categories', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('np_expenses', function ($t) {
        $t->id();
        $t->string('name');
        $t->unsignedBigInteger('np_category_id');
    });

    $registry = app(ExportConfigRegistry::class);
    $registry->register(new NpExpenseExportConfig);
    app()->instance(ExportConfigRegistry::class, $registry);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/** @return int Cantidad de queries que emite el callback. */
function queriesDe(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $fn();

    $cantidad = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $cantidad;
}

/** Siembra $n admins, cada uno con un rol y una ability directa propios. */
function sembrarAdmins(int $n): void
{
    $desde = DB::table('np_admins')->count() + 1;

    foreach (range($desde, $desde + $n - 1) as $i) {
        $id = DB::table('np_admins')->insertGetId([
            'name' => "admin{$i}", 'email' => "a{$i}@x.test", 'password' => 'x',
        ]);
        $rol = DB::table('roles')->insertGetId(['name' => "rol{$i}"]);
        $ability = DB::table('abilities')->insertGetId(['name' => "ability{$i}"]);
        DB::table('role_user')->insert(['role_id' => $rol, 'user_id' => $id]);
        DB::table('ability_user')->insert(['ability_id' => $ability, 'user_id' => $id]);
    }
}

/** Siembra $n gastos, cada uno con su propia categoría. */
function sembrarGastos(int $n): void
{
    $desde = DB::table('np_expenses')->count() + 1;

    foreach (range($desde, $desde + $n - 1) as $i) {
        $categoria = DB::table('np_categories')->insertGetId(['name' => "cat{$i}"]);
        DB::table('np_expenses')->insert(['name' => "gasto{$i}", 'np_category_id' => $categoria]);
    }
}

/** @return array{0:int,1:array} queries del index y las filas que devolvió. */
function indexDeAdmins(): array
{
    $request = Request::create('/x', 'GET');
    app()->instance('request', $request);
    $respuesta = null;

    $queries = queriesDe(function () use ($request, &$respuesta) {
        $respuesta = app(NpAdminController::class)->index($request);
    });

    return [$queries, $respuesta->getData(true)['data']];
}

/**
 * Lo que hace el job (re-ejecutar el index con la marca de re-entrada) y lo
 * que hace el motor después (leer cada columna de cada fila).
 *
 * @return array{0:int,1:array} queries de las dos cosas juntas y las celdas.
 */
function exportDeGastos(?ExportConfigInterface $config = null): array
{
    $config ??= new NpExpenseExportConfig;
    $buzon = app(FilasDelExport::class);
    $buzon->vaciar();

    $request = Request::create('/x', 'GET', [
        '_export' => 'csv',
        AsyncExportManager::MARCA_DE_REENTRADA => 1,
    ]);
    app()->instance('request', $request);
    $celdas = [];

    $queries = queriesDe(function () use ($request, $buzon, $config, &$celdas) {
        app(NpExpenseController::class)->index($request);

        foreach ($buzon->filas() as $fila) {
            $celdas[] = array_map(
                fn (ColumnDefinition $col) => $col->extractValue($fila),
                $config->columns(),
            );
        }
    });

    return [$queries, $celdas];
}

test('🔴 el index de un AuthUser carga roles y abilities en lote, no por fila', function () {
    sembrarAdmins(3);
    // ⚠️ La primera llamada paga una vez por proceso la introspección del
    // esquema de las pivots (`user_type`, que `MkBelongsToMany` cachea): 4
    // queries que no dependen de las filas. Se descarta para comparar igual
    // contra igual.
    indexDeAdmins();
    [$con3, $filas3] = indexDeAdmins();

    sembrarAdmins(7);
    [$con10, $filas10] = indexDeAdmins();

    expect($filas3)->toHaveCount(3)
        ->and($filas10)->toHaveCount(10)
        ->and($con10)->toBe($con3);

    // Y las relaciones llegan cargadas: el lote no puede ser "no cargar".
    foreach ($filas10 as $fila) {
        expect($fila['roles'])->toHaveCount(1)
            ->and($fila['direct_abilities'])->toHaveCount(1);
    }
});

test('🔴 el export lee una columna con punto sin una query por fila', function () {
    sembrarGastos(3);
    [$con3, $celdas3] = exportDeGastos();

    sembrarGastos(7);
    [$con10, $celdas10] = exportDeGastos();

    expect($celdas3)->toHaveCount(3)
        ->and($celdas10)->toHaveCount(10)
        ->and($con10)->toBe($con3)
        ->and($celdas10)->toContain(['gasto1', 'cat1'], ['gasto10', 'cat10']);
});

test('una columna con punto que NO es relación no rompe el export', function () {
    // `rutaDeRelaciones()` corta en el primer tramo que no es relación: un
    // `meta.color` sobre un atributo no puede terminar en un `loadMissing`
    // de una relación inexistente.
    $config = new class implements ExportConfigInterface
    {
        use ExportConfigDefaults;

        public function module(): string
        {
            return 'np_expenses';
        }

        public function title(): string
        {
            return 'Gastos';
        }

        public function columns(): array
        {
            return [ColumnDefinition::make('name.length')];
        }
    };
    app(ExportConfigRegistry::class)->register($config);

    sembrarGastos(2);
    [, $celdas] = exportDeGastos($config);

    expect($celdas)->toBe([[null], [null]]);
});
