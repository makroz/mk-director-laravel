<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * UN LISTADO DEVUELVE LA RESPUESTA DE OTRA CONSULTA.
 *
 * Medido contra una API real con 83 filas en `abilities`:
 *
 *     GET /abilities?q=branches    -> 7 filas    (correcto)
 *     GET /abilities?per_page=500  -> 7 filas    ❌ total: 7, success: true
 *     php artisan cache:clear
 *     GET /abilities?per_page=500  -> 83 filas   ✅
 *
 * Es el peor tipo de bug que hay: no tira error, no loguea, responde 200 y
 * confirma el numero equivocado en `total`. La pantalla dice "sin resultados"
 * con la base llena y el consumer no tiene forma de distinguirlo de "no hay
 * datos".
 *
 * ── POR QUE ESTE TEST NO ES EL OBVIO ─────────────────────────────────────
 *
 * 🔴 UN TEST QUE PIDE LA MISMA CONSULTA DOS VECES PASA EN VERDE CON EL BUG
 * VIVO. Devolver lo mismo ante lo mismo es, justamente, lo que se espera de
 * un cache. Lo unico que discrimina es pedir dos consultas DISTINTAS: la
 * segunda tiene que traer lo suyo y no lo de la primera.
 *
 * 🔴 Y LOS DOS PEDIDOS VAN EN EL MISMO TEST, no uno por test. El arrastre es
 * ENTRE requests: si cada uno corre en su propio test, el estado nace limpio
 * y no hay nada que medir.
 *
 * ── LA CAUSA NO ES LA QUE PARECIA ────────────────────────────────────────
 *
 * El sintoma grita "cache", y `php artisan cache:clear` lo arregla, asi que
 * el diagnostico natural es "la clave de cache no mira los parametros". Es
 * FALSO, y este archivo lo demuestra con un test aparte: la clave de
 * `CRUDSmart::index()` ya se arma con `toSql() + bindings + page + cursor +
 * perPage`, o sea que dos consultas distintas YA tienen claves distintas.
 *
 * El culpable es `remember_state`. `ListManager::restoreState()` guarda el
 * `q` / `filter` / `sort` del ultimo pedido y se los RE-INYECTA al siguiente
 * que no los traiga:
 *
 *     } elseif (isset($state['q'])) {
 *         $request->query->set('q', $state['q']);
 *     }
 *
 * O sea que el segundo request no recibe una respuesta cacheada: recibe una
 * respuesta CORRECTA a una consulta que el cliente nunca hizo. Por eso
 * `cache:clear` lo "arregla" —el estado vive en el cache— y por eso apagar
 * `auto_cache` NO lo arregla. Los dos hechos juntos solo los explica esta
 * causa, y estan los dos pineados abajo.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class ListStateAdmin extends AuthUser
{
    protected $table = 'list_state_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class ListStateCategory extends Model
{
    protected $table = 'list_state_categories';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class ListStateItem extends Model
{
    protected $table = 'list_state_items';

    /**
     * `ListManager::applySorting()` valida el campo pedido contra
     * `getFillable()`. Con `$guarded = []` el fillable sale VACIO y el `sort`
     * se descarta en silencio — el test creeria estar midiendo un orden que
     * nunca se aplico.
     */
    protected $fillable = ['name', 'category_id'];

    public $timestamps = false;

    public function category()
    {
        return $this->belongsTo(ListStateCategory::class, 'category_id');
    }
}

final class ListStateItemController extends SmartController
{
    protected array $mkConfig = [
        'model' => ListStateItem::class,
        'searchable' => ['name'],
        'features' => [
            'auto_cache' => true,
            'remember_state' => true,
        ],
    ];
}

/**
 * El mismo controller con el cache APAGADO. Es la mitad del experimento que
 * separa "la clave de cache esta mal" de "el estado se re-inyecta".
 */
final class ListStateItemNoCacheController extends SmartController
{
    protected array $mkConfig = [
        'model' => ListStateItem::class,
        'searchable' => ['name'],
        'features' => [
            'auto_cache' => false,
            'remember_state' => true,
        ],
    ];
}

/**
 * Y el mismo controller con `remember_state` apagado y el cache PRENDIDO: la
 * otra mitad. Si la clave de cache fuera el problema, ESTE tendria que
 * fallar.
 */
final class ListStateItemNoRememberController extends SmartController
{
    protected array $mkConfig = [
        'model' => ListStateItem::class,
        'searchable' => ['name'],
        'allowedIncludes' => ['category'],
        'features' => [
            'auto_cache' => true,
            'remember_state' => false,
        ],
    ];
}

beforeEach(function () {
    $this->bootHttpApp(ListStateAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => true, 'remember_state' => true],
    ]);

    Schema::create('list_state_admins', function ($t) {
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

    // `list_state_admin_id` es la FK que Eloquent INFIERE del nombre del
    // modelo. `AuthUser` no la override, asi que sin esta columna el request
    // muere en 500 al cargar los roles y el test no llega a medir nada.
    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('list_state_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }

    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    // 🔴 Los datos son inconfundibles: 3 filas que matchean `zeta` y 17 que
    // no. Contar no alcanzaria si los numeros coincidieran de casualidad, asi
    // que las aserciones van sobre los NOMBRES.
    Schema::create('list_state_categories', function ($t) {
        $t->increments('id');
        $t->string('name');
    });

    Schema::create('list_state_items', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->unsignedInteger('category_id')->nullable();
    });

    $categoria = ListStateCategory::create(['name' => 'la-categoria']);

    foreach (range(1, 17) as $i) {
        ListStateItem::create([
            'name' => 'alfa-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'category_id' => $categoria->id,
        ]);
    }
    foreach (range(1, 3) as $i) {
        ListStateItem::create([
            'name' => 'zeta-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'category_id' => $categoria->id,
        ]);
    }

    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/items', [ListStateItemController::class, 'index']);
        Route::get('api/items-sin-cache', [ListStateItemNoCacheController::class, 'index']);
        Route::get('api/items-sin-remember', [ListStateItemNoRememberController::class, 'index']);
    });
});

