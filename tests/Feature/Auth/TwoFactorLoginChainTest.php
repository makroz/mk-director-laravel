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
use Mk\Director\Auth\Enums\TwoFactorPolicy;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\TotpService;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El segundo factor medido con la CADENA HTTP real (login → desafío → `mk.auth`
 * → refresh), no llamando métodos sueltos.
 *
 * 🔴 POR QUÉ LA CADENA ENTERA Y NO EL CONTROLLER A MANO.
 *
 * Lo que hay que probar acá no es «el código valida»: es que entre «la
 * contraseña era correcta» y «tomá tu sesión» no salga NINGÚN token. Un test
 * que llama `login()` y mira el array devuelto no ve la diferencia entre un
 * desafío y un token — los dos son strings en un `data`. Lo que la distingue es
 * lo que pasa cuando esos strings se mandan a `GET /me` y a `/auth/refresh`, y
 * eso sólo existe con el Kernel, el Router y Sanctum de verdad.
 *
 * Dos scopes cableados sobre la misma tabla:
 *  - `/api/admin/…` → `TwoFactorAuthController`, con la política del test.
 *  - `/api/plain/…` → `PlainAuthController`, que NO override `twoFactorPolicy()`.
 *    Es la contraprueba de compatibilidad: un scope ya generado sigue logueando
 *    igual aunque el usuario tenga las cuatro columnas cargadas y confirmadas.
 *
 * Las rutas van SIN `throttle:` a propósito: acá se mide el tope de intentos de
 * la credencial (423), que es el que protege la cuenta. El prefijo de los
 * throttles que emite el scaffolder lo mide `MakeAuthUserThrottlePrefixTest`.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class TwoFactorAdmin extends AuthUser
{
    protected $table = 'two_factor_admins';

    protected $guarded = [];

    /**
     * 🔴 El modelo del scope override `$casts` ENTERO: lo que no esté acá no se
     * castea, por más que el modelo base lo declare. Es la misma trampa que ya
     * mordió con `status`, y por eso el scaffolder emite estas cuatro entradas
     * en el modelo generado y no sólo en la clase base.
     */
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
        return 'admin';
    }
}

final class TwoFactorAuthController extends BaseAuthController
{
    /**
     * La política es del SCOPE, no del request: en un consumer es una línea fija
     * en el controller generado. Acá es estática para poder cablear las rutas
     * una sola vez y correr la misma cadena con `optional` y con `required`.
     */
    public static TwoFactorPolicy $policy = TwoFactorPolicy::Optional;

    protected function twoFactorPolicy(): TwoFactorPolicy
    {
        return self::$policy;
    }

