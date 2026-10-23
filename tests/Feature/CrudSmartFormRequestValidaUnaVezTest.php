<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 93: EL PIN DE `{resource}` LLEGABA TARDE Y EL FORMREQUEST SE VALIDABA DOS VECES.
 *
 * `resolveFormRequest()` hacía `app($formRequestClass)`. Laravel valida un FormRequest
 * AL RESOLVERLO: `FormRequestServiceProvider` registra
 * `afterResolving(ValidatesWhenResolved::class, fn ($r) => $r->validateResolved())`.
 * Así que `prepareForValidation()`, `authorize()` y `rules()` corrían DENTRO del `app()`,
 * contra la ruta REAL, antes de que existiera el pin de `resource_route_param`. Si
 * fallaban, el 422 salía sin pin; si pasaban, todo corría otra vez con el pin.
 *
 * El caso que muerde: la ruta real es `{id}` y el UpdateRequest lee
 * `route('widget')` (el nombre pineado). En la primera pasada eso es `null`, el
 * `unique` no ignora la fila actual, y guardar sin cambiar el nombre da 422.
 *
 * ── POR QUÉ HTTP Y `FoundationServiceProvider` ──────────────────────────────
 *
 * El doble `validateResolved()` sólo existe si el `afterResolving` está registrado,
 * y lo registra ese provider. Sin él el test no reproduce lo que pasa en una app real.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class UnaVezWidget extends Model
{
    protected $table = 'una_vez_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

/** Bitácora compartida: qué corrió y en qué orden. */
final class UnaVezLog
{
    /** @var list<string> */
    public static array $pasos = [];

    public static bool $autoriza = true;

    public static function reset(): void
    {
        self::$pasos = [];
        self::$autoriza = true;
    }

    public static function cuantas(string $paso): int
    {
        return count(array_filter(self::$pasos, fn ($p) => $p === $paso));
    }
}

final class GuardarUnaVezWidgetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        UnaVezLog::$pasos[] = 'prepare';

        // Un recorte típico: lo que deja tiene que ser lo que validan las reglas.
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        UnaVezLog::$pasos[] = 'authorize';

        return UnaVezLog::$autoriza;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        UnaVezLog::$pasos[] = 'rules';

        return [
            'name' => ['required', 'string', 'min:3', Rule::unique('una_vez_widgets', 'name')],
        ];
    }

    protected function passedValidation(): void
    {
        UnaVezLog::$pasos[] = 'passed';
    }
}

final class EditarUnaVezWidgetRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        UnaVezLog::$pasos[] = 'prepare';

        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function authorize(): bool
    {
        UnaVezLog::$pasos[] = 'authorize';

        return UnaVezLog::$autoriza;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        UnaVezLog::$pasos[] = 'rules';

        // Lee el nombre PINEADO, no el de la ruta real (`{id}`).
        return [
            'name' => ['required', 'string', 'min:3', Rule::unique('una_vez_widgets', 'name')->ignore($this->route('widget'))],
        ];
    }

    protected function passedValidation(): void
    {
        UnaVezLog::$pasos[] = 'passed';
    }
}

final class UnaVezWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => UnaVezWidget::class,
        'searchable' => ['name'],
        'store_request' => GuardarUnaVezWidgetRequest::class,
        'update_request' => EditarUnaVezWidgetRequest::class,
        'resource_route_param' => 'widget',
        'features' => ['auto_cache' => false],
    ];
}