afterEach(function () {
    $this->tearDownHttpApp();
});

function tokenDeListStateAdmin(): string
{
    $admin = ListStateAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@test.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
    ]);

    return app(TokenIssuer::class)->issueAccessToken($admin)->plainTextToken;
}

/**
 * @return list<string>
 */
function nombresDe(object $test, string $uri, string $token): array
{
    $response = $test->httpGet($uri, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);

    $body = (array) json_decode((string) $response->getContent(), true);

    return array_map(
        static fn (array $row): string => (string) $row['name'],
        $body['data'] ?? [],
    );
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: despues de buscar `zeta`, un listado SIN busqueda sigue devolviendo los `zeta`', function () {
    $token = tokenDeListStateAdmin();

    // Consulta 1 — con busqueda. Correcta hoy tambien.
    $conBusqueda = nombresDe($this, '/api/items?q=zeta&per_page=50', $token);

    expect($conBusqueda)->toBe(['zeta-03', 'zeta-02', 'zeta-01']);

    // Consulta 2 — DISTINTA: sin `q`, y ademas otro `per_page`. Tiene que
    // traer las 20 filas.
    $sinBusqueda = nombresDe($this, '/api/items?per_page=50', $token);

    // Positiva y negativa a la vez: no alcanza con "no son solo los zeta",
    // porque una lista vacia tambien cumpliria eso.
    expect($sinBusqueda)->toHaveCount(20)
        ->and($sinBusqueda)->toContain('alfa-01')
        ->and($sinBusqueda)->toContain('zeta-01');
});