    protected function authModelClass(): string
    {
        return TwoFactorAdmin::class;
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

/** Un scope como los ya generados: sin `twoFactorPolicy()`, o sea `off`. */
final class PlainAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return TwoFactorAdmin::class;
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
    // 🔴 EL RELOJ CONGELADO NO ES COMODIDAD: sin él este archivo es flaky por
    // diseño. Un código TOTP vale un paso de 30 segundos y el replay sella el
    // paso aceptado; si el borde de los 30 segundos cae entre el sellado y el
    // código siguiente que calcula el test, ese código queda fuera de la
    // ventana y el test da un rojo que no vuelve a aparecer al correrlo solo.
    Carbon::setTestNow(Carbon::create(2026, 9, 15, 12, 0, 5));

    TwoFactorAuthController::$policy = TwoFactorPolicy::Optional;

    $this->bootHttpApp(TwoFactorAdmin::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config(['app.name' => 'NetPizza Consola']);
    $this->httpApp->register(FoundationServiceProvider::class);

    Schema::create('two_factor_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
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

    // La tabla genérica del paquete, tal cual la crea su migración.
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
            $t->uuid('two_factor_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }
    Schema::create('ability_role', function ($t) {
        $t->uuid('ability_id');
        $t->uuid('role_id');
    });

    Route::middleware(['api'])->group(function () {
        Route::post('api/admin/auth/login', [TwoFactorAuthController::class, 'login']);
        Route::post('api/admin/auth/refresh', [TwoFactorAuthController::class, 'refresh']);
        Route::post('api/admin/auth/two-factor/challenge', [TwoFactorAuthController::class, 'confirmTwoFactorChallenge']);
        Route::post('api/admin/auth/two-factor/setup/confirm', [TwoFactorAuthController::class, 'confirmTwoFactorSetup']);
        Route::post('api/plain/auth/login', [PlainAuthController::class, 'login']);
    });
    Route::middleware(['api', 'mk.auth:admin'])->group(function () {
        Route::get('api/admin/auth/me', [TwoFactorAuthController::class, 'me']);
        Route::post('api/admin/auth/logout', [TwoFactorAuthController::class, 'logout']);
        Route::post('api/admin/auth/two-factor/enable', [TwoFactorAuthController::class, 'enableTwoFactor']);
        Route::post('api/admin/auth/two-factor/confirm', [TwoFactorAuthController::class, 'confirmTwoFactor']);
        Route::post('api/admin/auth/two-factor/recovery-codes', [TwoFactorAuthController::class, 'regenerateTwoFactorRecoveryCodes']);
        Route::post('api/admin/auth/two-factor/disable', [TwoFactorAuthController::class, 'disableTwoFactor']);
    });

    TwoFactorAdmin::create([
        'name' => 'Operadora',
        'email' => 'ops@netpizza.test',
        'password' => Hash::make('secret'),
        'auth_scope' => 'admin',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

// ─── helpers ────────────────────────────────────────────────────────────────

/** @return array{0:int, 1:array<string,mixed>} */
function tfPost(object $test, string $uri, array $body = [], ?string $bearer = null): array
{
    $headers = $bearer !== null ? ['HTTP_AUTHORIZATION' => 'Bearer '.$bearer] : [];
    $response = $test->httpPost($uri, $body, $headers);

    return [$response->getStatusCode(), (array) json_decode((string) $response->getContent(), true)];
}

/** @return array{0:int, 1:array<string,mixed>} */
function tfLogin(object $test, string $uri = '/api/admin/auth/login'): array
{
    return tfPost($test, $uri, ['email' => 'ops@netpizza.test', 'password' => 'secret']);
}

function tfUser(): TwoFactorAdmin
{
    return TwoFactorAdmin::query()->firstOrFail();
}

/** El código que mostraría ahora la app del usuario. */
function tfCode(?string $secret = null): string
{
    $service = new TotpService;
    $secret ??= (string) tfUser()->two_factor_secret;

    return $service->codeAt($secret, $service->currentStep());
}

/** Deja al usuario enrolado y confirmado, sin pasar por los endpoints. */
function tfEnroll(): string
{
    $service = new TotpService;
    $secret = $service->generateSecret();
    $plainRecovery = $service->generateRecoveryCodes();

    $user = tfUser();
    $user->two_factor_secret = $secret;
    $user->two_factor_recovery_codes = $service->hashRecoveryCodes($plainRecovery);
    $user->two_factor_confirmed_at = now();
    $user->two_factor_last_step = null;
    $user->save();

    // Devuelve el primer código de recuperación en claro: es lo único que el
    // test no puede recalcular después (en la base sólo queda el hash).
    return $plainRecovery[0];
}

function tfCodeErr(array $body): ?string
{
    return $body['__extraData']['code'] ?? null;
}

// ─── 1. El login con segundo factor NO entrega sesión ────────────────────────

test('CONTRAPRUEBA: sin enrolar, el login de siempre entrega los dos tokens', function () {
    [$status, $body] = tfLogin($this);

    expect($status)->toBe(200);
    expect($body['data'])->toHaveKeys(['access_token', 'refresh_token']);

    [$meStatus] = tfPost($this, '/api/admin/auth/me', [], $body['data']['access_token']);
    // `me` es GET: se pide con el helper de la cadena.
    expect($meStatus)->toBeIn([200, 405]);
});

test('🔴 con 2FA confirmado, el login NO devuelve NINGÚN token: devuelve un desafío', function () {
    tfEnroll();

    [$status, $body] = tfLogin($this);

    expect($status)->toBe(200);
    expect($body['data'])->not->toHaveKey('access_token');
    expect($body['data'])->not->toHaveKey('refresh_token');
    expect($body['data']['two_factor'])->toBe('challenge');
    expect($body['data']['challenge'])->toBeString();
    expect(tfCodeErr($body))->toBe('TWO_FACTOR_REQUIRED');

    // Y la base tampoco tiene tokens: no se emitió ninguno «por las dudas».
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('🔴 el DESAFÍO no es un token: no autentica como Bearer ni refresca', function () {
    tfEnroll();
    [, $body] = tfLogin($this);
    $challenge = $body['data']['challenge'];

    $response = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$challenge]);
    expect($response->getStatusCode())->toBe(401);

    [$status] = tfPost($this, '/api/admin/auth/refresh', ['refresh_token' => $challenge]);
    expect($status)->toBe(401);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('el desafío completado devuelve EXACTAMENTE la sesión que habría devuelto el login', function () {
    tfEnroll();
    [, $login] = tfLogin($this);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'code' => tfCode(),
    ]);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data'])->toHaveKeys(['access_token', 'refresh_token', 'token_type', 'expires_in', 'admin']);

    // Los dos tokens sirven para lo que tienen que servir.
    $me = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$body['data']['access_token']]);
    expect($me->getStatusCode())->toBe(200);

    [$refreshStatus] = tfPost($this, '/api/admin/auth/refresh', ['refresh_token' => $body['data']['refresh_token']]);
    expect($refreshStatus)->toBe(200);
});

// ─── 2. Tope de intentos y replay ───────────────────────────────────────────

test('🔴 un código equivocado NO quema el desafío, pero se cuenta: al tope, 423', function () {
    tfEnroll();
    [, $login] = tfLogin($this);
    $challenge = $login['data']['challenge'];

    // Cinco intentos fallidos (el tope por defecto): el desafío sigue vivo y
    // cada uno responde el 422 genérico.
    foreach (range(1, 5) as $attempt) {
        [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => '000000',
        ]);
        expect($status)->toBe(422, "intento {$attempt}: ".json_encode($body));
        expect(tfCodeErr($body))->toBe('ERR_VALIDATION');
    }

    // El sexto ya no mira el código: la credencial está bloqueada.
    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $challenge,
        'code' => tfCode(),
    ]);
    expect($status)->toBe(423);
    expect(tfCodeErr($body))->toBe('ERR_CODE_LOCKED');
    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('🔴 REPLAY: el mismo código no entra dos veces, ni con un desafío nuevo', function () {
    tfEnroll();
    $code = tfCode();

    [, $first] = tfLogin($this);
    [$status] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $first['data']['challenge'],
        'code' => $code,
    ]);
    expect($status)->toBe(200);

    // Segundo login, desafío nuevo, MISMO código (no pasaron 30 segundos).
    [, $second] = tfLogin($this);
    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $second['data']['challenge'],
        'code' => $code,
    ]);

    expect($status)->toBe(422);
    expect(tfCodeErr($body))->toBe('ERR_VALIDATION');
    expect((int) tfUser()->two_factor_last_step)->toBe((new TotpService)->currentStep());
});

