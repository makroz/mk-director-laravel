<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Auth\Enums\TwoFactorPolicy;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TotpService;
use Mk\Director\Auth\Social\SocialIdentity;
use Mk\Director\Auth\Social\SocialProviderConfigResolver;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Login con Google y Apple por ID token, medido con la CADENA HTTP real
 * (Kernel + Router + Sanctum): lo que importa es que un token malo no saque
 * NINGUNA sesión, y que uno bueno saque la misma que el login con contraseña.
 *
 * Sin red: las claves RSA se generan acá y el JWKS de cada proveedor sale de
 * `Http::fake()`. Los tokens se firman con firebase/php-jwt, la misma
 * librería que los verifica — pero la verificación no puede «ponerse de
 * acuerdo» consigo misma: cada rechazo tiene su contraprueba que pasa.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

const SOCIAL_GOOGLE_JWKS = 'https://www.googleapis.com/oauth2/v3/certs';
const SOCIAL_APPLE_JWKS = 'https://appleid.apple.com/auth/keys';
const SOCIAL_GOOGLE_CLIENT = 'web-client.apps.googleusercontent.test';
const SOCIAL_APPLE_CLIENT = 'test.mozzo.app';

final class SocialDiner extends AuthUser
{
    protected $table = 'social_diners';

    protected $guarded = [];

    protected $casts = [
        'status' => ScopeStatus::class,
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'array',
        'two_factor_confirmed_at' => 'datetime',
        'two_factor_last_step' => 'integer',
    ];

    public function getAuthScope(): string
    {
        return 'customer';
    }
}

final class SocialAuthController extends BaseAuthController
{
    /** `null` = el default del paquete, que es lo que se mide sin tocarlo. */
    public static ?bool $autoLink = null;

    public static bool $creates = false;

    public static int $createCalls = 0;

    public static TwoFactorPolicy $policy = TwoFactorPolicy::Off;

    protected function twoFactorPolicy(): TwoFactorPolicy
    {
        return self::$policy;
    }

    protected function socialAutoLinkByEmail(): bool
    {
        return self::$autoLink ?? parent::socialAutoLinkByEmail();
    }

    protected function createSocialUser(Request $request, SocialIdentity $identity): ?Authenticatable
    {
        self::$createCalls++;

        if (! self::$creates) {
            return parent::createSocialUser($request, $identity);
        }

        return SocialDiner::create([
            'name' => $request->input('name', 'Comensal'),
            'email' => $identity->email,
            'password' => Hash::make(bin2hex(random_bytes(16))),
            'auth_scope' => 'customer',
        ]);
    }

    protected function authModelClass(): string
    {
        return SocialDiner::class;
    }

    protected function authScope(): string
    {
        return 'customer';
    }

    protected function loginField(): string
    {
        return 'email';
    }

    protected function passwordResetTable(): string
    {
        return 'customer_password_reset_tokens';
    }
}

// ─── claves de prueba ───────────────────────────────────────────────────────

/** @return array{private: string, jwk: array<string, string>} */
function socialKeyPair(string $kid): array
{
    static $pairs = [];

    return $pairs[$kid] ??= (function () use ($kid) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $private);
        $rsa = openssl_pkey_get_details($res)['rsa'];

        return [
            'private' => $private,
            'public' => openssl_pkey_get_details($res)['key'],
            'jwk' => [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => $kid,
                'n' => JWT::urlsafeB64Encode($rsa['n']),
                'e' => JWT::urlsafeB64Encode($rsa['e']),
            ],
        ];
    })();
}

/** @param  list<string>  $kids */
function socialJwks(array $kids): array
{
    return ['keys' => array_map(fn ($kid) => socialKeyPair($kid)['jwk'], $kids)];
}

/** Un ID token de Google válido; `$override` pisa o borra (null) claims. */
function googleToken(array $override = [], string $kid = 'g1', ?string $signWith = null): string
{
    $claims = array_filter(array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => SOCIAL_GOOGLE_CLIENT,
        'sub' => 'google-sub-1',
        'email' => 'diner@mozzo.test',
        'email_verified' => true,
        'iat' => now()->getTimestamp(),
        'exp' => now()->addHour()->getTimestamp(),
    ], $override), fn ($v) => $v !== null);

    return JWT::encode($claims, socialKeyPair($signWith ?? $kid)['private'], 'RS256', $kid);
}