beforeEach(function () {
    UnaVezLog::reset();

    $this->bootHttpApp(UnaVezWidget::class, ['tenant' => ['enabled' => false]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('una_vez_widgets', function ($t) {
        $t->increments('id');
        $t->string('name')->unique();
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/una-vez', [UnaVezWidgetController::class, 'store']);
        Route::patch('api/una-vez/{id}', [UnaVezWidgetController::class, 'update']);
    });

    DB::table('una_vez_widgets')->insert([['id' => 1, 'name' => 'alfa'], ['id' => 2, 'name' => 'beta']]);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

// ── update ───────────────────────────────────────────────────────────────────

test('🔴 update: rules(), authorize() y los hooks corren UNA vez por request', function () {
    $status = $this->httpSendPatch('/api/una-vez/1', ['name' => 'gamma'])->getStatusCode();

    // `rules()` solo no alcanza: el FormRequest cachea el validator, así que en la
    // segunda pasada `rules()` no se vuelve a llamar —se REUSA el validator armado
    // contra la ruta real— pero `prepareForValidation()`, `authorize()`, `fails()` y
    // `passedValidation()` sí corren otra vez.
    expect($status)->toBe(200)
        ->and(UnaVezLog::cuantas('rules'))->toBe(1)
        ->and(UnaVezLog::cuantas('prepare'))->toBe(1)
        ->and(UnaVezLog::cuantas('authorize'))->toBe(1)
        ->and(UnaVezLog::cuantas('passed'))->toBe(1);
});

test('🔴 update: el unique lee el param PINEADO — conservar el valor propio da 200', function () {
    $response = $this->httpSendPatch('/api/una-vez/1', ['name' => 'alfa']);

    expect($response->getStatusCode())->toBe(200, (string) $response->getContent())
        ->and(UnaVezWidget::query()->find(1)->name)->toBe('alfa');
});

test('🔴 update: tomar el valor de OTRA fila da 422 por la regla', function () {
    $response = $this->httpSendPatch('/api/una-vez/1', ['name' => 'beta']);

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode((string) $response->getContent(), true)['errors'])->toHaveKey('name')
        ->and(UnaVezWidget::query()->find(1)->name)->toBe('alfa');
});

test('🔴 update: authorize() en false sigue dando 403 y no corre las reglas', function () {
    UnaVezLog::$autoriza = false;

    $status = $this->httpSendPatch('/api/una-vez/1', ['name' => 'gamma'])->getStatusCode();

    expect($status)->toBe(403)
        ->and(UnaVezLog::cuantas('rules'))->toBe(0)
        ->and(UnaVezWidget::query()->find(1)->name)->toBe('alfa');
});

test('🔴 update: prepareForValidation corre UNA vez y antes de las reglas, y lo que deja es lo que se escribe', function () {
    $status = $this->httpSendPatch('/api/una-vez/1', ['name' => '  gamma  '])->getStatusCode();

    expect($status)->toBe(200)
        ->and(UnaVezLog::$pasos)->toBe(['prepare', 'authorize', 'rules', 'passed'])
        ->and(UnaVezWidget::query()->find(1)->name)->toBe('gamma');
});

// ── store ────────────────────────────────────────────────────────────────────

test('store: valida una vez, en el orden de Laravel, y crea', function () {
    $status = $this->httpPost('/api/una-vez', ['name' => '  delta  '])->getStatusCode();

    expect($status)->toBeIn([200, 201])
        ->and(UnaVezLog::$pasos)->toBe(['prepare', 'authorize', 'rules', 'passed'])
        ->and(UnaVezWidget::query()->where('name', 'delta')->exists())->toBeTrue();
});

test('store: un valor repetido da 422 y authorize() en false da 403', function () {
    expect($this->httpPost('/api/una-vez', ['name' => 'alfa'])->getStatusCode())->toBe(422);

    UnaVezLog::$autoriza = false;
    expect($this->httpPost('/api/una-vez', ['name' => 'epsilon'])->getStatusCode())->toBe(403)
        ->and(UnaVezWidget::query()->count())->toBe(2);
});

// ── forma del 422 ────────────────────────────────────────────────────────────

test('la forma del 422 no cambia', function () {
    $store = $this->httpPost('/api/una-vez', ['name' => '']);
    $update = $this->httpSendPatch('/api/una-vez/1', ['name' => '']);

    foreach ([$store, $update] as $response) {
        $body = json_decode((string) $response->getContent(), true);

        expect($response->getStatusCode())->toBe(422)
            ->and(array_keys($body))->toBe(['message', 'errors'])
            ->and(array_keys($body['errors']))->toBe(['name'])
            ->and($body['errors']['name'])->toBeArray()->toHaveCount(1);
    }
});