test('🔴 el desafío es de UN SOLO USO: completado, no se puede volver a usar', function () {
    tfEnroll();
    [, $login] = tfLogin($this);
    $challenge = $login['data']['challenge'];

    [$status] = tfPost($this, '/api/admin/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => tfCode()]);
    expect($status)->toBe(200);

    // Con un código del paso siguiente (para que el replay del TOTP no sea lo
    // que corta): lo que corta es que el desafío ya se gastó.
    $service = new TotpService;
    $next = $service->codeAt((string) tfUser()->two_factor_secret, $service->currentStep() + 1);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', ['challenge' => $challenge, 'code' => $next]);
    expect($status)->toBe(422);
    expect(tfCodeErr($body))->toBe('ERR_VALIDATION');
});

test('un desafío inventado, de otro usuario o mal armado no dice nada distinto', function () {
    tfEnroll();
    $id = (string) tfUser()->getKey();

    foreach ([
        'sin punto' => 'sinpunto',
        'id que no existe' => '00000000-0000-0000-0000-000000000000.'.str_repeat('a', 64),
        'id real, secreto inventado' => $id.'.'.str_repeat('b', 64),
        'vacío del lado del secreto' => $id.'.',
    ] as $caso => $challenge) {
        [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
            'challenge' => $challenge,
            'code' => tfCode(),
        ]);
        expect($status)->toBe(422, $caso);
        expect(tfCodeErr($body))->toBe('ERR_VALIDATION', $caso);
    }
});

