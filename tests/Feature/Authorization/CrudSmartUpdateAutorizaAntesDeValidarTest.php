<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 92: `update()` VALIDABA ANTES DE AUTORIZAR.
 *
 * Con `authorize_with_policy`, `store()` pregunta a la Policy y DESPUÉS resuelve el
 * FormRequest. `update()` hacía al revés: `resolveFormRequest('update_request')`
 * primero, `findScopedOrFail()` + `authorizeWithMkPolicy('update')` después. Tres
 * efectos:
 *
 * - quien no puede editar recibía un 422 con los mensajes de validación, no un 403;
 * - con una regla `exists`/`unique`, el 422 le contestaba si una fila existe;
 * - un id inexistente con un cuerpo inválido daba 422, no 404.
 *
 * ── POR QUÉ LA POLICY TIPA `mixed $user` Y LA RUTA NO TIENE `mk.auth` ────────
 *
 * Lo que se mide es el ORDEN, no quién es el usuario (eso lo fija
 * `CrudSmartInvokesPolicyTest`). `mixed` admite `null`, así que el Gate llama a la
 * Policy sin usuario y su respuesta es la única variable.
 *
 * ── POR QUÉ `FoundationServiceProvider` ─────────────────────────────────────
 *
 * Sin él, el FormRequest se construye sin la entrada de la request y valida un cuerpo
 * vacío: el 422 aparecería por el motivo equivocado. Ver
 * `CrudSmartEscribeSoloLoValidadoTest`.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class AuthzWidget extends Model
{
    protected $table = 'authz_widgets';

    protected $fillable = ['name', 'owner_id'];

    public $timestamps = false;
}

/** `name` obligatorio y un `exists`: la regla que contestaba si una fila existe. */
final class ActualizarAuthzWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3'],
            'owner_id' => ['sometimes', 'integer', 'exists:authz_owners,id'],
        ];
    }
}

final class AuthzWidgetNiega
{
    public static bool $corrio = false;

    public function update(mixed $user, AuthzWidget $widget): bool
    {
        self::$corrio = true;

        return false;
    }
}

final class AuthzWidgetPermite
{
    public function update(mixed $user, AuthzWidget $widget): bool
    {
        return true;
    }
}

final class AuthzWidgetFlagPrendido extends SmartController
{
    protected array $mkConfig = [
        'model' => AuthzWidget::class,
        'searchable' => ['name'],
        'update_request' => ActualizarAuthzWidgetRequest::class,
        'features' => ['authorize_with_policy' => true, 'auto_cache' => false],
    ];
}

final class AuthzWidgetFlagApagado extends SmartController
{
    protected array $mkConfig = [
        'model' => AuthzWidget::class,
        'searchable' => ['name'],
        'update_request' => ActualizarAuthzWidgetRequest::class,
        'features' => ['authorize_with_policy' => false, 'auto_cache' => false],
    ];
}

beforeEach(function () {
    AuthzWidgetNiega::$corrio = false;

    $this->bootHttpApp(AuthzWidget::class, ['tenant' => ['enabled' => false]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('authz_owners', function ($t) {
        $t->increments('id');
    });

    Schema::create('authz_widgets', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->unsignedInteger('owner_id')->nullable();
    });

    Route::middleware(['api'])->group(function () {
        Route::patch('api/authz-on/{id}', [AuthzWidgetFlagPrendido::class, 'update']);
        Route::patch('api/authz-off/{id}', [AuthzWidgetFlagApagado::class, 'update']);
    });

    // El dueño 1 existe; el 999 no.
    \Illuminate\Support\Facades\DB::table('authz_owners')->insert(['id' => 1]);
    AuthzWidget::query()->create(['name' => 'original']);
});

afterEach(function () {
    $this->tearDownHttpApp();
});

function patchAuthz(object $test, string $ruta, array $body): int
{
    return $test->httpSendPatch($ruta, $body)->getStatusCode();
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 la Policy que niega da 403 aunque el cuerpo sea inválido', function () {
    Gate::policy(AuthzWidget::class, AuthzWidgetNiega::class);

    expect(patchAuthz($this, '/api/authz-on/1', ['name' => '']))->toBe(403)
        ->and(AuthzWidget::query()->find(1)->name)->toBe('original');
});

test('🔴 con un `exists`, el que no puede editar recibe lo MISMO exista o no la fila', function () {
    Gate::policy(AuthzWidget::class, AuthzWidgetNiega::class);

    expect(patchAuthz($this, '/api/authz-on/1', ['name' => 'valido', 'owner_id' => 999]))->toBe(403)
        ->and(patchAuthz($this, '/api/authz-on/1', ['name' => 'valido', 'owner_id' => 1]))->toBe(403)
        ->and(AuthzWidget::query()->find(1)->owner_id)->toBeNull();
});

test('🔴 un id inexistente con un cuerpo inválido da 404, no 422', function () {
    Gate::policy(AuthzWidget::class, AuthzWidgetNiega::class);

    expect(patchAuthz($this, '/api/authz-on/999', ['name' => '']))->toBe(404);
});

test('CONTROL: con la Policy que permite, un cuerpo inválido sigue dando 422', function () {
    Gate::policy(AuthzWidget::class, AuthzWidgetPermite::class);

    expect(patchAuthz($this, '/api/authz-on/1', ['name' => '']))->toBe(422)
        ->and(patchAuthz($this, '/api/authz-on/1', ['name' => 'valido', 'owner_id' => 999]))->toBe(422)
        ->and(patchAuthz($this, '/api/authz-on/1', ['name' => 'nuevo', 'owner_id' => 1]))->toBe(200)
        ->and(AuthzWidget::query()->find(1)->name)->toBe('nuevo');
});

/*
|--------------------------------------------------------------------------
| BC: con el flag apagado el orden NO cambia. La Policy no se consulta y la
| validación sigue corriendo primero — incluso con un id inexistente, que da
| 422 como antes. No es una virtud: es lo que el flag apagado promete.
|--------------------------------------------------------------------------
*/
test('BC: con el flag apagado, valida primero y no consulta la Policy', function () {
    Gate::policy(AuthzWidget::class, AuthzWidgetNiega::class);

    expect(patchAuthz($this, '/api/authz-off/1', ['name' => '']))->toBe(422)
        ->and(patchAuthz($this, '/api/authz-off/999', ['name' => '']))->toBe(422)
        ->and(patchAuthz($this, '/api/authz-off/999', ['name' => 'valido']))->toBe(404)
        ->and(patchAuthz($this, '/api/authz-off/1', ['name' => 'nuevo']))->toBe(200)
        ->and(AuthzWidgetNiega::$corrio)->toBeFalse();
});
