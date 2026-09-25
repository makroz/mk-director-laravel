<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\MkLaravelTestCase;
use Mk\Director\Traits\CRUDSmart;

/**
 * HALLAZGO 14: UN CAMPO QUE EL SERVICE ESCRIBE Y NO ESTÁ EN `$fillable` SE
 * DESCARTA EN SILENCIO.
 *
 * `CRUDSmart` corre `beforeCreate`/`beforeUpdate` y **después** filtra `$input`
 * contra el `$fillable` del modelo. El orden no estaba documentado y es el que
 * rompe el patrón más natural del paquete.
 *
 * El caso concreto del piloto: `announcements.tenant_id` y `author_id` no tenían
 * que poder llegar del body —escritura cruzada entre restaurantes en el primero,
 * suplantación de autor en el segundo—. La defensa obvia, sacarlos del `$fillable`
 * y escribirlos desde el Service, que es donde vive el usuario autenticado, **no
 * funciona**: el hook los pone y el filtro los saca.
 *
 * Y los dos síntomas fueron muy distintos:
 *
 * | campo                  | síntoma                          | qué se ve |
 * |------------------------|----------------------------------|-----------|
 * | `tenant_id` (NOT NULL) | `SQLSTATE[23502]`                | ruidoso   |
 * | `author_id` (nullable) | la fila se crea con autor NULL   | **nada**  |
 *
 * El segundo es el caro: un comunicado sin autor se lee como «dato viejo», no como
 * «el pipeline tiró lo que el Service escribió». Y la defensa que uno creía haber
 * puesto —«el autor sale del token»— no está puesta, mientras el código dice que sí.
 *
 * ── 🔴 POR QUÉ EL AVISO SÓLO MIRA LAS CLAVES QUE EL HOOK AGREGÓ ─────────────
 *
 * Las claves que manda el CLIENTE y no son `fillable` las descarta el mismo filtro,
 * y ahí el silencio es CORRECTO: es la lista blanca haciendo su trabajo (hallazgo
 * 33). Avisar de ésas llenaría el log en cada request y enterraría el caso que
 * importa. Lo que no puede pasar callado es una clave que NO venía en el request:
 * ésa sólo pudo ponerla un hook o un plugin, o sea el consumidor, a propósito.
 */
uses(MkLaravelTestCase::class, UsesDatabase::class);

/** Escribe `author_id` —el caso nullable, el que no se ve— y pisa `name`. */
class HookQueEscribeFueraDelFillableService implements MkModuleServiceInterface
{
    public function beforeCreate(Request $request, array $input): array
    {
        $input['author_id'] = 'del-token';
        $input['name'] = 'pisado-por-el-service';

        return $input;
    }

    public function beforeUpdate(Request $request, string|int $id, array $input): array
    {
        $input['author_id'] = 'del-token';

        return $input;
    }

    public function afterCreate(Request $request, Model $model, array $input): mixed
    {
        return null;
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

class HookFillableController
{
    use CRUDSmart;

    public function __construct(array $mkConfig = [])
    {
        $this->mkConfig = $mkConfig;
    }

    /** @param  array<int, string>  $fillable */
    public function avisar(array $antes, array $despues, array $fillable, string $operacion): void
    {
        $this->warnAboutHookKeysDiscardedByFillable($antes, $despues, $fillable, $operacion);
    }
}

function avisosDeUnaCorrida(callable $fn): array
{
    $avisos = [];

    Log::swap(new class($avisos) extends Logger
    {
        public function __construct(public array &$recibidos)
        {
            // Sin logger real: sólo interesa qué se le pidió registrar.
        }

        public function warning($message, array $context = []): void
        {
            $this->recibidos[] = ['mensaje' => (string) $message, 'contexto' => $context];
        }

        public function __call($method, $parameters)
        {
            return null;
        }
    });

    $fn();

    /** @var array<int, array{mensaje: string, contexto: array<string, mixed>}> $avisos */
    return $avisos;
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: una clave que el hook AGREGÓ y el filtro descarta queda registrada', function () {
    $controller = new HookFillableController;

    $avisos = avisosDeUnaCorrida(fn () => $controller->avisar(
        antes: ['name' => 'del-body'],
        despues: ['name' => 'pisado-por-el-service', 'author_id' => 'del-token'],
        fillable: ['name'],
        operacion: 'create',
    ));

    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['contexto']['keys'] ?? null)->toBe(['author_id']);
});

