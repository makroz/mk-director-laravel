<?php

declare(strict_types=1);

use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\AccountStatus;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * [SECURITY] Qué token sirve para qué, medido con la cadena HTTP real
 * (login → `mk.auth` → refresh → logout), no llamando piezas sueltas.
 *
 * Los cuatro agujeros que cierra, medidos antes en el piloto NetPizza:
 *  - El REFRESH token (7 días) funcionaba como Bearer: `mk.auth` sólo miraba
 *    la ability `auth_scope:`, que el refresh también lleva.
 *  - Un ACCESS token mandado a `/auth/refresh` devolvía tokens nuevos: el TTL
 *    del access no significaba nada, se encadenaba para siempre.
 *  - Un usuario BLOQUEADO seguía refrescando y usando su access token:
 *    bloquearlo no cortaba las sesiones vivas.
 *  - Después de `logout` el refresh token de esa sesión seguía vivo.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class TokenChainAdmin extends AuthUser
{
    protected $table = 'token_chain_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class TokenChainAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return TokenChainAdmin::class;
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
    $this->bootHttpApp(TokenChainAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    // `$request->validate()` es un macro de FoundationServiceProvider; el
    // harness no lo registra y el login lo usa.
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('token_chain_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->unsignedTinyInteger('status')->default(ScopeStatus::Active->value);
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

    // `mk.auth` y el payload del login cargan roles/abilities.
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
            $t->uuid('token_chain_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    // Cableado de un consumer: login/refresh públicos, me/logout detrás de mk.auth.
    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [TokenChainAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [TokenChainAuthController::class, 'refresh']);
    });
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/auth/me', [TokenChainAuthController::class, 'me']);
        Route::post('api/admin/auth/logout', [TokenChainAuthController::class, 'logout']);
    });

    TokenChainAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@test.local',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
    ]);
});