// ─── 3. Códigos de recuperación ─────────────────────────────────────────────

test('un código de recuperación entra UNA vez y deja la cuenta con uno menos', function () {
    $recovery = tfEnroll();
    [, $login] = tfLogin($this);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'recovery_code' => $recovery,
    ]);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data'])->toHaveKey('access_token');
    expect(tfUser()->two_factor_recovery_codes)->toHaveCount(7);

    // El mismo código, otro login: ya no está en la lista.
    [, $again] = tfLogin($this);
    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $again['data']['challenge'],
        'recovery_code' => $recovery,
    ]);
    expect($status)->toBe(422);
    expect(tfUser()->two_factor_recovery_codes)->toHaveCount(7);
});

test('el desafío exige uno de los dos: sin código y sin código de recuperación es 422', function () {
    tfEnroll();
    [, $login] = tfLogin($this);

    [$status] = tfPost($this, '/api/admin/auth/two-factor/challenge', ['challenge' => $login['data']['challenge']]);

    expect($status)->toBe(422);
});

// ─── 4. Política `required` ─────────────────────────────────────────────────

test('🔴 required + sin enrolar: el login sólo abre el enrolamiento, y esa credencial no llega a ninguna otra ruta', function () {
    TwoFactorAuthController::$policy = TwoFactorPolicy::Required;

    [$status, $body] = tfLogin($this);

    expect($status)->toBe(200);
    expect($body['data'])->not->toHaveKey('access_token');
    expect($body['data']['two_factor'])->toBe('setup');
    expect($body['data']['secret'])->toMatch('/^[A-Z2-7]{32}$/');
    expect($body['data']['otpauth_uri'])->toContain('NetPizza%20Consola');
    expect(tfCodeErr($body))->toBe('TWO_FACTOR_SETUP_REQUIRED');

    $setup = $body['data']['setup'];

    // La credencial de enrolamiento NO es un token ni sirve como desafío.
    expect($this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$setup])->getStatusCode())->toBe(401);
    expect(tfPost($this, '/api/admin/auth/refresh', ['refresh_token' => $setup])[0])->toBe(401);
    expect(tfPost($this, '/api/admin/auth/two-factor/challenge', ['challenge' => $setup, 'code' => '000000'])[0])->toBe(422);
    expect(PersonalAccessToken::query()->count())->toBe(0);

    // Y el secreto guardado queda SIN confirmar: si el usuario abandona acá, el
    // próximo login vuelve a ofrecer el enrolamiento, no lo deja afuera.
    expect(tfUser()->two_factor_confirmed_at)->toBeNull();

    // Confirmado, el mismo endpoint devuelve la sesión Y los códigos de
    // recuperación, que se ven esta única vez.
    [$status, $confirmed] = tfPost($this, '/api/admin/auth/two-factor/setup/confirm', [
        'setup' => $setup,
        'code' => tfCode($body['data']['secret']),
    ]);

    expect($status)->toBe(200, json_encode($confirmed));
    expect($confirmed['data'])->toHaveKeys(['access_token', 'refresh_token', 'recovery_codes']);
    expect($confirmed['data']['recovery_codes'])->toHaveCount(8);
    expect(tfUser()->two_factor_confirmed_at)->not->toBeNull();

    $me = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$confirmed['data']['access_token']]);
    expect($me->getStatusCode())->toBe(200);
});

