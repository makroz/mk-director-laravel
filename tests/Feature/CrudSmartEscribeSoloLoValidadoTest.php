<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 33: LAS `rules()` DE UN FORMREQUEST NO LIMITAN LO QUE SE ESCRIBE.
 *
 * `CRUDSmart::store()`/`update()` hacen `$request->all()` y filtran contra
 * `$fillable`. `validateResolved()` verifica lo que llegó pero **no lo recorta**, así
 * que cualquier campo `fillable` ausente de `rules()` viaja hasta el `update()`.
 *
 * 🔴 **Convierte «no lo puse en las reglas» en una defensa IMAGINARIA**, que es la peor
 * clase: se lee en el diff como si defendiera. El autor de un `UpdateRequest` cree que
 * declara qué se puede editar, y en realidad sólo declara qué se valida. Lo que se puede
 * editar es `$fillable`, que vive en otro archivo y se escribió pensando en el ALTA.
 *
 * En el piloto el campo expuesto era el peor posible: `branch_id`, el eje de
 * aislamiento. Un área que cambia de sucursal deja sus mesas —y los pedidos históricos
 * de esas mesas— apuntando a otro edificio; y como el scope filtra por esa columna, la
 * fila **desaparece** de la pantalla de quien la acaba de editar. El síntoma no es «se
 * movió», es «se borró».
 *
 * Es el mismo hueco que el hallazgo 14 por el otro lado: no se puede escribir lo que no
 * es fillable, **y se puede escribir todo lo que sí lo es**.
 *
 * ── 🔴 POR QUÉ ESTE TEST VA POR HTTP, Y LA PRIMERA VERSIÓN NO SERVÍA ────────
 *
 * La primera versión instanciaba el controller a mano y le llamaba `update()`. Pasó en
 * VERDE con el flag prendido **y el flag todavía sin implementar**, y se descubrió sólo
 * porque el control del default también daba rojo: en ese harness el FormRequest llegaba
 * **VACÍO**, así que no se escribía nada y el campo se quedaba con el valor viejo por el
 * motivo equivocado.
 *
 * La causa: `resolveFormRequest()` hace `app($formRequestClass)`, y lo que le copia la
 * entrada de la request real es el callback `resolving` que registra
 * `FoundationServiceProvider`. Sin ese provider, el FormRequest se construye sin input.
 * De ahí la app booteada, el provider registrado explícitamente y la ruta de verdad.
 *
 * ⚠️ Un valor de prueba que el bug puede producir por casualidad no mide nada, y acá el
 * bug producía justo el valor esperado.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

class AreaDelSalon extends Model
{
    protected $table = 'areas_del_salon';

    // `branch_id` TIENE que ser fillable: en el ALTA hay que poder decir de qué
    // sucursal es el área. El problema es la EDICIÓN.
    protected $fillable = ['name', 'branch_id'];

    public $timestamps = false;
}

/** Declara `name` y NO `branch_id`: un área no se muda de local. */
class ActualizarAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:50']];
    }
}

class AreaConFlagPrendido extends SmartController
{
    protected array $mkConfig = [
        'model' => AreaDelSalon::class,
        'searchable' => ['name'],
        'update_request' => ActualizarAreaRequest::class,
        'features' => ['write_only_validated' => true, 'auto_cache' => false],
    ];
}

class AreaConFlagApagado extends SmartController
{
    protected array $mkConfig = [
        'model' => AreaDelSalon::class,
        'searchable' => ['name'],
        'update_request' => ActualizarAreaRequest::class,
        'features' => ['write_only_validated' => false, 'auto_cache' => false],
    ];
}

class AreaSinOpinion extends SmartController
{
    protected array $mkConfig = [
        'model' => AreaDelSalon::class,
        'searchable' => ['name'],
        'update_request' => ActualizarAreaRequest::class,
        'features' => ['auto_cache' => false],
    ];
}