afterEach(function () {
    // Estas pivots tienen `user_type` y `MorphPivot` cachea esa detección en un
    // estático: sin olvidarla, los archivos siguientes (pivots sin la columna)
    // escriben `user_type` y revientan.
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

/** @return array{access_token:string, refresh_token:string} */
function tokenChainLogin(object $test): array
{
    $response = $test->httpPost('/api/admin/auth/login', ['email' => 'admin@test.local', 'password' => 'secret']);
    expect($response->getStatusCode())->toBe(200, (string) $response->getContent());

    return json_decode((string) $response->getContent(), true)['data'];
}

/** @return array{0:int,1:array<string,mixed>} */
function tokenChainMe(object $test, string $bearer): array
{
    $response = $test->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer]);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

/** @return array{0:int,1:array<string,mixed>} */
function tokenChainRefresh(object $test, string $refreshToken): array
{
    $response = $test->httpPost('/api/admin/auth/refresh', ['refresh_token' => $refreshToken]);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

function tokenChainRowExists(string $plainToken): bool
{
    return PersonalAccessToken::query()->whereKey((int) explode('|', $plainToken, 2)[0])->exists();
}

// ─────────────────────────────────────────────────────────────────────────────

test('CONTRAPRUEBA: usuario activo — el access token entra y el refresh token real refresca', function () {
    $tokens = tokenChainLogin($this);

    [$status] = tokenChainMe($this, $tokens['access_token']);
    expect($status)->toBe(200);

    [$status, $body] = tokenChainRefresh($this, $tokens['refresh_token']);
    expect($status)->toBe(200);

    [$status] = tokenChainMe($this, $body['data']['access_token']);
    expect($status)->toBe(200);
});

test('🔴 A: el REFRESH token usado como Bearer → 401 ERR_UNAUTHENTICATED', function () {
    $tokens = tokenChainLogin($this);

    [$status, $body] = tokenChainMe($this, $tokens['refresh_token']);

    expect($status)->toBe(401);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_UNAUTHENTICATED');
});

test('🔴 B: un ACCESS token mandado a /auth/refresh → 401 y no emite tokens', function () {
    $tokens = tokenChainLogin($this);
    $before = PersonalAccessToken::query()->count();

    [$status, $body] = tokenChainRefresh($this, $tokens['access_token']);

    expect($status)->toBe(401);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_UNAUTHENTICATED');
    expect(PersonalAccessToken::query()->count())->toBe($before);
});

test('🔴 D: usuario BLOQUEADO — su access token vivo → 401 ERR_ACCOUNT_DISABLED', function () {
    $tokens = tokenChainLogin($this);
    TokenChainAdmin::query()->update(['status' => ScopeStatus::Blocked->value]);

    [$status, $body] = tokenChainMe($this, $tokens['access_token']);

    expect($status)->toBe(401);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_ACCOUNT_DISABLED');
});

test('🔴 D: usuario BLOQUEADO — refresh → 401 ERR_ACCOUNT_DISABLED y el refresh token queda revocado en la base', function () {
    $tokens = tokenChainLogin($this);
    TokenChainAdmin::query()->update(['status' => ScopeStatus::Blocked->value]);

    [$status, $body] = tokenChainRefresh($this, $tokens['refresh_token']);

    expect($status)->toBe(401);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_ACCOUNT_DISABLED');
    expect(tokenChainRowExists($tokens['refresh_token']))->toBeFalse();
});

test('🔴 logout revoca también el refresh token de ESA sesión (y no el de otra)', function () {
    $session = tokenChainLogin($this);
    $otherDevice = tokenChainLogin($this);

    $response = $this->httpPost('/api/admin/auth/logout', [], ['HTTP_AUTHORIZATION' => 'Bearer '.$session['access_token']]);
    expect($response->getStatusCode())->toBe(200, (string) $response->getContent());

    expect(tokenChainRowExists($session['refresh_token']))->toBeFalse();
    [$status] = tokenChainRefresh($this, $session['refresh_token']);
    expect($status)->toBe(401);

    expect(tokenChainRowExists($otherDevice['refresh_token']))->toBeTrue();
});

test('logout con el access token emitido por un refresh también revoca el refresh token vigente', function () {
    $session = tokenChainLogin($this);
    [, $body] = tokenChainRefresh($this, $session['refresh_token']);

    $response = $this->httpPost('/api/admin/auth/logout', [], ['HTTP_AUTHORIZATION' => 'Bearer '.$body['data']['access_token']]);
    expect($response->getStatusCode())->toBe(200);

    expect(tokenChainRowExists($body['data']['refresh_token']))->toBeFalse();
});

// ── El login de una cuenta bloqueada: el motivo, sólo con la contraseña buena ──

/** @return array{0:int,1:array<string,mixed>} */
function tokenChainLoginAttempt(object $test, string $password): array
{
    $response = $test->httpPost('/api/admin/auth/login', ['email' => 'admin@test.local', 'password' => $password]);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

test('🔴 E: usuario BLOQUEADO con la contraseña buena → 403 ERR_ACCOUNT_DISABLED con el motivo, sin tokens', function () {
    TokenChainAdmin::query()->update(['status' => ScopeStatus::Blocked->value]);

    [$status, $body] = tokenChainLoginAttempt($this, 'secret');

    expect($status)->toBe(403);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_ACCOUNT_DISABLED');
    expect($body['message'] ?? null)->toBe(AccountStatus::DEFAULT_DENIAL);
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('🔴 E: usuario BLOQUEADO con la contraseña MALA → el 422 genérico, igual que una cuenta que no existe', function () {
    TokenChainAdmin::query()->update(['status' => ScopeStatus::Blocked->value]);

    [$status, $body] = tokenChainLoginAttempt($this, 'otra');
    $statusInexistente = $this->httpPost('/api/admin/auth/login', ['email' => 'nadie@test.local', 'password' => 'otra'])->getStatusCode();

    expect($status)->toBe(422);
    expect($body['__extraData']['code'] ?? null)->toBe('ERR_VALIDATION');
    expect($body['message'] ?? null)->toBe('Credenciales inválidas.');
    expect($statusInexistente)->toBe(422);
});

test('🔴 E: el motivo lo pone el chequeo extra que negó, si declara denialMessage()', function () {
    config(['mk_director.auth.account_checks' => [new class
    {
        public function __invoke($user): bool
        {
            return false;
        }

        public function denialMessage(): string
        {
            return 'La empresa está suspendida.';
        }
    }]]);

    [$status, $body] = tokenChainLoginAttempt($this, 'secret');

    expect($status)->toBe(403);
    expect($body['message'] ?? null)->toBe('La empresa está suspendida.');
});

// ── Borrar la cuenta se lleva su acceso ──

test('🔴 borrar la cuenta se lleva sus tokens y sus roles', function () {
    tokenChainLogin($this);
    $admin = TokenChainAdmin::query()->firstOrFail();
    $rolId = (string) Str::uuid();
    DB::table('roles')->insert(['id' => $rolId, 'name' => 'editor', 'created_at' => now(), 'updated_at' => now()]);
    $admin->roles()->attach($rolId);

    // Contraprueba: con nada antes, el cero de después no mediría nada.
    expect(PersonalAccessToken::query()->count())->toBeGreaterThan(0)
        ->and($admin->roles()->count())->toBe(1);

    $admin->delete();

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(DB::table('role_user')->count())->toBe(0);
});