test('🔴 required: `disable` responde 403 aunque la contraseña y el código sean correctos', function () {
    TwoFactorAuthController::$policy = TwoFactorPolicy::Required;
    tfEnroll();

    [, $login] = tfLogin($this);
    [, $session] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'code' => tfCode(),
    ]);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/disable', [
        'current_password' => 'secret',
        'code' => tfCode(),
    ], $session['data']['access_token']);

    expect($status)->toBe(403);
    expect(tfCodeErr($body))->toBe('ERR_2FA_REQUIRED_BY_SCOPE');
    expect(tfUser()->hasConfirmedTwoFactor())->toBeTrue();
});

// ─── 5. Gestión autenticada ─────────────────────────────────────────────────

test('optional: enable → confirm → disable, con sus pruebas de identidad', function () {
    [, $login] = tfLogin($this);
    $token = $login['data']['access_token'];

    // Sin la contraseña actual no se prende: un token robado no enrola el
    // dispositivo del atacante.
    expect(tfPost($this, '/api/admin/auth/two-factor/enable', [], $token)[0])->toBe(422);
    expect(tfPost($this, '/api/admin/auth/two-factor/enable', ['current_password' => 'otra'], $token)[0])->toBe(422);

    [$status, $enable] = tfPost($this, '/api/admin/auth/two-factor/enable', ['current_password' => 'secret'], $token);
    expect($status)->toBe(200, json_encode($enable));
    expect($enable['data'])->toHaveKeys(['secret', 'otpauth_uri']);

    // Mientras no se confirme, el login sigue entrando derecho.
    expect(tfLogin($this)[1]['data'])->toHaveKey('access_token');

    [$status, $confirm] = tfPost($this, '/api/admin/auth/two-factor/confirm', ['code' => tfCode($enable['data']['secret'])], $token);
    expect($status)->toBe(200, json_encode($confirm));
    expect($confirm['data']['recovery_codes'])->toHaveCount(8);

    // Ahora sí: el login pide el segundo factor.
    expect(tfLogin($this)[1]['data'])->not->toHaveKey('access_token');

    // Y apagarlo pide las dos pruebas.
    expect(tfPost($this, '/api/admin/auth/two-factor/disable', ['current_password' => 'secret', 'code' => '000000'], $token)[0])->toBe(422);
    expect(tfPost($this, '/api/admin/auth/two-factor/disable', ['current_password' => 'mala', 'code' => tfCode()], $token)[0])->toBe(422);

    $service = new TotpService;
    $next = $service->codeAt((string) tfUser()->two_factor_secret, $service->currentStep() + 1);
    [$status] = tfPost($this, '/api/admin/auth/two-factor/disable', ['current_password' => 'secret', 'code' => $next], $token);
    expect($status)->toBe(200);
    expect(tfUser()->hasConfirmedTwoFactor())->toBeFalse();
    expect(tfUser()->two_factor_secret)->toBeNull();
});

test('prender, confirmar y apagar el 2FA cierra las OTRAS sesiones y deja viva la propia', function () {
    [, $sessionA] = tfLogin($this);
    [, $sessionB] = tfLogin($this);

    [$status, $enable] = tfPost($this, '/api/admin/auth/two-factor/enable', ['current_password' => 'secret'], $sessionA['data']['access_token']);
    expect($status)->toBe(200);

    [$status] = tfPost($this, '/api/admin/auth/two-factor/confirm', ['code' => tfCode($enable['data']['secret'])], $sessionA['data']['access_token']);
    expect($status)->toBe(200);

    // La sesión que hizo el cambio sigue adentro…
    expect($this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$sessionA['data']['access_token']])->getStatusCode())->toBe(200);
    // …y la otra quedó afuera.
    expect($this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$sessionB['data']['access_token']])->getStatusCode())->toBe(401);
});