function appleToken(array $override = [], string $kid = 'a1'): string
{
    $claims = array_filter(array_merge([
        'iss' => 'https://appleid.apple.com',
        'aud' => SOCIAL_APPLE_CLIENT,
        'sub' => '001234.apple-sub.0001',
        'email' => 'relay@privaterelay.appleid.test',
        'email_verified' => 'true',
        'is_private_email' => 'true',
        'iat' => now()->getTimestamp(),
        'exp' => now()->addMinutes(10)->getTimestamp(),
    ], $override), fn ($v) => $v !== null);

    return JWT::encode($claims, socialKeyPair($kid)['private'], 'RS256', $kid);
}

/** JWKS de cada proveedor: cada descarga devuelve el siguiente juego de `kid`s de la lista. */
function fakeJwks(array $google = [['g1']], array $apple = [['a1']]): void
{
    $seq = fn (array $sets) => array_map(
        fn ($kids) => Http::response(socialJwks($kids), 200, ['Cache-Control' => 'public, max-age=21600']),
        $sets,
    );

    // Agotada la lista, se repite la última: el proveedor sigue publicando.
    Http::fake([
        SOCIAL_GOOGLE_JWKS => Http::sequence($seq($google))->whenEmpty($seq([end($google)])[0]),
        SOCIAL_APPLE_JWKS => Http::sequence($seq($apple))->whenEmpty($seq([end($apple)])[0]),
    ]);
}

function jwksCalls(string $url): int
{
    return count(Http::recorded(fn ($request) => $request->url() === $url));
}

