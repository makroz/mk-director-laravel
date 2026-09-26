<?php

declare(strict_types=1);

use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 73 (Mozzo, 2026-09-26): LA SESIÓN LARGA, medida por HTTP.
 *
 *  a) `MK_AUTH_REFRESH_ROTATE_ON_REFRESH=true` no rotaba: la config guarda un
 *     booleano y el `TokenIssuer` lo leía con `readConfigInt()`, que sólo
 *     acepta enteros. Sólo el entero `1` rotaba.
 *  b) El TTL y la rotación del refresh eran globales; ahora los declara el
 *     controller del scope (`refreshTtlSeconds()`, `rotatesRefreshTokens()`).
 *  c) Sin detección de reutilización: si el ladrón refrescaba primero, se
 *     quedaba con la sesión. Ahora un refresh ya rotado cierra la familia.
 *  d) `password/change` borraba el refresh de la PROPIA sesión: quien cambiaba
 *     la clave quedaba deslogueado cuando vencía su access.
 *  e) El login sin usuario no pagaba el bcrypt: oráculo por el reloj.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class RefreshSessionAdmin extends AuthUser
{
    protected $table = 'refresh_session_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class RefreshSessionAuthController extends BaseAuthController
{
    public static ?int $ttl = null;

    public static ?bool $rotate = null;

    protected function refreshTtlSeconds(): ?int
    {
        return self::$ttl;
    }

    protected function rotatesRefreshTokens(): ?bool
    {
        return self::$rotate;
    }

    protected function authModelClass(): string
    {
        return RefreshSessionAdmin::class;
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

/** Cuenta los `check()` del hasher; lo demás pasa derecho. */
final class RefreshSessionCountingHasher
{
    public int $checks = 0;

    public function __construct(private readonly object $inner) {}

    public function check(mixed ...$args): bool
    {
        $this->checks++;

        return $this->inner->check(...$args);
    }

    public function __call(string $method, array $args): mixed
    {
        return $this->inner->{$method}(...$args);
    }
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 26, 12, 0, 0));
    RefreshSessionAuthController::$ttl = null;
    RefreshSessionAuthController::$rotate = null;

    $this->bootHttpApp(RefreshSessionAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config(['mk_director.auth.refresh.rotate_on_refresh' => false]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('refresh_session_admins', function ($t) {
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
            $t->uuid('refresh_session_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [RefreshSessionAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [RefreshSessionAuthController::class, 'refresh']);
    });
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/auth/me', [RefreshSessionAuthController::class, 'me']);
        Route::post('api/admin/auth/password/change', [RefreshSessionAuthController::class, 'changePassword']);
    });

    $this->admin = RefreshSessionAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@refresh.local',
        'password' => Hash::make('secret-123'),
        'auth_scope' => 'admin',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    RefreshSessionAuthController::$ttl = null;
    RefreshSessionAuthController::$rotate = null;
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

/** @return array{access_token: string, refresh_token: string} */
function sessionLogin(object $test, string $password = 'secret-123'): array
{
    $response = $test->httpPost('/api/admin/auth/login', ['email' => 'admin@refresh.local', 'password' => $password]);
    expect($response->getStatusCode())->toBe(200, (string) $response->getContent());

    return json_decode((string) $response->getContent(), true)['data'];
}

/** @return array{0: int, 1: array<string, mixed>} */
function sessionRefresh(object $test, string $refreshToken): array
{
    $response = $test->httpPost('/api/admin/auth/refresh', ['refresh_token' => $refreshToken]);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

function sessionMe(object $test, string $accessToken): int
{
    return $test->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken])->getStatusCode();
}

// ─── a) el interruptor global, con los valores que escribe un .env ─────────

test('a) rotate_on_refresh rota con cualquier «verdadero»', function (mixed $value) {
    config(['mk_director.auth.refresh.rotate_on_refresh' => $value]);
    $tokens = sessionLogin($this);

    [$status, $body] = sessionRefresh($this, $tokens['refresh_token']);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data']['refresh_token'])->not->toBe($tokens['refresh_token']);
})->with([
    'bool true' => [true],
    'int 1' => [1],
    'string true' => ['true'],
    'string 1' => ['1'],
]);

test('a) rotate_on_refresh NO rota con un «falso»', function (mixed $value) {
    config(['mk_director.auth.refresh.rotate_on_refresh' => $value]);
    $tokens = sessionLogin($this);

    [$status, $body] = sessionRefresh($this, $tokens['refresh_token']);

    expect($status)->toBe(200);
    expect($body['data']['refresh_token'])->toBe($tokens['refresh_token']);
})->with([
    'bool false' => [false],
    'string false' => ['false'],
    'int 0' => [0],
    'null' => [null],
]);

// ─── b) por scope, con la global como default ───────────────────────────────

test('b) el scope prende la rotación aunque la global esté apagada, y la apaga aunque esté prendida', function () {
    RefreshSessionAuthController::$rotate = true;
    $tokens = sessionLogin($this);
    [, $body] = sessionRefresh($this, $tokens['refresh_token']);
    expect($body['data']['refresh_token'])->not->toBe($tokens['refresh_token']);

    config(['mk_director.auth.refresh.rotate_on_refresh' => true]);
    RefreshSessionAuthController::$rotate = false;
    $tokens = sessionLogin($this);
    [, $body] = sessionRefresh($this, $tokens['refresh_token']);
    expect($body['data']['refresh_token'])->toBe($tokens['refresh_token']);
});

test('b) el TTL del refresh es el del scope, en el login y en cada rotación; sin override, el global', function () {
    RefreshSessionAuthController::$ttl = 90 * 86400;
    RefreshSessionAuthController::$rotate = true;

    $tokens = sessionLogin($this);
    $expiresOf = fn (string $plain) => PersonalAccessToken::findToken($plain)->expires_at->getTimestamp();
    expect($expiresOf($tokens['refresh_token']))->toBe(now()->addDays(90)->getTimestamp());

    Carbon::setTestNow(now()->addDays(10));
    [, $body] = sessionRefresh($this, $tokens['refresh_token']);
    expect($expiresOf($body['data']['refresh_token']))->toBe(now()->addDays(90)->getTimestamp());

    // Control: sin override, los 7 días globales.
    RefreshSessionAuthController::$ttl = null;
    $tokens = sessionLogin($this);
    expect($expiresOf($tokens['refresh_token']))->toBe(now()->addDays(7)->getTimestamp());
});

// ─── c) reutilización ───────────────────────────────────────────────────────

test('🔴 c) un refresh ya rotado que vuelve: 401 y la sesión ENTERA muere (el refresh nuevo y su access también)', function () {
    RefreshSessionAuthController::$rotate = true;
    $old = sessionLogin($this);

    [$status, $body] = sessionRefresh($this, $old['refresh_token']);
    expect($status)->toBe(200);
    $new = $body['data'];
    expect(sessionMe($this, $new['access_token']))->toBe(200);

    // El replay del viejo.
    [$status, $body] = sessionRefresh($this, $old['refresh_token']);
    expect($status)->toBe(401);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_REFRESH_REUSED');

    [$status] = sessionRefresh($this, $new['refresh_token']);
    expect($status)->toBe(401);
    expect(sessionMe($this, $new['access_token']))->toBe(401);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('c) la revocación alcanza SÓLO a la familia: otra sesión del mismo usuario sigue viva', function () {
    RefreshSessionAuthController::$rotate = true;
    $stolen = sessionLogin($this);
    $other = sessionLogin($this);

    sessionRefresh($this, $stolen['refresh_token']);
    [$status] = sessionRefresh($this, $stolen['refresh_token']);
    expect($status)->toBe(401);

    [$status] = sessionRefresh($this, $other['refresh_token']);
    expect($status)->toBe(200);
});

test('c) CONTROL: la cadena normal de rotaciones no dispara nada', function () {
    RefreshSessionAuthController::$rotate = true;
    $tokens = sessionLogin($this);

    foreach (range(1, 3) as $_) {
        [$status, $body] = sessionRefresh($this, $tokens['refresh_token']);
        expect($status)->toBe(200, json_encode($body));
        $tokens = $body['data'];
    }

    expect(sessionMe($this, $tokens['access_token']))->toBe(200);
});

test('c) la lápida no sirve como Bearer', function () {
    RefreshSessionAuthController::$rotate = true;
    $old = sessionLogin($this);
    sessionRefresh($this, $old['refresh_token']);

    expect(sessionMe($this, $old['refresh_token']))->toBe(401);
});

test('c) un refresh anterior a las familias (sin ability de familia) rota y también detecta el replay', function () {
    RefreshSessionAuthController::$rotate = true;
    $legacy = app(TokenIssuer::class)->issueRefreshToken($this->admin);

    [$status, $body] = sessionRefresh($this, $legacy);
    expect($status)->toBe(200);
    $new = $body['data'];

    [$status] = sessionRefresh($this, $legacy);
    expect($status)->toBe(401);
    [$status] = sessionRefresh($this, $new['refresh_token']);
    expect($status)->toBe(401);
});

// ─── d) cambiar la clave no desloguea a quien la cambia ─────────────────────

test('🔴 d) password/change conserva la sesión propia (access Y refresh) y cierra las demás', function () {
    $mine = sessionLogin($this);
    $other = sessionLogin($this);

    $response = $this->httpPost('/api/admin/auth/password/change', [
        'current_password' => 'secret-123',
        'password' => 'new-secret-456',
        'password_confirmation' => 'new-secret-456',
    ], ['HTTP_AUTHORIZATION' => 'Bearer '.$mine['access_token']]);
    expect($response->getStatusCode())->toBe(200, (string) $response->getContent());

    expect(sessionMe($this, $mine['access_token']))->toBe(200);
    [$status] = sessionRefresh($this, $mine['refresh_token']);
    expect($status)->toBe(200);

    expect(sessionMe($this, $other['access_token']))->toBe(401);
    [$status] = sessionRefresh($this, $other['refresh_token']);
    expect($status)->toBe(401);
});

// ─── e) el login cuesta lo mismo exista o no el correo ──────────────────────

test('🔴 e) el hasher corre también cuando el correo no existe', function (string $email) {
    $counting = new RefreshSessionCountingHasher($this->httpApp['hash']);
    Hash::swap($counting);

    $response = $this->httpPost('/api/admin/auth/login', ['email' => $email, 'password' => 'wrong-password']);

    expect($response->getStatusCode())->toBe(422);
    expect($counting->checks)->toBe(1);
})->with([
    'correo inexistente' => ['nobody@refresh.local'],
    'CONTROL: correo existente, clave mala' => ['admin@refresh.local'],
]);
