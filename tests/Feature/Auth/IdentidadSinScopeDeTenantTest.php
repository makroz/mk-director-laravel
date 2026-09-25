<?php

declare(strict_types=1);

use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tenancy\HasTenantScope;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 45: CON `fail_closed` PRENDIDO, EL `BaseAuthController` NO ENCUENTRA
 * A NADIE.
 *
 * `login()`, `forgotPassword()`, `resetPassword()`, los dos pasos del PIN,
 * `verifyEmail()` y el login de dos pasos buscaban al usuario con
 * `authModelClass()::query()`. Si el modelo del scope lleva `HasTenantScope`
 * —que es lo que aísla sus LISTADOS—, esa búsqueda corre con el scope puesto y
 * **sin contexto**: en estas rutas no hay usuario del que sacar el tenant, porque
 * el tenant es justamente lo que se quiere averiguar.
 *
 * Con `fail_closed` en `true` el scope sin contexto agrega `where 1 = 0`, así que
 * la consulta devuelve cero filas y el controller responde lo mismo que si el
 * usuario no existiera. Medido en el piloto:
 *
 *     POST /auth/login con credenciales válidas  -> 422 «Credenciales inválidas»
 *     POST /auth/password/forgot                 -> el token no se guarda
 *     POST /auth/password/reset/code/request     -> el PIN no se encola
 *
 * 🔴 **Sin error ni log**: la anti-enumeración responde igual exista o no el
 * usuario, así que «no se encontró por el scope» y «no existe» son
 * indistinguibles. Y hay un usuario para el que anclar el contexto no alcanza
 * nunca: el super-admin que crea `mk:auth:create-super-admin`, con tenant nulo.
 *
 * ── 🔴 POR QUÉ ESTE TEST VA POR HTTP ─────────────────────────────────────────
 *
 * Un test que llame al método de búsqueda a mano no mide nada: el global scope se
 * aplica igual, pero lo que hace fallar al piloto es la COMBINACIÓN —modelo
 * tenant-scoped, `fail_closed` prendido, ruta pública sin contexto de tenant— y
 * eso sólo se arma con la cadena entera.
 *
 * ⚠️ Y el CONTROL es obligatorio: un 200 en el login también sale si el scope no
 * está registrado, o si `fail_closed` quedó apagado. Por eso hay un caso que
 * afirma que el mismo modelo SÍ está aislado en una consulta normal.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class IdentidadAdmin extends AuthUser
{
    use HasTenantScope;

    protected $table = 'identidad_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    protected static bool $usesTenant = true;

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class IdentidadAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return IdentidadAdmin::class;
    }

    protected function authScope(): string
    {
        return 'admin';
    }

    protected function loginField(): string
    {
        return 'email';
    }

    protected function passwordResetTable(): string
    {
        return 'admin_password_reset_tokens';
    }
}

beforeEach(function () {
    // 🔴 `tenant.enabled` en `true` Y `fail_closed` en `true`: es la config del
    // piloto, y con cualquiera de las dos apagada este archivo mide otra cosa.
    $this->bootHttpApp(IdentidadAdmin::class, [
        'tenant' => ['enabled' => true, 'fail_closed' => true, 'strict' => false],
    ]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    app(TenantContext::class)->flush();

    Schema::create('identidad_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->unsignedTinyInteger('status')->default(ScopeStatus::Active->value);
        $t->string('tenant_id')->nullable();
        $t->timestamps();
    });

    Schema::create('admin_password_reset_tokens', function ($t) {
        $t->string('email')->index();
        $t->string('token');
        $t->timestamp('created_at')->nullable();
    });

    Schema::create('personal_access_tokens', function ($t) {
        $t->id();
        $t->morphs('tokenable');
        $t->string('name');
        $t->string('token', 64)->unique();
        $t->text('abilities')->nullable();
        $t->timestamp('last_used_at')->nullable();
        $t->timestamp('expires_at')->nullable();
        $t->timestamps();
    });

    foreach (['roles', 'abilities'] as $name) {
        Schema::create($name, function ($t) {
            $t->uuid('id')->primary();
            $t->string('name');
            $t->timestamps();
        });
    }
    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('identidad_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [IdentidadAuthController::class, 'login']);
        Route::post('api/admin/auth/password/forgot', [IdentidadAuthController::class, 'forgotPassword']);
    });

    // 🔴 `tenant_id` se escribe POR ATRIBUTO. `AuthUser` declara `$fillable`, y
    // un `$fillable` no vacío gana sobre el `$guarded = []` de la subclase: pasado
    // en el array del `create()` se descarta en silencio y las dos filas quedan con
    // tenant nulo — con lo cual el control de aislamiento mediría nada.
    $napoli = IdentidadAdmin::withoutGlobalScope('tenant')->create([
        'name' => 'Admin de Napoli',
        'email' => 'admin@napoli.local',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
    ]);
    $napoli->tenant_id = 'napoli';
    $napoli->save();

    // El que no tiene tenant: para éste, anclar el contexto no alcanza nunca.
    IdentidadAdmin::withoutGlobalScope('tenant')->create([
        'name' => 'Super',
        'email' => 'super@local',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
        'tenant_id' => null,
    ]);
});

afterEach(function () {
    app(TenantContext::class)->flush();
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

function identidadLogin(object $test, string $email): int
{
    return $test->httpPost('/api/admin/auth/login', [
        'email' => $email,
        'password' => 'secret',
    ])->getStatusCode();
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: con fail_closed prendido y sin contexto, el login entra', function () {
    expect(identidadLogin($this, 'admin@napoli.local'))->toBe(200);
});

test('🔴 y el super-admin SIN tenant también, que es el que no tiene contexto que anclar', function () {
    expect(identidadLogin($this, 'super@local'))->toBe(200);
});

test('🔴 la recuperación de contraseña guarda su token, no lo tira en silencio', function () {
    $response = $this->httpPost('/api/admin/auth/password/forgot', ['email' => 'admin@napoli.local']);

    expect($response->getStatusCode())->toBe(200)
        ->and(DB::table('admin_password_reset_tokens')
            ->where('email', 'admin@napoli.local')->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| LOS CONTROLES. Sin estos, los tres verdes de arriba también salen si el
| scope nunca se registró o si `fail_closed` quedó apagado — o sea midiendo
| un mundo donde el bug no podía existir.
|--------------------------------------------------------------------------
*/

test('CONTROL: el mismo modelo SIGUE aislado en una consulta normal', function () {
    // Sin contexto y con fail_closed: cero filas. Es el mecanismo que rompía
    // el login, intacto donde tiene que estar.
    expect(IdentidadAdmin::query()->count())->toBe(0);

    app(TenantContext::class)->set('napoli');
    expect(IdentidadAdmin::query()->count())->toBe(1);

    app(TenantContext::class)->set('roma');
    expect(IdentidadAdmin::query()->count())->toBe(0);
});

test('CONTROL: una credencial equivocada sigue dando 422', function () {
    $status = $this->httpPost('/api/admin/auth/login', [
        'email' => 'admin@napoli.local',
        'password' => 'la-que-no-es',
    ])->getStatusCode();

    expect($status)->toBe(422);
});

test('CONTROL: un email que no existe sigue dando 422', function () {
    expect(identidadLogin($this, 'nadie@local'))->toBe(422);
});