/** @return array{0:int, 1:array<string,mixed>} */
function socialPost(object $test, string $provider, array $body): array
{
    $response = $test->httpPost("/api/customer/auth/social/{$provider}", $body);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

function socialErr(array $body): ?string
{
    return $body['__extraData']['code'] ?? null;
}

function linkDiner(SocialDiner $diner, string $provider, string $subject): void
{
    DB::table('mk_social_identities')->insert([
        'auth_scope' => 'customer',
        'provider' => $provider,
        'subject' => $subject,
        'user_id' => (string) $diner->getKey(),
        'email' => $diner->email,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

// ─── setup ──────────────────────────────────────────────────────────────────

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 26, 12, 0, 0));

    SocialAuthController::$autoLink = null;
    SocialAuthController::$creates = false;
    SocialAuthController::$createCalls = 0;
    SocialAuthController::$policy = TwoFactorPolicy::Off;

    $this->bootHttpApp(SocialDiner::class, ['tenant' => ['enabled' => false]]);
    $this->httpApp->register(FoundationServiceProvider::class);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config(['auth.guards.customer' => ['driver' => 'sanctum', 'provider' => 'admins']]);
    config([
        'mk_director.auth.social.providers.google' => ['enabled' => true, 'client_ids' => 'android.apps.googleusercontent.test, '.SOCIAL_GOOGLE_CLIENT],
        'mk_director.auth.social.providers.apple' => ['enabled' => true, 'client_ids' => [SOCIAL_APPLE_CLIENT]],
    ]);
    Cache::flush();

    Schema::create('social_diners', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password');
        $t->string('auth_scope')->default('customer');
        $t->unsignedTinyInteger('status')->default(ScopeStatus::Active->value);
        $t->text('two_factor_secret')->nullable();
        $t->text('two_factor_recovery_codes')->nullable();
        $t->timestamp('two_factor_confirmed_at')->nullable();
        $t->unsignedBigInteger('two_factor_last_step')->nullable();
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
    Schema::create('verification_codes', function ($t) {
        $t->uuid('id')->primary();
        $t->string('auth_scope');
        $t->string('purpose');
        $t->string('identifier');
        $t->string('code_hash');
        $t->unsignedTinyInteger('attempts')->default(0);
        $t->unsignedTinyInteger('max_attempts');
        $t->timestamp('expires_at');
        $t->timestamp('consumed_at')->nullable();
        $t->timestamp('created_at')->nullable();
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
            $t->uuid('social_diner_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    // La tabla del vínculo sale de la MIGRACIÓN del paquete, no de una copia.
    (require dirname(__DIR__, 3).'/src/Auth/Database/Migrations/2026_09_26_000001_create_mk_social_identities_table.php')->up();

    Route::middleware(['api'])->group(function () {
        Route::post('api/customer/auth/social/{provider}', [SocialAuthController::class, 'socialLogin']);
        Route::post('api/customer/auth/two-factor/challenge', [SocialAuthController::class, 'confirmTwoFactorChallenge']);
    });
    Route::middleware(['api', 'mk.auth:customer'])->group(function () {
        Route::get('api/customer/auth/me', [SocialAuthController::class, 'me']);
    });

    $this->diner = SocialDiner::create([
        'name' => 'Comensal',
        'email' => 'diner@mozzo.test',
        'password' => Hash::make('secret'),
        'auth_scope' => 'customer',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

function expectNoSession(): void
{
    expect(PersonalAccessToken::query()->count())->toBe(0);
}

// ─── 1. Tokens válidos ──────────────────────────────────────────────────────

test('Google: una identidad YA vinculada entra con la misma sesión que el login con contraseña', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data'])->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in', 'customer']);
    expect($body['data']['customer']['id'])->toBe($this->diner->getKey());

    // El access token es una sesión real del scope.
    $me = $this->httpGet('/api/customer/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$body['data']['access_token']]);
    expect($me->getStatusCode())->toBe(200);
});

test('Apple con nonce: entra si el claim es el SHA-256 del nonce crudo, y no si no lo es', function () {
    fakeJwks();
    linkDiner($this->diner, 'apple', '001234.apple-sub.0001');
    $raw = 'nonce-crudo-del-cliente';

    [$status, $body] = socialPost($this, 'apple', [
        'id_token' => appleToken(['nonce' => hash('sha256', $raw)]),
        'nonce' => $raw,
    ]);
    expect($status)->toBe(200, json_encode($body));

    // El claim con el nonce CRUDO no alcanza: quien robó el token lo conoce.
    [$status, $body] = socialPost($this, 'apple', [
        'id_token' => appleToken(['nonce' => $raw]),
        'nonce' => $raw,
    ]);
    expect($status)->toBe(401);
    expect(socialErr($body))->toBe('ERR_SOCIAL_TOKEN_INVALID');

    // Y si el cliente manda nonce, el token tiene que traerlo.
    [$status] = socialPost($this, 'apple', ['id_token' => appleToken(), 'nonce' => $raw]);
    expect($status)->toBe(401);
});

// ─── 2. Tokens que NO entran — ninguno deja sesión ──────────────────────────

test('audiencia de OTRA app: 401', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken(['aud' => 'otra-app.apps.googleusercontent.test'])]);

    expect($status)->toBe(401);
    expect(socialErr($body))->toBe('ERR_SOCIAL_TOKEN_INVALID');
    expectNoSession();
});

test('emisor equivocado: 401 (un token de Google no pasa por Apple aunque la firma fuera buena)', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['iss' => 'https://evil.test'])]);
    expect($status)->toBe(401);

    // El emisor sin esquema también es de Google y entra.
    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['iss' => 'accounts.google.com'])]);
    expect($status)->toBe(200);
});

test('vencido: 401, y dentro de la tolerancia de reloj todavía entra', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['exp' => now()->subMinutes(5)->getTimestamp()])]);
    expect($status)->toBe(401);
    expectNoSession();

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['exp' => now()->subSeconds(30)->getTimestamp()])]);
    expect($status)->toBe(200);
});

test('sin exp o sin sub: 401 (un token eterno o sin identidad no es un token de login)', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['exp' => null])]);
    expect($status)->toBe(401);

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['sub' => null])]);
    expect($status)->toBe(401);

    expectNoSession();
});

test('firma de OTRA clave con el mismo kid: 401', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status] = socialPost($this, 'google', ['id_token' => googleToken([], 'g1', 'atacante')]);

    expect($status)->toBe(401);
    expectNoSession();
});