test('🔴 EL BUG, al reves: un filtro viejo tampoco puede sobrevivir a un listado nuevo', function () {
    $token = tokenDeListStateAdmin();

    // Se ordena descendente primero, y despues se pide sin `sort`. El default
    // del paquete es `id desc`, asi que para que la asercion signifique algo
    // se compara contra un orden que el default NO produce.
    $ordenado = nombresDe($this, '/api/items?q=alfa&sort=name&dir=asc&per_page=5', $token);

    expect($ordenado)->toBe(['alfa-01', 'alfa-02', 'alfa-03', 'alfa-04', 'alfa-05']);

    $sinNada = nombresDe($this, '/api/items?per_page=5', $token);

    // Sin `q` ni `sort`, el default es `id desc`: las ultimas creadas son las
    // `zeta`. Si el estado se arrastra, salen `alfa` ordenadas por nombre.
    expect($sinNada)->toBe(['zeta-03', 'zeta-02', 'zeta-01', 'alfa-17', 'alfa-16']);
});

/*
|--------------------------------------------------------------------------
| LOS DOS TESTS QUE IDENTIFICAN LA CAUSA
|
| El sintoma apunta al cache y el diagnostico natural es "la clave no mira
| los parametros". Estos dos lo descartan: apagar el cache NO arregla, y
| apagar `remember_state` SI. La causa es la re-inyeccion del estado.
|
| 🔴 Van en el archivo del fix a proposito. Borrar el supuesto culpable y ver
| verde no prueba nada por si solo; lo que prueba es el PAR — que el sospechoso
| descartado siga en rojo y el verdadero se ponga en verde.
|--------------------------------------------------------------------------
*/
test('NO ES EL CACHE: con auto_cache apagado el arrastre sigue vivo', function () {
    $token = tokenDeListStateAdmin();

    nombresDe($this, '/api/items-sin-cache?q=zeta&per_page=50', $token);

    $sinBusqueda = nombresDe($this, '/api/items-sin-cache?per_page=50', $token);

    expect($sinBusqueda)->toHaveCount(20);
});

test('ES remember_state: con la feature apagada, el cache prendido no arrastra nada', function () {
    $token = tokenDeListStateAdmin();

    $conBusqueda = nombresDe($this, '/api/items-sin-remember?q=zeta&per_page=50', $token);

    expect($conBusqueda)->toBe(['zeta-03', 'zeta-02', 'zeta-01']);

    $sinBusqueda = nombresDe($this, '/api/items-sin-remember?per_page=50', $token);

    expect($sinBusqueda)->toHaveCount(20);
});

/*
|--------------------------------------------------------------------------
| Lo que `remember_state` SI tiene que seguir haciendo.
|
| El arreglo no puede ser "sacar la feature": recordar el estado es lo que
| hace que volver a una pantalla te devuelva la busqueda donde la dejaste.
| Lo que no puede hacer es aplicarselo a un pedido que pidio otra cosa.
|--------------------------------------------------------------------------
*/
test('remember_state sigue recordando: el estado se devuelve cuando el cliente lo PIDE', function () {
    $token = tokenDeListStateAdmin();

    nombresDe($this, '/api/items?q=zeta&per_page=50', $token);

    $restaurado = nombresDe($this, '/api/items?per_page=50&restore_state=1', $token);

    expect($restaurado)->toBe(['zeta-03', 'zeta-02', 'zeta-01']);
});

test('el estado recordado es POR USUARIO: el de uno no se le aplica al otro', function () {
    $unToken = tokenDeListStateAdmin();
    $otroAdmin = ListStateAdmin::create([
        'name' => 'Otro',
        'email' => 'otro@test.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
    ]);
    $otroToken = app(TokenIssuer::class)->issueAccessToken($otroAdmin)->plainTextToken;

    nombresDe($this, '/api/items?q=zeta&per_page=50', $unToken);

    $delOtro = nombresDe($this, '/api/items?per_page=50&restore_state=1', $otroToken);

    expect($delOtro)->toHaveCount(20);
});

