<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Managers\ListManager;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * `?cols=` NO PUEDE DEJAR A LAS RELACIONES SIN SU CLAVE.
 *
 * `ListManager::applyColumns()` hace `select(<lo pedido>)` y el `with` del
 * controller se agrega DESPUÉS. Con `?cols=name` y `'with' => ['category']`
 * el `category_id` no viajaba en el SELECT, el eager load no encontraba con
 * qué emparejar y cada fila salía con `category: null` — 200, `success: true`,
 * sin un error. Lo mismo con un HasMany: sin el `id` del padre, los hijos
 * salían `[]`.
 *
 * El arreglo agrega al SELECT —sólo cuando hay una lista explícita— las
 * claves que piden las relaciones que se van a cargar. ⚠️ Consecuencia
 * aceptada: esas claves (el `id`, el `category_id`) aparecen en el JSON aunque
 * no estén en `cols`.
 *
 * `withCount` no dependía de esto (su subquery se correlaciona contra la tabla
 * del padre, no contra una columna del SELECT): su test queda como guarda.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class KrkAdmin extends AuthUser
{
    protected $table = 'krk_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class KrkCategory extends Model
{
    protected $table = 'krk_categories';

    protected $fillable = ['name'];

    public $timestamps = false;
}

final class KrkNote extends Model
{
    protected $table = 'krk_notes';

    protected $fillable = ['item_id', 'body'];

    public $timestamps = false;
}

final class KrkItem extends Model
{
    protected $table = 'krk_items';

    protected $fillable = ['name', 'category_id'];

    public $timestamps = false;

    public function category(): BelongsTo
    {
        return $this->belongsTo(KrkCategory::class, 'category_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(KrkNote::class, 'item_id');
    }
}

final class KrkBelongsToController extends SmartController
{
    protected array $mkConfig = ['model' => KrkItem::class, 'with' => ['category']];
}

final class KrkHasManyController extends SmartController
{
    protected array $mkConfig = ['model' => KrkItem::class, 'with' => ['notes']];
}

final class KrkWithCountController extends SmartController
{
    protected array $mkConfig = ['model' => KrkItem::class, 'withCount' => ['notes']];
}

final class KrkIncludeController extends SmartController
{
    protected array $mkConfig = ['model' => KrkItem::class, 'allowedIncludes' => ['category', 'notes']];
}

beforeEach(function () {
    $this->bootHttpApp(KrkAdmin::class, [
        'tenant' => ['enabled' => false],
        'features' => ['auto_cache' => false],
    ]);

    Schema::create('krk_admins', function ($t) {
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

    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->uuid($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('krk_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }

    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Schema::create('krk_categories', function ($t) {
        $t->increments('id');
        $t->string('name');
    });

    Schema::create('krk_items', function ($t) {
        $t->increments('id');
        $t->string('name');
        $t->unsignedInteger('category_id')->nullable();
    });

    Schema::create('krk_notes', function ($t) {
        $t->increments('id');
        $t->unsignedInteger('item_id');
        $t->string('body');
    });

    // Datos inconfundibles: cada item tiene SU categoría y SU número de notas,
    // así un emparejamiento cruzado tampoco pasaría.
    $roja = KrkCategory::create(['name' => 'roja']);
    $azul = KrkCategory::create(['name' => 'azul']);

    $uno = KrkItem::create(['name' => 'uno', 'category_id' => $roja->id]);
    $dos = KrkItem::create(['name' => 'dos', 'category_id' => $azul->id]);
    KrkItem::create(['name' => 'tres', 'category_id' => $roja->id]);

    KrkNote::create(['item_id' => $uno->id, 'body' => 'uno-a']);
    KrkNote::create(['item_id' => $uno->id, 'body' => 'uno-b']);
    KrkNote::create(['item_id' => $dos->id, 'body' => 'dos-a']);

    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/krk-belongs-to', [KrkBelongsToController::class, 'index']);
        Route::get('api/krk-has-many', [KrkHasManyController::class, 'index']);
        Route::get('api/krk-with-count', [KrkWithCountController::class, 'index']);
        Route::get('api/krk-include', [KrkIncludeController::class, 'index']);
    });
});

afterEach(function () {
    $this->tearDownHttpApp();
});

/**
 * @return array<string, array<string, mixed>> filas indexadas por `name`
 */
function krkRows(object $test, string $uri): array
{
    $admin = KrkAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@test.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
    ]);
    $token = app(TokenIssuer::class)->issueAccessToken($admin)->plainTextToken;

    $response = $test->httpGet($uri, ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    expect($response->getStatusCode())->toBe(200);

    $rows = (array) (json_decode((string) $response->getContent(), true)['data'] ?? []);

    return array_column($rows, null, 'name');
}

test('🔴 ?cols=name con un BelongsTo en `with`: cada fila trae SU categoría', function () {
    $rows = krkRows($this, '/api/krk-belongs-to?cols=name');

    expect($rows)->toHaveCount(3)
        ->and($rows['uno']['category']['name'] ?? null)->toBe('roja')
        ->and($rows['dos']['category']['name'] ?? null)->toBe('azul')
        ->and($rows['tres']['category']['name'] ?? null)->toBe('roja');

    // `cols` se sigue respetando: sólo `name` más las claves que la relación
    // necesita (el PK y la FK). Nada del resto del modelo.
    expect(array_keys($rows['uno']))->toEqualCanonicalizing(['name', 'id', 'category_id', 'category']);
});

test('🔴 ?cols=name con un HasMany en `with`: cada fila trae SUS hijos', function () {
    $rows = krkRows($this, '/api/krk-has-many?cols=name');

    expect(array_column($rows['uno']['notes'] ?? [], 'body'))->toEqualCanonicalizing(['uno-a', 'uno-b'])
        ->and(array_column($rows['dos']['notes'] ?? [], 'body'))->toBe(['dos-a'])
        ->and($rows['tres']['notes'] ?? null)->toBe([]);
});

test('?cols=name con `withCount`: el conteo sale y es el correcto', function () {
    $rows = krkRows($this, '/api/krk-with-count?cols=name');

    expect($rows['uno']['notes_count'] ?? null)->toBe(2)
        ->and($rows['dos']['notes_count'] ?? null)->toBe(1)
        ->and($rows['tres']['notes_count'] ?? null)->toBe(0);
});

test('🔴 ?cols=name con `?include=`: las relaciones pedidas por el cliente también llegan', function () {
    $rows = krkRows($this, '/api/krk-include?cols=name&include=category,notes');

    expect($rows['uno']['category']['name'] ?? null)->toBe('roja')
        ->and($rows['dos']['category']['name'] ?? null)->toBe('azul')
        ->and(array_column($rows['uno']['notes'] ?? [], 'body'))->toEqualCanonicalizing(['uno-a', 'uno-b']);
});

test('sin `cols` el SELECT no se toca: sigue siendo `*`', function () {
    $query = KrkItem::query()->with(['category', 'notes']);

    ListManager::keepRelationKeys($query);

    expect($query->getQuery()->columns)->toBeNull();

    $rows = krkRows($this, '/api/krk-belongs-to');

    expect(array_keys($rows['uno']))->toEqualCanonicalizing(['id', 'name', 'category_id', 'category'])
        ->and($rows['uno']['category']['name'] ?? null)->toBe('roja');
});

test('con `cols` agrega sólo las claves que faltan, calificadas y sin duplicar', function () {
    $query = KrkItem::query()->select(['name', 'id'])->with(['category.missing' => fn () => null, 'notes']);

    // `category.missing` no existe en KrkCategory: sólo cuenta el primer
    // segmento, que es el que se resuelve sobre el padre.
    ListManager::keepRelationKeys($query);

    expect($query->getQuery()->columns)->toBe(['name', 'id', 'krk_items.category_id']);
});