test('alg none y HS256 con la clave pública como secreto: 401', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');
    $claims = [
        'iss' => 'https://accounts.google.com', 'aud' => SOCIAL_GOOGLE_CLIENT, 'sub' => 'google-sub-1',
        'iat' => now()->getTimestamp(), 'exp' => now()->addHour()->getTimestamp(),
    ];

    // El motivo va al evento de auditoría, no a la respuesta. Se mira acá
    // porque firebase/php-jwt TAMBIÉN rechaza los dos: sin mirar el motivo,
    // sacar el chequeo propio de `alg` dejaría este test en verde.
    $reasons = [];
    Event::listen(AuthEvent::class, function (AuthEvent $event) use (&$reasons) {
        $reasons[] = $event->payload['detail'] ?? null;
    });

    $none = JWT::urlsafeB64Encode(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'g1']))
        .'.'.JWT::urlsafeB64Encode(json_encode($claims)).'.';
    [$status, $body] = socialPost($this, 'google', ['id_token' => $none]);
    expect($status)->toBe(401);
    expect(socialErr($body))->toBe('ERR_SOCIAL_TOKEN_INVALID');

    $hs = JWT::encode($claims, socialKeyPair('g1')['public'], 'HS256', 'g1');
    [$status] = socialPost($this, 'google', ['id_token' => $hs]);
    expect($status)->toBe(401);

    expect($reasons)->toBe(['alg no permitido: "none"', 'alg no permitido: "HS256"']);

    expectNoSession();
});

// ─── 3. JWKS: caché y rotación ──────────────────────────────────────────────

test('el JWKS se cachea: dos logins, UNA descarga', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    socialPost($this, 'google', ['id_token' => googleToken()]);
    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(200);
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(1);
});

test('la caché del JWKS dura lo que dice el max-age del proveedor (6 h en el fake), no más', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    socialPost($this, 'google', ['id_token' => googleToken()]);

    Carbon::setTestNow(now()->addHours(6)->subSecond());
    socialPost($this, 'google', ['id_token' => googleToken()]);
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(2));
    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(200);
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(2);
});

test('kid desconocido: se re-descarga el JWKS UNA vez (rotación), y una ráfaga de kids inventados no multiplica descargas', function () {
    // Primera descarga: sólo g1. La segunda (tras la rotación): g1 y g2.
    fakeJwks(google: [['g1'], ['g1', 'g2']]);
    linkDiner($this->diner, 'google', 'google-sub-1');

    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(200);
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(1);

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken([], 'g2')]);
    expect($status)->toBe(200, json_encode($body));
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(2);

    // Dentro del freno, un kid que no existe ya no baja nada.
    [$status] = socialPost($this, 'google', ['id_token' => googleToken([], 'inventado')]);
    [$status2] = socialPost($this, 'google', ['id_token' => googleToken([], 'otro-inventado')]);
    expect([$status, $status2])->toBe([401, 401]);
    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(2);
});

test('sin claves del proveedor: 503, no 401 (no es culpa del cliente)', function () {
    Http::fake([SOCIAL_GOOGLE_JWKS => Http::response('caído', 500)]);

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(503);
    expect(socialErr($body))->toBe('ERR_SOCIAL_UNAVAILABLE');
});

// ─── 4. Proveedor apagado ───────────────────────────────────────────────────

test('proveedor apagado, sin client ids o desconocido: 403 SIN mirar el token ni bajar claves', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    config(['mk_director.auth.social.providers.google.enabled' => false]);
    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(403);
    expect(socialErr($body))->toBe('ERR_SOCIAL_PROVIDER_DISABLED');

    config(['mk_director.auth.social.providers.google' => ['enabled' => true, 'client_ids' => '']]);
    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(403);

    [$status] = socialPost($this, 'facebook', ['id_token' => googleToken()]);
    expect($status)->toBe(403);

    expect(jwksCalls(SOCIAL_GOOGLE_JWKS))->toBe(0);
    expectNoSession();
});

test('el resolver del CONSUMER manda sobre la config, y se consulta en cada login', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');

    // Como un consumer que guarda los client ids en su base y los apaga desde una consola.
    $resolver = new class implements SocialProviderConfigResolver
    {
        public array $ids = [];

        public function clientIds(string $scope, string $provider): array
        {
            return $scope === 'customer' ? $this->ids : [];
        }
    };
    $this->httpApp->instance(SocialProviderConfigResolver::class, $resolver);

    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(403);

    $resolver->ids = [SOCIAL_GOOGLE_CLIENT];
    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(200);
});

// ─── 5. Vincular o crear ────────────────────────────────────────────────────

test('identidad nueva sin hook de alta: 404, y no se vincula a la cuenta con el mismo email', function () {
    fakeJwks();

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(404);
    expect(socialErr($body))->toBe('ERR_SOCIAL_ACCOUNT_NOT_FOUND');
    expect(SocialAuthController::$createCalls)->toBe(1);
    expect(DB::table('mk_social_identities')->count())->toBe(0);
    expectNoSession();
});

