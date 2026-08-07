<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * EL SCAFFOLDER EMITE POLICIES QUE `CRUDSmart` NUNCA INVOCA.
 *
 * `mk:make:auth-user X --with-crud` genera una Policy por modelo, la registra
 * en el provider con `Gate::policy()` y lo documenta. Y despues no la llama:
 * no hay una sola referencia a `Gate`, `authorize()` ni `can()` en
 * `src/Traits/CRUDSmart.php` ni en `src/Controllers/SmartController.php`.
 *
 * El resultado es codigo de seguridad muerto que NADIE nota, porque el
 * consumer ve la Policy en su repo, la lee, la modifica, y asume que corre.
 * Es el peor modo de fallar que hay: una defensa que no se ejecuta ocupa el
 * lugar donde alguien buscaria el bug.
 *
 * ── 🔴 POR QUE ESTE TEST VA POR HTTP Y NO LLAMA A LA POLICY ──────────────
 *
 * Un test unitario de la Policy —instanciarla y llamarle `viewAny($user)`—
 * PASA EN VERDE con este bug vivo. Claro: la Policy funciona perfecto; lo que
 * no existe es quien la llame. Es exactamente la trampa que ya nos mordio con
 * el `TenantResolver`, donde el middleware llamado a mano pasaba en verde con
 * la fuga puesta porque el test le entregaba el `$request` ya resuelto.
 *
 * Lo unico que discrimina es el CAMINO REAL: ruta registrada, cadena de
 * middleware, `Kernel::handle()`, controller que hereda de `SmartController`.
 * Por eso este archivo usa `BootsHttpApp`.
 *
 * ── 🔴 Y LA ASERCION VA AL REVES ────────────────────────────────────────
 *
 * Un test que pide 200 con un usuario autorizado no mide nada: queda verde
 * con la Policy desconectada. Lo que vale es que una Policy que NIEGA
 * produzca 403.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class PolicyAdmin extends AuthUser
{
    protected $table = 'policy_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class PolicyWidget extends Model
{
    protected $table = 'policy_widgets';

    protected $fillable = ['name'];

    public $timestamps = false;
}

/**
 * Dice que NO a todo, y ANOTA a quien le preguntaron.
 *
 * El registro del usuario es lo que fija el segundo detalle del hallazgo:
 * `Gate::authorize()` a secas resuelve por el guard POR DEFECTO (`web`), que
 * acá no autentico a nadie. Si el enganche olvidara `forUser()`, la Policy
 * recibiria `null` y el 403 saldria igual — por el motivo equivocado. La
 * unica forma de distinguir "denego bien" de "no vio a nadie" es mirar QUIEN
 * llego.
 */
final class PolicyWidgetDeniega
{
    public static mixed $ultimoUsuario = null;

    public static bool $corrio = false;

    public function viewAny(mixed $user): bool
    {
        self::$corrio = true;
        self::$ultimoUsuario = $user;

        return false;
    }

    public function view(mixed $user, PolicyWidget $widget): bool
    {
        self::$corrio = true;
        self::$ultimoUsuario = $user;

        return false;
    }
}

/** Permite todo. Es el control: separa "la policy denego" de "algo mas rompio". */
final class PolicyWidgetPermite
{
    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, PolicyWidget $widget): bool
    {
        return true;
    }
}

/** Controller sin opinion: hereda lo que diga la config global. */
final class PolicyWidgetController extends SmartController
{
    protected array $mkConfig = [
        'model' => PolicyWidget::class,
        'searchable' => ['name'],
    ];
}

/** Controller que PIDE autorizar, sin importar el default global. */
final class PolicyWidgetOptInController extends SmartController
{
    protected array $mkConfig = [
        'model' => PolicyWidget::class,
        'searchable' => ['name'],
        'features' => ['authorize_with_policy' => true],
    ];
}

/** Controller que la RECHAZA, aunque el default global la prenda. */
final class PolicyWidgetOptOutController extends SmartController
{
    protected array $mkConfig = [
        'model' => PolicyWidget::class,
        'searchable' => ['name'],
        'features' => ['authorize_with_policy' => false],
    ];
}

beforeEach(function () {
    PolicyWidgetDeniega::$ultimoUsuario = null;
    PolicyWidgetDeniega::$corrio = false;
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/**
 * Arma la app con el toggle en el estado pedido y devuelve un token valido.
 */
function armarMundoDePolicies(object $test, ?bool $autorizar): string
{
    $features = ['auto_cache' => false];

    if ($autorizar !== null) {
        $features['authorize_with_policy'] = $autorizar;
    }

    $test->bootHttpApp(PolicyAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => $features,
    ]);

    Schema::create('policy_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->string('client_id')->nullable();
        $t->timestamps();
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

    // `policy_admin_id` es la FK que Eloquent INFIERE del nombre del modelo.
    // Sin la columna, el request muere en 500 cargando roles y el test no
    // llega a medir nada.
    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('policy_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }

    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Schema::create('policy_widgets', function ($t) {
        $t->increments('id');
        $t->string('name');
    });

    PolicyWidget::create(['name' => 'el-unico-widget']);

    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/widgets', [PolicyWidgetController::class, 'index']);
        Route::get('api/widgets/{id}', [PolicyWidgetController::class, 'show']);
        Route::get('api/widgets-opt-in', [PolicyWidgetOptInController::class, 'index']);
        Route::get('api/widgets-opt-out', [PolicyWidgetOptOutController::class, 'index']);
    });

    $admin = PolicyAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@policy.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
    ]);

    return app(TokenIssuer::class)->issueAccessToken($admin)->plainTextToken;
}

