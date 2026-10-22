<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `destroy()` on a row another table still references answers 409, not 500.
 *
 * 🔴 Before, `$model->delete()` let the FK violation bubble up and the client
 * got a 500 with no hint of what to do. Only FK violations become a 409: any
 * other query error is re-thrown unchanged, and the row stays (the delete runs
 * in a transaction).
 *
 * The real path runs against SQLite with FKs ON. The PostgreSQL and MySQL
 * codes are fed through a service hook that throws a `QueryException` with
 * that driver's `errorInfo`, inside the same transaction.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class FkAdmin extends AuthUser
{
    protected $table = 'fk_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class FkCategory extends Model
{
    protected $table = 'fk_categories';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class FkCategoryService implements MkModuleServiceInterface
{
    /** @var array{0: string, 1: int, 2: string}|null */
    public static ?array $throwErrorInfo = null;

    public function beforeCreate(Request $request, array $input): array
    {
        return $input;
    }

    public function afterCreate(Request $request, Model $model, array $input): mixed
    {
        return null;
    }

    public function beforeUpdate(Request $request, string|int $id, array $input): array
    {
        return $input;
    }

    public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed
    {
        return null;
    }

    public function beforeDelete(Request $request, Model $model, string|int $id): bool
    {
        return true;
    }

    public function afterDelete(Request $request, Model $model, string|int $id): mixed
    {
        if (self::$throwErrorInfo !== null) {
            $pdo = new PDOException(self::$throwErrorInfo[2]);
            $pdo->errorInfo = self::$throwErrorInfo;

            throw new QueryException('testing', 'delete from "fk_categories"', [], $pdo);
        }

        return null;
    }

    public function beforeList(Request $request, $query)
    {
        return $query;
    }

    public function afterList(Request $request, $data, int $total)
    {
        return $data;
    }

    public function setExtraData(Request $request, $data): array
    {
        return [];
    }

    public function beforeSearch(Request $request, $query)
    {
        return $query;
    }

    public function beforeShow(Request $request, Model $model): Model
    {
        return $model;
    }
}

final class FkCategoryController extends SmartController
{
    protected array $mkConfig = [
        'model' => FkCategory::class,
        'service' => FkCategoryService::class,
        'features' => ['authorize_with_policy' => false],
    ];
}

function fkDeleteRequest(): Request
{
    $request = Request::create('/x', 'DELETE');
    app()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    FkCategoryService::$throwErrorInfo = null;

    $this->bootHttpApp(FkAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
    ]);

    Schema::create('fk_categories', function ($t) {
        $t->id();
        $t->string('name');
    });
    Schema::create('fk_products', function ($t) {
        $t->id();
        $t->foreignId('category_id')->constrained('fk_categories');
    });
    Schema::enableForeignKeyConstraints();
});

afterEach(function () {
    Schema::disableForeignKeyConstraints();
    $this->tearDownHttpApp();
});

test('🔴 deleting a row that is still referenced answers 409 and keeps the row', function () {
    $category = FkCategory::create(['name' => 'in use']);
    DB::table('fk_products')->insert(['category_id' => $category->id]);

    $response = app(FkCategoryController::class)->destroy(fkDeleteRequest(), $category->id);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(409)
        ->and($body['success'])->toBeFalse()
        ->and($body['message'])->toContain('registros vinculados')
        ->and($body['__extraData']['code'])->toBe('ERR_CONFLICT')
        ->and(FkCategory::count())->toBe(1)
        ->and(DB::table('fk_products')->count())->toBe(1);
});

test('a row nobody references is still deleted', function () {
    $category = FkCategory::create(['name' => 'free']);

    $response = app(FkCategoryController::class)->destroy(fkDeleteRequest(), $category->id);

    expect($response->getStatusCode())->toBe(200)
        ->and(FkCategory::count())->toBe(0);
});

test('the FK codes of PostgreSQL and MySQL/MariaDB also answer 409', function (array $errorInfo) {
    $category = FkCategory::create(['name' => 'in use']);
    FkCategoryService::$throwErrorInfo = $errorInfo;

    $response = app(FkCategoryController::class)->destroy(fkDeleteRequest(), $category->id);

    expect($response->getStatusCode())->toBe(409)
        ->and(FkCategory::count())->toBe(1);
})->with([
    'postgres' => [['23503', 7, 'update or delete on table violates foreign key constraint']],
    'mysql 1451' => [['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails']],
    'mysql 1217' => [['23000', 1217, 'Cannot delete or update a parent row: a foreign key constraint fails']],
]);

test('any other query error is re-thrown unchanged, and the row stays', function (array $errorInfo) {
    $category = FkCategory::create(['name' => 'x']);
    FkCategoryService::$throwErrorInfo = $errorInfo;

    try {
        app(FkCategoryController::class)->destroy(fkDeleteRequest(), $category->id);
        $this->fail('expected the QueryException to be re-thrown');
    } catch (QueryException $e) {
        expect($e->errorInfo)->toBe($errorInfo);
    }

    expect(FkCategory::count())->toBe(1);
})->with([
    // Same SQLSTATE as an FK violation on MySQL, but a duplicate key.
    'mysql duplicate key' => [['23000', 1062, 'Duplicate entry']],
    'postgres not null' => [['23502', 7, 'null value in column violates not-null constraint']],
    'sqlite other constraint' => [['23000', 19, 'UNIQUE constraint failed: fk_categories.name']],
]);