/*
|--------------------------------------------------------------------------
| EL OTRO AGUJERO DE LA CLAVE, Y ESTE SI ES DE LA CLAVE.
|
| 🔴 `?include=category` NO CAMBIA EL SQL. El eager loading se resuelve en
| queries APARTE, asi que `toSql()` devuelve exactamente lo mismo con y sin
| el include — y la clave se arma con `toSql()`.
|
| O sea: dos pedidos que piden cosas distintas comparten entrada de cache. El
| que llega segundo recibe el payload del primero, con las relaciones de mas
| o de menos, y con `success: true`.
|
| Se prueba con `remember_state` APAGADO a proposito: asi el unico mecanismo
| que puede producir el arrastre es la clave.
|--------------------------------------------------------------------------
*/
test('🔴 la clave de cache ignora los includes: pedir con `include` despues de pedir sin el', function () {
    $token = tokenDeListStateAdmin();

    // Primero SIN include: se cachea un payload sin la relacion.
    $sinInclude = $this->httpGet('/api/items-sin-remember?per_page=5', ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $filasSinInclude = ((array) json_decode((string) $sinInclude->getContent(), true))['data'] ?? [];

    expect($filasSinInclude[0])->not->toHaveKey('category');

    // Ahora CON include: es otra consulta y tiene que traer la relacion.
    $conInclude = $this->httpGet('/api/items-sin-remember?per_page=5&include=category', ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    $filasConInclude = ((array) json_decode((string) $conInclude->getContent(), true))['data'] ?? [];

    expect($filasConInclude[0])->toHaveKey('category')
        ->and($filasConInclude[0]['category']['name'] ?? null)->toBe('la-categoria');
});

/*
|--------------------------------------------------------------------------
| EL TENANT EN LA CLAVE.
|
| Hoy el tenant sólo vive en los TAGS, y los tags son un mecanismo de
| INVALIDACION, no de unicidad. Peor: cuando el driver no soporta tags
| —`file` y `database`, dos defaults de Laravel— `CacheManager::remember()`
| descarta todos los tags menos el PRIMERO, que es la tabla. El tenant
| desaparece de la separacion sin que nadie se entere.
|
| ⚠️ Se mide sobre la CLAVE y no sobre una fuga de datos, y es a proposito:
| mientras la aislacion viva en un global scope, el tenant ya llega por los
| bindings y no hay fuga que mostrar. Lo que este test defiende es no
| DEPENDER de eso — un consumer que aisle fuera de la query convierte la
| cache en una fuga entre clientes. Afirmar mas que esto seria inventar un
| bug que hoy no se puede reproducir.
|--------------------------------------------------------------------------
*/
test('la clave de cache distingue el tenant aunque el SQL sea identico', function () {
    $controller = new ListStateItemNoRememberController;

    $clave = function (?string $tenantId) use ($controller): string {
        app(TenantContext::class)->flush();

        if ($tenantId !== null) {
            app(TenantContext::class)->set($tenantId);
        }

        $metodo = new ReflectionMethod($controller, 'buildQueryCacheKey');
        $metodo->setAccessible(true);

        // La MISMA query para los dos: si el tenant no estuviera en la clave,
        // no habria nada que los distinga.
        return $metodo->invoke($controller, ListStateItem::query(), ['page' => 1]);
    };

    $napoli = $clave('tenant-napoli');
    $roma = $clave('tenant-roma');
    $sinTenant = $clave(null);

    expect($napoli)->not->toBe($roma)
        ->and($napoli)->not->toBe($sinTenant)
        ->and($roma)->not->toBe($sinTenant);

    // Y la contraprueba de que la clave no es simplemente aleatoria: el mismo
    // tenant con la misma query tiene que dar la MISMA clave, o no habria
    // cache en absoluto.
    expect($clave('tenant-napoli'))->toBe($napoli);
});