test('el aviso nombra la operación y el controller, que es lo que hace falta para ubicarlo', function () {
    $controller = new HookFillableController;

    $avisos = avisosDeUnaCorrida(fn () => $controller->avisar(
        antes: [],
        despues: ['author_id' => 'del-token'],
        fillable: ['name'],
        operacion: 'update',
    ));

    expect($avisos[0]['contexto']['operation'] ?? null)->toBe('update')
        ->and($avisos[0]['contexto']['controller'] ?? null)->toBe(HookFillableController::class);
});

/*
|--------------------------------------------------------------------------
| LOS CONTROLES. Sin ellos, «avisar de todo lo que el filtro descarta»
| también pone el rojo de arriba en verde — y llena el log en cada request
| con la lista blanca haciendo su trabajo, que es justo donde el caso que
| importa se vuelve invisible.
|--------------------------------------------------------------------------
*/

test('CONTROL: una clave que mandó el CLIENTE y no es fillable NO avisa', function () {
    $controller = new HookFillableController;

    $avisos = avisosDeUnaCorrida(fn () => $controller->avisar(
        antes: ['name' => 'del-body', 'is_admin' => true],
        despues: ['name' => 'del-body', 'is_admin' => true],
        fillable: ['name'],
        operacion: 'create',
    ));

    expect($avisos)->toBe([]);
});

test('CONTROL: un hook que escribe DENTRO del fillable no avisa', function () {
    $controller = new HookFillableController;

    $avisos = avisosDeUnaCorrida(fn () => $controller->avisar(
        antes: ['name' => 'del-body'],
        despues: ['name' => 'del-body', 'slug' => 'calculado'],
        fillable: ['name', 'slug'],
        operacion: 'create',
    ));

    expect($avisos)->toBe([]);
});

test('CONTROL: sin hooks y sin nada descartado tampoco', function () {
    $controller = new HookFillableController;

    $avisos = avisosDeUnaCorrida(fn () => $controller->avisar(
        antes: ['name' => 'del-body'],
        despues: ['name' => 'del-body'],
        fillable: ['name'],
        operacion: 'create',
    ));

    expect($avisos)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| 🔴 Y EL AVISO TIENE QUE ESTAR CABLEADO AL PIPELINE, NO SÓLO EXISTIR.
|
| Los cuatro casos de arriba miden la DISCRIMINACIÓN, que es donde está la
| sutileza. Pasan en verde con el método escrito y nunca llamado — que es la
| forma exacta en la que este paquete ya se equivocó dos veces (la Policy que
| `CRUDSmart` no invocaba, el `AbilityResolver` que nadie bindeaba). Este caso
| corre `store()` de verdad contra sqlite.
|--------------------------------------------------------------------------
*/

class HookFillableWidget extends Model
{
    protected $table = 'hook_fillable_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

class HookFillableStoreController extends SmartController
{
    public function __construct()
    {
        $this->mkConfig = [
            'model' => HookFillableWidget::class,
            'service' => HookQueEscribeFueraDelFillableService::class,
            'searchable' => ['name'],
        ];
    }
}

test('🔴 el aviso sale del pipeline REAL: `store()` lo dispara', function () {
    $this->setUpDatabase();

    Schema::create('hook_fillable_widgets', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('author_id')->nullable();
    });

    // `sendResponse()` resuelve `response()`, que necesita la ResponseFactory: el
    // harness del paquete no la trae, y sin esto el test muere DESPUÉS de haber
    // escrito la fila, con un `BindingResolutionException` que no tiene nada que ver
    // con lo que se está midiendo.
    app()->singleton(
        \Illuminate\Contracts\Routing\ResponseFactory::class,
        fn ($app) => new ResponseFactory(
            new Factory(
                new EngineResolver,
                new FileViewFinder($app['files'], []),
                new Dispatcher($app),
            ),
            new Redirector(new UrlGenerator(
                new RouteCollection,
                Request::create('/'),
            )),
        ),
    );

    $controller = new HookFillableStoreController;
    $request = Request::create('/', 'POST', ['name' => 'del-body']);

    $avisos = avisosDeUnaCorrida(fn () => $controller->store($request));

    // El aviso salió...
    expect($avisos)->toHaveCount(1)
        ->and($avisos[0]['contexto']['keys'] ?? null)->toBe(['author_id']);

    // ...y la fila quedó con el autor en NULL, que es el síntoma del hallazgo:
    // el filtro descarta lo que el Service escribió y nada lo dice.
    expect(HookFillableWidget::query()->first()->author_id)->toBeNull();

    // Contraprueba de que el hook SÍ corrió: pisó `name`, que es fillable.
    expect(HookFillableWidget::query()->first()->name)->toBe('pisado-por-el-service');
});
