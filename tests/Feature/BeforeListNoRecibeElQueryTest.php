<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Hallazgo 64 — `beforeList` se llamaba «modificar query» y recibe un MODELO.
 *
 * `CRUDSmart::index()` le pasa `new $modelClass` y le entrega lo que devuelva a
 * `ListManager::apply(Model $model)`. Un consumidor que le cree al nombre y
 * filtra ahí (`return $query->where(...)`) devuelve un Builder, y el listado
 * revienta con un `TypeError` adentro de `ListManager` que no nombra el gancho
 * ni dice dónde se filtra. Medido en NetPizza: escondiendo una identidad de la
 * lista de administradores.
 *
 * El filtro va en `beforeSearch`, que sí recibe el builder. Este test fija que
 * el error lo diga.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class BlAdmin extends AuthUser
{
    protected $table = 'bl_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class BlWidget extends Model
{
    protected $table = 'bl_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class BlWidgetServiceQueFiltraEnBeforeList implements MkModuleServiceInterface
{
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
        return null;
    }

    public function beforeList(Request $request, $query)
    {
        // Lo que hace quien le cree al nombre del gancho.
        return $query->where('name', '!=', 'escondido');
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

final class BlWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => BlWidget::class,
        'service' => BlWidgetServiceQueFiltraEnBeforeList::class,
        'features' => ['authorize_with_policy' => false],
    ];
}

beforeEach(function () {
    $this->bootHttpApp(BlAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
    ]);

    Schema::create('bl_widgets', function ($t) {
        $t->id();
        $t->string('name');
    });
});

afterEach(function () {
    $this->tearDownHttpApp();
});

test('🔴 un beforeList que devuelve un Builder explota nombrando el gancho y la salida', function () {
    $request = Request::create('/x', 'GET');
    app()->instance('request', $request);

    expect(fn () => app(BlWidgetController::class)->index($request))
        ->toThrow(LogicException::class, 'beforeSearch');
});