/** Con el flag prendido pero SIN FormRequest configurado. */
class AreaSinFormRequest extends SmartController
{
    protected array $mkConfig = [
        'model' => AreaDelSalon::class,
        'searchable' => ['name'],
        'features' => ['write_only_validated' => true, 'auto_cache' => false],
    ];
}

beforeEach(function () {
    $this->bootHttpApp(AreaDelSalon::class, ['tenant' => ['enabled' => false]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('areas_del_salon', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->string('branch_id')->nullable();
    });

    Route::middleware(['api'])->group(function () {
        Route::patch('api/areas-flag-on/{id}', [AreaConFlagPrendido::class, 'update']);
        Route::patch('api/areas-flag-off/{id}', [AreaConFlagApagado::class, 'update']);
        Route::patch('api/areas-default/{id}', [AreaSinOpinion::class, 'update']);
        Route::patch('api/areas-sin-request/{id}', [AreaSinFormRequest::class, 'update']);
    });

    AreaDelSalon::query()->create(['name' => 'Terraza', 'branch_id' => 'sucursal-propia']);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/** Manda el PATCH que intenta mudar el área y devuelve la fila que quedó. */
function areaTrasElPatch(object $test, string $ruta, ?array $body = null): AreaDelSalon
{
    $respuesta = $test->httpSendPatch($ruta, $body ?? ['name' => 'Terraza', 'branch_id' => 'sucursal-ajena']);

    expect($respuesta->getStatusCode())->toBe(200, (string) $respuesta->getContent());

    return AreaDelSalon::query()->find(1);
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: con `write_only_validated`, el campo fuera de `rules()` NO se escribe', function () {
    $fila = areaTrasElPatch($this, '/api/areas-flag-on/1');

    expect($fila->branch_id)->toBe('sucursal-propia');
});

/*
|--------------------------------------------------------------------------
| ⚠️ EL DEFAULT SE MIDE, Y ES LO QUE IMPIDE UN BC BREAK SILENCIOSO.
|
| Un consumidor que hoy dependa de escribir un campo no declarado dejaría de
| escribirlo SIN ERROR: la fila se guarda con el valor viejo. Un test que sólo
| compruebe el flag prendido deja pasar el día en que el default cambie sin que
| nadie lo decida.
|
| 🔴 Y ADEMÁS ES EL CONTROL QUE HACE QUE EL DE ARRIBA SIGNIFIQUE ALGO: si no se
| escribiera nada nunca, los dos darían `sucursal-propia`.
|--------------------------------------------------------------------------
*/

test('⚠️ CONTROL: el default sigue escribiendo el campo no declarado (BC)', function () {
    expect(areaTrasElPatch($this, '/api/areas-default/1')->branch_id)->toBe('sucursal-ajena');
});

test('CONTROL: apagarlo explícitamente también escribe', function () {
    expect(areaTrasElPatch($this, '/api/areas-flag-off/1')->branch_id)->toBe('sucursal-ajena');
});

test('CONTROL: con el flag prendido, lo que SÍ está en `rules()` se escribe', function () {
    $fila = areaTrasElPatch($this, '/api/areas-flag-on/1', ['name' => 'Patio']);

    expect($fila->name)->toBe('Patio');
});

/**
 * 🔴 SIN FORMREQUEST CONFIGURADO EL FLAG NO PUEDE HACER NADA. `validated()` sobre una
 * `Request` común no existe: si el flag se aplicara igual, un controller sin
 * `update_request` dejaría de escribir TODO — y eso no sería una defensa, sería el CRUD
 * roto en silencio.
 */
test('CONTROL: sin FormRequest configurado, el flag prendido no rompe la escritura', function () {
    $fila = areaTrasElPatch($this, '/api/areas-sin-request/1');

    expect($fila->name)->toBe('Terraza')
        ->and($fila->branch_id)->toBe('sucursal-ajena');
});
