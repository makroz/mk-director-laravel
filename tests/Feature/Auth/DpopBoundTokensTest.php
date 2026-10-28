<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\DpopFailure;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Exceptions\DpopProofException;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\DpopProofVerifier;
use Mk\Director\Auth\Services\TokenIssuer;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\DpopTestKey;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * DPoP (DEVELOPER_GUIDE § 3.24): tokens atados a la clave del cliente.
 *
 * Se mide el EFECTO: qué abilities quedaron en `personal_access_tokens`, si el
 * refresh sigue sirviendo después de un intento del ladrón, cuántos tokens
 * quedan tras la reutilización. El código HTTP solo no alcanza.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class DpopAdmin extends AuthUser
{
    protected $table = 'dpop_admins';

    protected $guarded = [];

    protected $casts = ['status' => ScopeStatus::class];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

final class DpopAuthController extends BaseAuthController
{
    public static ?bool $rotate = null;

    protected function rotatesRefreshTokens(): ?bool
    {
        return self::$rotate;
    }

    protected function authModelClass(): string
    {
        return DpopAdmin::class;
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

const DPOP_BASE = 'http://localhost';

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 10, 28, 12, 0, 0));
    DpopAuthController::$rotate = null;

    $this->bootHttpApp(DpopAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config(['mk_director.auth.refresh.rotate_on_refresh' => false]);
    config(['mk_director.auth.dpop' => ['scopes' => [], 'max_age_seconds' => 60, 'cache_store' => null]]);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('dpop_admins', function ($t) {
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
            $t->uuid('dpop_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [DpopAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [DpopAuthController::class, 'refresh']);
    });
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/auth/me', [DpopAuthController::class, 'me']);
    });

    DpopAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@dpop.local',
        'password' => Hash::make('secret-123'),
        'auth_scope' => 'admin',
    ]);

    $this->key = new DpopTestKey;
});

