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
 * `store()`, `update()` y `destroy()` escriben DENTRO de una transacción.
 *
 * 🔴 EL BUG QUE ESTO CIERRA. Los tres métodos no abrían transacción ni tenían
 * `catch`. La única que sí era `storeMany()`, o sea que **el bulk era atómico
 * y el single no**: el mismo motor daba dos garantías distintas según cuántos
 * ítems mandara el front.
 *
 * Con un `afterCreate` que tire, la fila queda ESCRITA y el request devuelve
 * 500: un registro huérfano, sin el efecto secundario que lo justificaba. En
 * `destroy()` es peor todavía — la fila ya no está y el efecto que tenía que
 * acompañarla no ocurrió, y no hay forma de deshacerlo a mano porque el
 * registro que decía qué borrar es justamente el que se fue.
 *
 * ── 🔴 POR QUÉ SE MIDE LA BASE Y NO EL CÓDIGO ──────────────────────────────
 *
 * Un test que verifique que el método "usa DB::transaction" —leyendo el
 * fuente, o espiando la fachada— pasa en verde con la transacción puesta en el
 * lugar equivocado: envolviendo sólo el `create` y dejando el hook afuera. Lo
 * único que discrimina es CONTAR LAS FILAS después de que el hook explote.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class TxAdmin extends AuthUser
{
    protected $table = 'tx_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class TxWidget extends Model
{
    protected $table = 'tx_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

/**
 * El service cuyos hooks explotan. Simula un efecto secundario que falla.
 *
 * ⚠️ Implementa `MkModuleServiceInterface` entero porque `getService()` lo
 * exige por tipo de retorno. Los hooks que no interesan son pass-through.
 */
final class TxWidgetServiceQueTira implements MkModuleServiceInterface
{
    public static bool $tirarEnCreate = false;

    public static bool $tirarEnUpdate = false;

    public static bool $tirarEnDelete = false;

    public function beforeCreate(Request $request, array $input): array
    {
        return $input;
    }

    public function afterCreate(Request $request, Model $model, array $input): mixed
    {
        if (self::$tirarEnCreate) {
            throw new RuntimeException('el efecto secundario falló');
        }

        return null;
    }

    public function beforeUpdate(Request $request, string|int $id, array $input): array
    {
        return $input;
    }

    public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed
    {
        if (self::$tirarEnUpdate) {
            throw new RuntimeException('el efecto secundario falló');
        }

        return null;
    }

    public function beforeDelete(Request $request, Model $model, string|int $id): bool
    {
        return true;
    }

    public function afterDelete(Request $request, Model $model, string|int $id): mixed
    {
        if (self::$tirarEnDelete) {
            throw new RuntimeException('el efecto secundario falló');
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

final class TxWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => TxWidget::class,
        'service' => TxWidgetServiceQueTira::class,
        'features' => ['authorize_with_policy' => false],
    ];
}

/**
 * El request, además, bindeado en el container.
 *
 * ⚠️ Hace falta para el camino FELIZ y no para el que falla: `sendResponse()`
 * arma URLs, y el generador de URLs de Laravel se construye con el request del
 * container. Sin bindearlo, los tests de rollback pasan igual —tiran antes de
 * llegar ahí— y los del camino feliz mueren con un `TypeError` que no tiene
 * nada que ver con lo que se está midiendo.
 */
function pedido(string $metodo, array $datos = []): Request
{
    $request = Request::create('/x', $metodo, $datos);
    app()->instance('request', $request);

    return $request;
}

beforeEach(function () {
    TxWidgetServiceQueTira::$tirarEnCreate = false;
    TxWidgetServiceQueTira::$tirarEnUpdate = false;
    TxWidgetServiceQueTira::$tirarEnDelete = false;

    $this->bootHttpApp(TxAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false, 'authorize_with_policy' => false],
    ]);

    Schema::create('tx_widgets', function ($t) {
        $t->id();
        $t->string('name');
    });
});

afterEach(function () {
    $this->tearDownHttpApp();
});

test('🔴 si afterCreate tira, la fila NO queda escrita', function () {
    TxWidgetServiceQueTira::$tirarEnCreate = true;

    $controller = app(TxWidgetController::class);

    expect(fn () => $controller->store(pedido('POST', ['name' => 'huérfano'])))
        ->toThrow(RuntimeException::class);

    // Sin transacción esto da 1: la fila escrita, el 500 devuelto, y nadie
    // que la limpie.
    expect(TxWidget::count())->toBe(0);
});

test('sin fallo, store escribe normalmente — la transacción no cambia el camino feliz', function () {
    $controller = app(TxWidgetController::class);
    $controller->store(pedido('POST', ['name' => 'ok']));

    expect(TxWidget::count())->toBe(1);
    expect(TxWidget::first()->name)->toBe('ok');
});

test('🔴 si afterUpdate tira, el valor VIEJO se conserva', function () {
    $widget = TxWidget::create(['name' => 'original']);
    TxWidgetServiceQueTira::$tirarEnUpdate = true;

    $controller = app(TxWidgetController::class);

    expect(fn () => $controller->update(pedido('PUT', ['name' => 'nuevo']), $widget->id))
        ->toThrow(RuntimeException::class);

    // ⚠️ Éste es el caso que NO se ve: sin rollback la fila queda con el valor
    // nuevo y sin el efecto que lo justificaba, y es indistinguible de una
    // actualización que salió bien.
    expect(TxWidget::find($widget->id)->name)->toBe('original');
});

test('🔴 si afterDelete tira, la fila SIGUE ahí', function () {
    $widget = TxWidget::create(['name' => 'no me borres']);
    TxWidgetServiceQueTira::$tirarEnDelete = true;

    $controller = app(TxWidgetController::class);

    expect(fn () => $controller->destroy(pedido('DELETE'), $widget->id))
        ->toThrow(RuntimeException::class);

    // El más caro de los tres: sin rollback, el registro que decía QUÉ había
    // que limpiar es justamente el que desaparece.
    expect(TxWidget::count())->toBe(1);
});

test('sin fallo, destroy borra — el camino feliz sigue igual', function () {
    $widget = TxWidget::create(['name' => 'chau']);

    $controller = app(TxWidgetController::class);
    $controller->destroy(pedido('DELETE'), $widget->id);

    expect(TxWidget::count())->toBe(0);
});
