<?php

declare(strict_types=1);

use App\Modules\SpecShop\Http\Controllers\ProductController;
use App\Modules\SpecShop\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Spec\Introspector;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `mk:spec:introspect` reads the RUNNING app: a booted app with a real (sqlite) database, a
 * fixture module under `tests/Fixtures/Spec/Modules/SpecShop` and real routes. What the
 * introspector reports is what `mk spec check` compares the spec against, so every fact has
 * to come from the runtime (getFillable, Schema, reflection), never from a parse of the source.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

beforeEach(function () {
    $modules = __DIR__.'/../Fixtures/Spec/Modules';
    $this->bootHttpApp(Product::class, ['tenant' => ['enabled' => false], 'paths' => ['modules' => $modules]]);

    Schema::create('spec_products', function ($t) {
        $t->string('id', 36)->primary();
        $t->string('name', 80);
        $t->decimal('price', 8, 2)->nullable();
        $t->unsignedTinyInteger('status')->default(1);
        $t->timestamps();
    });
    // "One active product": partial, so NOT a unique column.
    DB::statement('create unique index spec_products_one_active on spec_products (status) where status = 1');

    Route::middleware(['api', 'mk.auth:admin', 'mk.ability:admin.spec_shop.update'])
        ->put('api/admin/products/{product}', [ProductController::class, 'update']);

    $this->intro = (new Introspector([$modules]))->collect();
});

afterEach(function () {
    $this->tearDownHttpApp();
});

test('fillable_comes_from_getFillable', function () {
    $model = collect($this->intro['models'])->firstWhere('class', Product::class);

    expect($model['module'])->toBe('SpecShop')
        ->and($model['table'])->toBe('spec_products')
        ->and($model['fillable'])->toBe(['name', 'price'])
        ->and($model['guarded'])->toBe(['*'])
        ->and((array) $model['attributes'])->toBe(['status' => 1])
        ->and((array) $model['casts'])->toMatchArray(['status' => 'App\\Modules\\SpecShop\\Enums\\ProductStatus'])
        ->and((array) $model['relations'])->toBe(['children' => ['type' => 'HasMany', 'related' => Product::class, 'declaredIn' => Product::class, 'foreignKey' => 'parent_id']]);
});

test('enum_cases_and_labels', function () {
    $enum = collect($this->intro['enums'])->firstWhere('class', 'App\\Modules\\SpecShop\\Enums\\ProductStatus');

    expect($enum['cases'])->toBe([
        ['name' => 'Active', 'value' => 1, 'label' => 'Activo'],
        ['name' => 'Inactive', 'value' => 2, 'label' => 'Inactivo'],
    ])
        ->and($enum['default'])->toBe('Active')
        ->and($enum['doc'])->toBe("Whether a product is sold.\n\nInactive is not deleted.");
});

test('abilities_from_discoverAbilities', function () {
    expect($this->intro['abilities'])->toBe([
        ['module' => 'SpecShop', 'source' => 'provider', 'name' => 'admin.spec_shop.update', 'description' => 'Editar productos.', 'baseline' => false],
        ['module' => 'SpecShop', 'source' => 'provider', 'name' => 'member.spec_shop.viewAny', 'description' => 'Ver productos.', 'baseline' => true],
    ]);
});

test('route_abilities_parsed_from_middleware', function () {
    $route = collect($this->intro['routes'])->firstWhere('uri', 'api/admin/products/{product}');

    expect($route)->toBe([
        'uri' => 'api/admin/products/{product}',
        'methods' => ['PUT'],
        'action' => ProductController::class.'@update',
        'abilities' => ['admin.spec_shop.update'],
        'scopes' => ['admin'],
    ]);
});

test('smart_controller_mkconfig_by_reflection', function () {
    // The hand controller declares nothing: the config is inherited from the generated base.
    expect($this->intro['crud'])->toBe([[
        'controller' => ProductController::class,
        'module' => 'SpecShop',
        'mkConfig' => [
            'model' => Product::class,
            'searchable' => ['name'],
            'features' => ['allowed_filters' => ['status'], 'pagination_type' => 'length_aware'],
        ],
    ]]);
});

test('table_columns_from_schema', function () {
    $table = collect($this->intro['tables'])->firstWhere('name', 'spec_products');
    $columns = collect($table['columns'])->keyBy('name');

    expect($columns->keys()->all())->toBe(['id', 'name', 'price', 'status', 'created_at', 'updated_at'])
        ->and($columns['name'])->toMatchArray(['type' => 'varchar', 'nullable' => false, 'default' => null])
        ->and($columns['price'])->toMatchArray(['type' => 'numeric', 'nullable' => true])
        ->and($columns['status']['default'])->toBe("'1'")
        ->and(collect($table['indexes'])->keyBy('name')->map(fn ($i) => [$i['columns'], $i['unique'], $i['partial']])->all())->toBe([
            'spec_products_one_active' => [['status'], true, true],
            'sqlite_autoindex_spec_products_1' => [['id'], true, false],
        ]);
});