afterEach(function () {
    Carbon::setTestNow();
    DpopAuthController::$rotate = null;
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

function dpopRequireAdmin(): void
{
    config(['mk_director.auth.dpop.scopes' => ['admin']]);
}

/** @return array{0: int, 1: array<string, mixed>} */
function dpopLogin(object $test, ?string $proof = null): array
{
    $response = $test->httpPost('/api/admin/auth/login', ['email' => 'admin@dpop.local', 'password' => 'secret-123'],
        $proof !== null ? ['HTTP_DPOP' => $proof] : []);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

/** @return array{0: int, 1: array<string, mixed>} */
function dpopRefresh(object $test, string $refreshToken, ?string $proof = null): array
{
    $response = $test->httpPost('/api/admin/auth/refresh', ['refresh_token' => $refreshToken],
        $proof !== null ? ['HTTP_DPOP' => $proof] : []);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

/** @return array{0: int, 1: array<string, mixed>} */
function dpopMe(object $test, string $accessToken, ?string $proof = null): array
{
    $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken];
    if ($proof !== null) {
        $headers['HTTP_DPOP'] = $proof;
    }
    $response = $test->httpGet('/api/admin/auth/me', $headers);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

/** @return list<string> */
function dpopAbilitiesOf(string $plainToken): array
{
    return array_values((array) PersonalAccessToken::findToken($plainToken)?->abilities);
}

function dpopLoginProof(DpopTestKey $key): string
{
    return $key->proof('POST', DPOP_BASE.'/api/admin/auth/login');
}

function dpopRefreshProof(DpopTestKey $key): string
{
    return $key->proof('POST', DPOP_BASE.'/api/admin/auth/refresh');
}

function dpopMeProof(DpopTestKey $key, string $accessToken): string
{
    return $key->proof('GET', DPOP_BASE.'/api/admin/auth/me', $accessToken);
}

/** Corre el verificador sobre un pedido armado a mano; devuelve la huella o el motivo del rechazo. */
function dpopVerify(
    string|array|null $proof,
    string $method = 'POST',
    string $uri = '/api/admin/auth/login',
    ?string $accessToken = null,
    ?string $expectedJkt = null,
): string|DpopFailure {
    $request = Request::create($uri, $method);
    if ($proof !== null) {
        $request->headers->set('DPoP', $proof);
    }

    try {
        return app(DpopProofVerifier::class)->verify($request, $accessToken, $expectedJkt);
    } catch (DpopProofException $e) {
        return $e->reason;
    }
}

// ─── El verificador, rechazo por rechazo ────────────────────────────────────

test('verificador: una prueba buena devuelve la huella RFC 7638 de su clave', function () {
    expect(dpopVerify(dpopLoginProof($this->key)))->toBe($this->key->thumbprint());
});

test('verificador: el htu se compara sólo por la ruta (otro esquema, host y query valen)', function () {
    $proof = $this->key->proof('POST', 'https://api.example.com/api/admin/auth/login?x=1#frag');

    expect(dpopVerify($proof))->toBe($this->key->thumbprint());
});

test('verificador: sin header → Missing; dos headers → Multiple', function () {
    expect(dpopVerify(null))->toBe(DpopFailure::Missing);
    expect(dpopVerify([dpopLoginProof($this->key), dpopLoginProof($this->key)]))->toBe(DpopFailure::Multiple);
});

test('verificador: typ distinto de dpop+jwt → Type', function () {
    expect(dpopVerify($this->key->proof('POST', DPOP_BASE.'/api/admin/auth/login', header: ['typ' => 'JWT'])))
        ->toBe(DpopFailure::Type);
});

test('verificador: alg HS256 y alg none → Algorithm', function () {
    $claims = ['htm' => 'POST', 'htu' => DPOP_BASE.'/api/admin/auth/login', 'iat' => now()->getTimestamp(), 'jti' => 'j-1'];
    $hs256 = JWT::encode($claims, str_repeat('k', 32), 'HS256', null, ['typ' => 'dpop+jwt', 'jwk' => $this->key->jwk]);
    $none = JWT::urlsafeB64Encode(json_encode(['typ' => 'dpop+jwt', 'alg' => 'none', 'jwk' => $this->key->jwk]))
        .'.'.JWT::urlsafeB64Encode(json_encode($claims)).'.';

    expect(dpopVerify($hs256))->toBe(DpopFailure::Algorithm);
    expect(dpopVerify($none))->toBe(DpopFailure::Algorithm);
});

test('verificador: una jwk con la parte privada (d) → PrivateKey', function () {
    $proof = $this->key->proof('POST', DPOP_BASE.'/api/admin/auth/login', header: ['jwk' => $this->key->jwk + ['d' => 'AAAA']]);

    expect(dpopVerify($proof))->toBe(DpopFailure::PrivateKey);
});

test('verificador: una jwk que no es EC P-256 → Key', function () {
    $proof = $this->key->proof('POST', DPOP_BASE.'/api/admin/auth/login', header: ['jwk' => ['kty' => 'RSA', 'n' => 'AQAB', 'e' => 'AQAB']]);

    expect(dpopVerify($proof))->toBe(DpopFailure::Key);
});

test('verificador: firmada con OTRA clave que la del header → Signature', function () {
    $other = new DpopTestKey;
    $proof = $other->proof('POST', DPOP_BASE.'/api/admin/auth/login', header: ['jwk' => $this->key->jwk]);

    expect(dpopVerify($proof))->toBe(DpopFailure::Signature);
});

test('verificador: htm distinto del método → Method', function () {
    expect(dpopVerify($this->key->proof('GET', DPOP_BASE.'/api/admin/auth/login')))->toBe(DpopFailure::Method);
});

test('verificador: la ruta del htu distinta → Path', function () {
    expect(dpopVerify($this->key->proof('POST', DPOP_BASE.'/api/admin/auth/refresh')))->toBe(DpopFailure::Path);
});

test('verificador: iat fuera de la ventana, viejo o futuro → IssuedAt; dentro, en los dos sentidos, vale', function () {
    $proofAt = fn (int $offset) => $this->key->proof('POST', DPOP_BASE.'/api/admin/auth/login', claims: ['iat' => now()->getTimestamp() + $offset]);

    expect(dpopVerify($proofAt(-61)))->toBe(DpopFailure::IssuedAt);
    expect(dpopVerify($proofAt(61)))->toBe(DpopFailure::IssuedAt);
    expect(dpopVerify($proofAt(-60)))->toBe($this->key->thumbprint());
    expect(dpopVerify($proofAt(60)))->toBe($this->key->thumbprint());
});

test('verificador: la misma prueba dos veces → Replayed', function () {
    $proof = dpopLoginProof($this->key);

    expect(dpopVerify($proof))->toBe($this->key->thumbprint());
    expect(dpopVerify($proof))->toBe(DpopFailure::Replayed);
});

test('verificador: con access token, ath ausente o de otro token → AccessTokenHash', function () {
    $url = DPOP_BASE.'/api/admin/auth/me';

    expect(dpopVerify($this->key->proof('GET', $url, 'otro-token'), 'GET', '/api/admin/auth/me', 'el-token'))
        ->toBe(DpopFailure::AccessTokenHash);
    expect(dpopVerify($this->key->proof('GET', $url), 'GET', '/api/admin/auth/me', 'el-token'))
        ->toBe(DpopFailure::AccessTokenHash);
    expect(dpopVerify($this->key->proof('GET', $url, 'el-token'), 'GET', '/api/admin/auth/me', 'el-token'))
        ->toBe($this->key->thumbprint());
});

test('verificador: una prueba buena de otra clave que la atada → KeyMismatch', function () {
    expect(dpopVerify(dpopLoginProof($this->key), expectedJkt: (new DpopTestKey)->thumbprint()))
        ->toBe(DpopFailure::KeyMismatch);
});

// ─── Compatibilidad: sin scopes y sin pruebas, todo igual ───────────────────

test('BC: con scopes vacío y sin pruebas, login, me y refresh andan como antes y nada sale atado', function () {
    [$status, $body] = dpopLogin($this);
    expect($status)->toBe(200, json_encode($body));
    $tokens = $body['data'];

    expect(TokenIssuer::dpopJktOf(dpopAbilitiesOf($tokens['access_token'])))->toBeNull();
    expect(TokenIssuer::dpopJktOf(dpopAbilitiesOf($tokens['refresh_token'])))->toBeNull();
    expect(dpopMe($this, $tokens['access_token'])[0])->toBe(200);

    [$status, $body] = dpopRefresh($this, $tokens['refresh_token']);
    expect($status)->toBe(200, json_encode($body));
    expect(dpopMe($this, $body['data']['access_token'])[0])->toBe(200);
});

// ─── Emisión ────────────────────────────────────────────────────────────────

test('🔴 login: el scope exige DPoP y no vino prueba → 401 ERR_DPOP_REQUIRED y no se emite ningún token', function () {
    dpopRequireAdmin();

    [$status, $body] = dpopLogin($this);

    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_REQUIRED');
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('login: una prueba inválida → 401 ERR_DPOP_INVALID y no se emite ningún token', function () {
    dpopRequireAdmin();

    [$status, $body] = dpopLogin($this, $this->key->proof('POST', DPOP_BASE.'/api/admin/auth/me'));

    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_INVALID');
    expect($body['__extraData']['reason'])->toBe('htu');
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('login: un iat fuera de la ventana devuelve la hora del servidor para corregir el reloj', function () {
    dpopRequireAdmin();

    [$status, $body] = dpopLogin($this, $this->key->proof('POST', DPOP_BASE.'/api/admin/auth/login', claims: ['iat' => now()->getTimestamp() - 600]));

    expect($status)->toBe(401);
    expect($body['__extraData']['reason'])->toBe('iat');
    expect($body['__extraData']['server_time'])->toBe(now()->getTimestamp());
});

test('🔴 login con prueba: el access Y el refresh quedan atados a la huella de la clave', function () {
    dpopRequireAdmin();

    [$status, $body] = dpopLogin($this, dpopLoginProof($this->key));

    expect($status)->toBe(200, json_encode($body));
    expect(dpopAbilitiesOf($body['data']['access_token']))->toContain('dpop_jkt:'.$this->key->thumbprint());
    expect(dpopAbilitiesOf($body['data']['refresh_token']))->toContain('dpop_jkt:'.$this->key->thumbprint());
});

test('login con prueba en un scope que NO la exige: igual sale atado (el cliente la pidió)', function () {
    [$status, $body] = dpopLogin($this, dpopLoginProof($this->key));

    expect($status)->toBe(200);
    expect(dpopAbilitiesOf($body['data']['access_token']))->toContain('dpop_jkt:'.$this->key->thumbprint());
});

// ─── mk.auth ────────────────────────────────────────────────────────────────

test('🔴 mk.auth: un token atado sin prueba, con prueba de otra clave o con otro ath → 401; con la suya → 200', function () {
    [, $body] = dpopLogin($this, dpopLoginProof($this->key));
    $access = $body['data']['access_token'];

    [$status, $body] = dpopMe($this, $access);
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_REQUIRED');

    [$status, $body] = dpopMe($this, $access, dpopMeProof(new DpopTestKey, $access));
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_INVALID');
    expect($body['__extraData']['reason'])->toBe('key_mismatch');

    [$status, $body] = dpopMe($this, $access, dpopMeProof($this->key, 'otro-token'));
    expect($status)->toBe(401);
    expect($body['__extraData']['reason'])->toBe('ath');

    [$status, $body] = dpopMe($this, $access, dpopMeProof($this->key, $access));
    expect($status)->toBe(200, json_encode($body));
});

test('mk.auth: el token atado exige la prueba aunque su scope NO esté en la config (la exigencia sale del token)', function () {
    dpopRequireAdmin();
    [, $body] = dpopLogin($this, dpopLoginProof($this->key));
    config(['mk_director.auth.dpop.scopes' => []]);

    expect(dpopMe($this, $body['data']['access_token'])[0])->toBe(401);
});

test('🔴 el scope pasa a exigir DPoP: un token suelto de antes → 401 ERR_DPOP_UNBOUND en mk.auth y en el refresh', function () {
    [, $body] = dpopLogin($this);
    $tokens = $body['data'];
    dpopRequireAdmin();

    [$status, $body] = dpopMe($this, $tokens['access_token']);
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_UNBOUND');

    [$status, $body] = dpopRefresh($this, $tokens['refresh_token'], dpopRefreshProof($this->key));
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_UNBOUND');
});

// ─── Refresh ────────────────────────────────────────────────────────────────

test('🔴 refresh: el ladrón (otra clave, o sin prueba) recibe 401 y el refresh del dueño sigue intacto', function () {
    DpopAuthController::$rotate = true;
    [, $body] = dpopLogin($this, dpopLoginProof($this->key));
    $refresh = $body['data']['refresh_token'];
    $before = dpopAbilitiesOf($refresh);

    [$status, $body] = dpopRefresh($this, $refresh, dpopRefreshProof(new DpopTestKey));
    expect($status)->toBe(401);
    expect($body['__extraData']['reason'])->toBe('key_mismatch');

    [$status, $body] = dpopRefresh($this, $refresh);
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_DPOP_REQUIRED');

    // Ni lápida ni revocación: el token está igual y el dueño refresca.
    expect(dpopAbilitiesOf($refresh))->toBe($before);
    [$status, $body] = dpopRefresh($this, $refresh, dpopRefreshProof($this->key));
    expect($status)->toBe(200, json_encode($body));
});

test('refresh con la clave correcta: el par nuevo hereda la misma huella', function () {
    DpopAuthController::$rotate = true;
    [, $body] = dpopLogin($this, dpopLoginProof($this->key));

    [$status, $body] = dpopRefresh($this, $body['data']['refresh_token'], dpopRefreshProof($this->key));

    expect($status)->toBe(200, json_encode($body));
    $jkt = 'dpop_jkt:'.$this->key->thumbprint();
    expect(dpopAbilitiesOf($body['data']['access_token']))->toContain($jkt);
    expect(dpopAbilitiesOf($body['data']['refresh_token']))->toContain($jkt);
    expect(dpopMe($this, $body['data']['access_token'], dpopMeProof($this->key, $body['data']['access_token']))[0])->toBe(200);
});

test('🔴 refresh con rotación: el viejo reusado CON la clave revoca la familia; sin la clave no toca nada', function () {
    DpopAuthController::$rotate = true;
    [, $body] = dpopLogin($this, dpopLoginProof($this->key));
    $old = $body['data']['refresh_token'];

    [$status, $body] = dpopRefresh($this, $old, dpopRefreshProof($this->key));
    expect($status)->toBe(200);
    $new = $body['data'];
    $tokensAlive = PersonalAccessToken::query()->count();

    // La lápida presentada por quien no tiene la clave: 401 sin revocar.
    [$status] = dpopRefresh($this, $old, dpopRefreshProof(new DpopTestKey));
    expect($status)->toBe(401);
    expect(PersonalAccessToken::query()->count())->toBe($tokensAlive);

    // La lápida con la clave (comportamiento de siempre): se cierra la sesión entera.
    [$status, $body] = dpopRefresh($this, $old, dpopRefreshProof($this->key));
    expect($status)->toBe(401);
    expect($body['__extraData']['code'])->toBe('ERR_REFRESH_REUSED');
    expect(PersonalAccessToken::query()->count())->toBe(0);
    expect(dpopRefresh($this, $new['refresh_token'], dpopRefreshProof($this->key))[0])->toBe(401);
});
