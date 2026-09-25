<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 46: EL REFRESH LEÍA EL TOKEN CON LA CLASE BASE DE SANCTUM, NO CON EL
 * MODELO CONFIGURADO.
 *
 * `TokenIssuer::rotateRefreshToken()` hacía
 * `\Laravel\Sanctum\PersonalAccessToken::query()->find($tokenId)` a secas.
 * `Sanctum::usePersonalAccessTokenModel()` existe justamente para que el
 * consumidor cambie esa clase — NetPizza lo usa para que `tokenable` resuelva al
 * usuario SIN el scope de tenant— y el refresh se lo salteaba: hardcodeaba la
 * clase base, y su `tokenable` salía con el scope puesto.
 *
 * Medido en el piloto con `MK_TENANT_FAIL_CLOSED=true`: un refresh token recién
 * emitido y válido daba **401 «Refresh token not found.»**, que el front lee como
 * sesión vencida y desloguea. Con el flag en `false` andaba, así que nadie lo vio:
 * el refresh no tenía ni un test.
 *
 * ── 🔴 POR QUÉ LA ASERCIÓN VA AL REVÉS ───────────────────────────────────────
 *
 * Un test que configure un modelo propio y pida 200 **no mide nada**: la clase
 * base encuentra el mismo token y responde 200 igual, con el bug vivo. Lo único
 * que discrimina es un modelo configurado que NO VE la fila: si el refresh sigue
 * dando 200, es porque no está usando el modelo que se le dijo.
 *
 * Y el `1 = 0` no es un caprico del test: es la forma más pequeña de un global
 * scope, que es exactamente lo que el consumidor le pone a su modelo de token.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class RefreshModelAdmin extends AuthUser
{
    protected $table = 'refresh_model_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

/**
 * El modelo de token del consumidor. Cuando `$invisible` está prendido, ninguna
 * consulta encuentra nada — el equivalente mínimo de un global scope.
 *
 * El INSERT no lo toca un global scope, así que el login sigue emitiendo tokens
 * normalmente: lo único que cambia es quién puede LEERLOS.
 */
final class RefreshModelToken extends PersonalAccessToken
{
    public static bool $invisible = false;

    /** La clase base no declara `$table`, y Eloquent la inferiría del nombre nuevo. */
    protected $table = 'personal_access_tokens';

    protected static function booted(): void
    {
        self::addGlobalScope('refresh_model_invisible', function (Builder $query): void {
            if (self::$invisible) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}

final class RefreshModelAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return RefreshModelAdmin::class;
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
    RefreshModelToken::$invisible = false;

    $this->bootHttpApp(RefreshModelAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('refresh_model_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->unsignedTinyInteger('status')->default(ScopeStatus::Active->value);
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
            $t->uuid('refresh_model_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [RefreshModelAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [RefreshModelAuthController::class, 'refresh']);
    });

    RefreshModelAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@refresh.local',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
    ]);
});

afterEach(function () {
    // 🔴 El modelo de token es ESTADO GLOBAL de Sanctum. Sin restaurarlo, el
    // archivo de test siguiente resuelve sus tokens con esta clase.
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    RefreshModelToken::$invisible = false;

    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

function refreshModelLogin(object $test): array
{
    $response = $test->httpPost('/api/admin/auth/login', [
        'email' => 'admin@refresh.local',
        'password' => 'secret',
    ]);
    expect($response->getStatusCode())->toBe(200, (string) $response->getContent());

    return json_decode((string) $response->getContent(), true)['data'];
}

function refreshModelRefresh(object $test, string $refreshToken): int
{
    return $test->httpPost('/api/admin/auth/refresh', ['refresh_token' => $refreshToken])->getStatusCode();
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: el refresh usa el modelo de token CONFIGURADO, no la clase base', function () {
    Sanctum::usePersonalAccessTokenModel(RefreshModelToken::class);

    $tokens = refreshModelLogin($this);

    // Recién ahora se vuelve invisible: el login tiene que haber podido emitir.
    RefreshModelToken::$invisible = true;

    expect(refreshModelRefresh($this, $tokens['refresh_token']))->toBe(401);
});

/**
 * EL CONTROL. Sin esto, el 401 de arriba podría venir de cualquier cosa —la
 * ruta, el parseo del token, el harness— y el test pasaría por el motivo
 * equivocado.
 */
test('CONTROL: el mismo modelo configurado SIN esconder nada refresca normalmente', function () {
    Sanctum::usePersonalAccessTokenModel(RefreshModelToken::class);

    $tokens = refreshModelLogin($this);

    expect(refreshModelRefresh($this, $tokens['refresh_token']))->toBe(200);
});

test('CONTROL: sin modelo configurado, el refresh sigue andando con la clase base', function () {
    $tokens = refreshModelLogin($this);

    expect(refreshModelRefresh($this, $tokens['refresh_token']))->toBe(200);
});