test('🔴 auto-vínculo por email prendido: un email NO verificado no se vincula a la cuenta existente', function () {
    fakeJwks();
    SocialAuthController::$autoLink = true;

    [$status] = socialPost($this, 'google', ['id_token' => googleToken(['email_verified' => false])]);

    expect($status)->toBe(404);
    expect(DB::table('mk_social_identities')->count())->toBe(0);
    expectNoSession();

    // Contraprueba: el mismo email, verificado, sí se vincula y entra.
    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);
    expect($status)->toBe(200, json_encode($body));
    expect($body['data']['customer']['id'])->toBe($this->diner->getKey());
    expect(DB::table('mk_social_identities')->value('user_id'))->toBe($this->diner->getKey());
});

test('auto-vínculo APAGADO (default): ni con email verificado se toma la cuenta existente', function () {
    fakeJwks();

    [$status] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(404);
    expect(DB::table('mk_social_identities')->count())->toBe(0);
});

test('identidad nueva pasa por el hook de alta, queda vinculada, y el segundo login ya no lo llama', function () {
    fakeJwks();
    SocialAuthController::$creates = true;

    [$status, $body] = socialPost($this, 'apple', ['id_token' => appleToken(), 'name' => 'Ana']);

    expect($status)->toBe(200, json_encode($body));
    $created = SocialDiner::query()->where('email', 'relay@privaterelay.appleid.test')->firstOrFail();
    expect($created->name)->toBe('Ana');
    expect($body['data']['customer']['id'])->toBe($created->getKey());
    expect(DB::table('mk_social_identities')->where('subject', '001234.apple-sub.0001')->value('user_id'))->toBe($created->getKey());

    [$status] = socialPost($this, 'apple', ['id_token' => appleToken()]);
    expect($status)->toBe(200);
    expect(SocialAuthController::$createCalls)->toBe(1);
    expect(SocialDiner::query()->count())->toBe(2);
});

// ─── 6. Lo que sigue siendo del login ───────────────────────────────────────

test('cuenta bloqueada: 403 ERR_ACCOUNT_DISABLED aunque el token sea bueno', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');
    $this->diner->forceFill(['status' => ScopeStatus::Blocked])->save();

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(403);
    expect(socialErr($body))->toBe('ERR_ACCOUNT_DISABLED');
    expectNoSession();
});

test('🔴 el segundo factor del scope NO se saltea entrando con Google', function () {
    fakeJwks();
    linkDiner($this->diner, 'google', 'google-sub-1');
    SocialAuthController::$policy = TwoFactorPolicy::Optional;

    $totp = new TotpService;
    $this->diner->forceFill([
        'two_factor_secret' => $totp->generateSecret(),
        'two_factor_recovery_codes' => [],
        'two_factor_confirmed_at' => now(),
    ])->save();

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken()]);

    expect($status)->toBe(200);
    expect($body['data'])->not->toHaveKey('access_token');
    expect($body['data']['two_factor'])->toBe('challenge');
    expectNoSession();
});

// ─── 7. Restos del hallazgo 72 ──────────────────────────────────────────────

test('auto-vínculo: el email del proveedor se compara SIN mayúsculas contra el guardado', function () {
    fakeJwks();
    SocialAuthController::$autoLink = true;

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken(['email' => 'Diner@Mozzo.TEST'])]);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data']['customer']['id'])->toBe($this->diner->getKey());
});

test('el 401 del token social está en castellano neutro', function () {
    fakeJwks();

    [$status, $body] = socialPost($this, 'google', ['id_token' => googleToken(['aud' => 'otra-app.apps.googleusercontent.test'])]);

    expect($status)->toBe(401);
    expect($body['message'])->toBe('El acceso no es válido.');
});

test('borrar de verdad la cuenta se lleva su vínculo social, y sólo el suyo', function () {
    $other = SocialDiner::create([
        'name' => 'Otro',
        'email' => 'other@mozzo.test',
        'password' => Hash::make('secret'),
        'auth_scope' => 'customer',
    ]);
    linkDiner($this->diner, 'google', 'google-sub-1');
    linkDiner($other, 'google', 'google-sub-2');

    $this->diner->delete();

    expect(DB::table('mk_social_identities')->pluck('subject')->all())->toBe(['google-sub-2']);
});