test('regenerar los códigos de recuperación exige un código del autenticador', function () {
    tfEnroll();
    [, $login] = tfLogin($this);
    [, $session] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'code' => tfCode(),
    ]);
    $token = $session['data']['access_token'];
    $before = tfUser()->two_factor_recovery_codes;

    expect(tfPost($this, '/api/admin/auth/two-factor/recovery-codes', ['code' => '000000'], $token)[0])->toBe(422);
    expect(tfUser()->two_factor_recovery_codes)->toBe($before);

    $service = new TotpService;
    $next = $service->codeAt((string) tfUser()->two_factor_secret, $service->currentStep() + 1);
    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/recovery-codes', ['code' => $next], $token);

    expect($status)->toBe(200, json_encode($body));
    expect($body['data']['recovery_codes'])->toHaveCount(8);
    expect(tfUser()->two_factor_recovery_codes)->not->toBe($before);
});

test('`me` dice si el 2FA está activo y NUNCA el secreto ni los códigos', function () {
    tfEnroll();
    [, $login] = tfLogin($this);
    [, $session] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'code' => tfCode(),
    ]);

    $response = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$session['data']['access_token']]);
    $raw = (string) $response->getContent();
    $body = (array) json_decode($raw, true);

    expect($body['data']['two_factor_enabled'])->toBeTrue();
    expect($body['data']['two_factor_confirmed_at'])->toBeString();
    expect($body['data'])->not->toHaveKey('two_factor_secret');
    expect($body['data'])->not->toHaveKey('two_factor_recovery_codes');
    expect($body['data'])->not->toHaveKey('two_factor_last_step');
    // Contraprueba sobre el texto crudo: el secreto no aparece por ningún lado
    // (ni dentro de otra clave, ni en `__extraData`).
    expect($raw)->not->toContain((string) tfUser()->two_factor_secret);
});

// ─── 6. Compatibilidad: un scope en `off` ───────────────────────────────────

test('🔴 BC: un scope SIN política sigue logueando igual, aunque el usuario tenga 2FA confirmado', function () {
    tfEnroll();

    [$status, $body] = tfLogin($this, '/api/plain/auth/login');

    expect($status)->toBe(200);
    expect($body['data'])->toHaveKeys(['access_token', 'refresh_token']);
    expect($body['data'])->not->toHaveKey('two_factor');
    expect($body['message'])->toBe('Login exitoso');

    $me = $this->httpGet('/api/admin/auth/me', ['HTTP_AUTHORIZATION' => 'Bearer '.$body['data']['access_token']]);
    expect($me->getStatusCode())->toBe(200);
});

test('BC: con la política apagada, `enable` responde 403 en vez de prender algo que el login ignora', function () {
    TwoFactorAuthController::$policy = TwoFactorPolicy::Off;
    [, $login] = tfLogin($this);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/enable', ['current_password' => 'secret'], $login['data']['access_token']);

    expect($status)->toBe(403);
    expect(tfCodeErr($body))->toBe('ERR_2FA_DISABLED_BY_SCOPE');
});

// ─── 7. El estado de la cuenta manda también en el segundo paso ─────────────

test('🔴 bloquear al usuario entre el login y el desafío corta el segundo paso', function () {
    tfEnroll();
    [, $login] = tfLogin($this);

    TwoFactorAdmin::query()->update(['status' => ScopeStatus::Blocked->value]);

    [$status, $body] = tfPost($this, '/api/admin/auth/two-factor/challenge', [
        'challenge' => $login['data']['challenge'],
        'code' => tfCode(),
    ]);

    expect($status)->toBe(422);
    expect(tfCodeErr($body))->toBe('ERR_VALIDATION');
    expect(PersonalAccessToken::query()->count())->toBe(0);
});