function pedir(object $test, string $uri, string $token): int
{
    return $test->httpGet($uri, ['HTTP_AUTHORIZATION' => 'Bearer '.$token])->getStatusCode();
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: con la Policy registrada y NEGANDO, el listado responde igual', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    expect(pedir($this, '/api/widgets', $token))->toBe(403);
});

test('🔴 EL BUG, en el detalle: `show` tampoco pregunta', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    expect(pedir($this, '/api/widgets/1', $token))->toBe(403);
});

/*
|--------------------------------------------------------------------------
| EL CONTROL. Sin esto, el 403 de arriba podria venir de cualquier otra
| cosa —la ruta, el token, el guard— y el test pasaria por el motivo
| equivocado.
|--------------------------------------------------------------------------
*/
test('CONTROL: la misma ruta con una Policy que PERMITE responde 200', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    Gate::policy(PolicyWidget::class, PolicyWidgetPermite::class);

    expect(pedir($this, '/api/widgets', $token))->toBe(200);
});

/*
|--------------------------------------------------------------------------
| 🔴 LA TRAMPA DEL GUARD.
|
| `Gate::authorize()` a secas resuelve el usuario por el guard POR DEFECTO
| (`web`), y acá autentico `mk.auth:admin`, que es otro. Sin
| `Gate::forUser($request->user())` la Policy recibe `null` y TODO da 403:
| una defensa que no distingue nada, y que ademas da el MISMO codigo que la
| implementacion correcta. Por eso no alcanza con mirar el status.
|--------------------------------------------------------------------------
*/
test('la Policy recibe el usuario del scope, no el `null` del guard por defecto', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    pedir($this, '/api/widgets', $token);

    expect(PolicyWidgetDeniega::$corrio)->toBeTrue()
        ->and(PolicyWidgetDeniega::$ultimoUsuario)->toBeInstanceOf(PolicyAdmin::class)
        ->and(PolicyWidgetDeniega::$ultimoUsuario->email)->toBe('admin@policy.local');
});

/*
|--------------------------------------------------------------------------
| COMPATIBILIDAD. Estos tres son los que protegen a RETO.
|--------------------------------------------------------------------------
*/
test('BC: con el toggle en su default, una Policy que niega NO cambia nada', function () {
    // `null` = no se pinea la feature: queda el default del paquete.
    $token = armarMundoDePolicies($this, autorizar: null);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    expect(pedir($this, '/api/widgets', $token))->toBe(200)
        ->and(PolicyWidgetDeniega::$corrio)->toBeFalse();
});

test('un controller puede pedir autorizar aunque el default global este apagado', function () {
    $token = armarMundoDePolicies($this, autorizar: false);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    expect(pedir($this, '/api/widgets-opt-in', $token))->toBe(403)
        // Y el que no lo pide sigue pasando: el opt-in es POR CONTROLLER, no
        // un interruptor que se contagia.
        ->and(pedir($this, '/api/widgets', $token))->toBe(200);
});

test('un controller puede quedarse afuera aunque el default global este prendido', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    Gate::policy(PolicyWidget::class, PolicyWidgetDeniega::class);

    expect(pedir($this, '/api/widgets-opt-out', $token))->toBe(200)
        // Contraprueba en el mismo test: con el default prendido, el que NO se
        // excluye si da 403. Sin esto, el 200 podria significar "el toggle
        // global tampoco funciona".
        ->and(pedir($this, '/api/widgets', $token))->toBe(403);
});

/*
|--------------------------------------------------------------------------
| 🔴 SIN POLICY REGISTRADA, PRENDER EL TOGGLE NO PUEDE CERRAR NADA.
|
| Es la condicion que hace seguro poner esto en `true` algun dia. Laravel
| resuelve `Gate::authorize()` sin policy contra las abilities sueltas del
| Gate y, al no encontrar ninguna, DENIEGA. O sea: un consumer sin Policies
| —el caso mas comun— se despertaria con 403 en todo su CRUD.
|--------------------------------------------------------------------------
*/
test('un modelo SIN Policy registrada no se ve afectado por el toggle', function () {
    $token = armarMundoDePolicies($this, autorizar: true);

    // A proposito NO se registra ninguna policy para PolicyWidget.
    expect(pedir($this, '/api/widgets', $token))->toBe(200);
});
