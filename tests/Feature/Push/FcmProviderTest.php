<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Facades\MkPush;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\Providers\FcmAccessToken;
use Mk\Director\Push\Providers\FcmProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El driver `fcm` contra `Http::fake()`: sin red y con una cuenta de servicio
 * descartable (la clave RSA se genera en cada corrida).
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

const FCM_TOKEN_URI = 'https://oauth2.fcm.test/token';
const FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/reto-test/messages:send';

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privateKey);
    $this->publicKey = openssl_pkey_get_details($key)['key'];

    $this->credentials = tempnam(sys_get_temp_dir(), 'mk-fcm-');
    file_put_contents($this->credentials, json_encode([
        'type' => 'service_account',
        'project_id' => 'reto-test',
        'client_email' => 'push@reto-test.iam.gserviceaccount.com',
        'private_key' => $privateKey,
        'token_uri' => FCM_TOKEN_URI,
    ]));

    $this->bootPushApp(['driver' => 'fcm', 'fcm' => ['credentials' => $this->credentials]]);
});

afterEach(function () {
    $this->tearDownHttpApp();
    @unlink($this->credentials);
});

/** @param list<PromiseInterface> $sends una respuesta por token, en orden */
function fakeFcm(array $sends = []): void
{
    Http::fake([
        FCM_TOKEN_URI => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        FCM_SEND_URL => $sends === []
            ? Http::response(['name' => 'projects/reto-test/messages/1'])
            // La última conexión se cae: un token sin red tampoco corta el envío.
            : Http::sequence($sends)->pushFailedConnection(),
    ]);
}

/** @return list<Request> */
function fcmRequests(string $url): array
{
    return Http::recorded(fn (Request $request) => $request->url() === $url)->map(fn ($pair) => $pair[0])->values()->all();
}

function fcmError(int $code, string $status, string $message, array $details = []): PromiseInterface
{
    return Http::response(['error' => compact('code', 'status', 'message', 'details')], $code);
}

test('the fcm driver is built from the service account file', function () {
    expect(app(PushProvider::class))->toBeInstanceOf(FcmProvider::class)
        ->and(app(PushProvider::class)->name())->toBe('fcm');
});

test('a missing or unreadable service account is a clear error', function (?string $path, string $error) {
    config(['mk_director.push.fcm.credentials' => $path]);

    expect(fn () => app(PushProvider::class))->toThrow(RuntimeException::class, $error);
})->with([
    'not configured' => [null, 'MK_PUSH_FCM_CREDENTIALS'],
    'no such file' => ['/no/existe/cuenta.json', "No se puede leer la cuenta de servicio de FCM en '/no/existe/cuenta.json'"],
]);

test('a service account without its private key is rejected without echoing the file', function () {
    file_put_contents($this->credentials, json_encode(['project_id' => 'reto-test', 'client_email' => 'secreto@x', 'token_uri' => FCM_TOKEN_URI]));

    try {
        FcmAccessToken::fromFile($this->credentials);
        $this->fail('debió rechazar la cuenta');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain("falta 'private_key'")
            ->and($e->getMessage())->not->toContain('secreto@x');
    }
});

test('fcm access token is signed RS256 with the service account and cached', function () {
    fakeFcm();
    $provider = app(PushProvider::class);

    $provider->send(new PushMessage('Hola', 'Uno'), ['token-a']);
    $provider->send(new PushMessage('Hola', 'Dos'), ['token-a']);

    $oauth = fcmRequests(FCM_TOKEN_URI);
    expect($oauth)->toHaveCount(1)
        ->and(fcmRequests(FCM_SEND_URL))->toHaveCount(2)
        ->and($oauth[0]['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');

    // Si la firma no fuera RS256 con la clave de la cuenta, esto explota.
    $claims = (array) JWT::decode($oauth[0]['assertion'], new Key($this->publicKey, 'RS256'));
    expect($claims['iss'])->toBe('push@reto-test.iam.gserviceaccount.com')
        ->and($claims['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and($claims['aud'])->toBe(FCM_TOKEN_URI)
        ->and($claims['exp'] - $claims['iat'])->toBe(3600);
});

test('a rejected service account throws, so the job is retried', function () {
    Http::fake([FCM_TOKEN_URI => Http::response(['error' => 'invalid_grant'], 400)]);

    expect(fn () => app(PushProvider::class)->send(new PushMessage('Hola', 'Uno'), ['token-a']))
        ->toThrow(RuntimeException::class, 'invalid_grant');

    expect(fcmRequests(FCM_SEND_URL))->toBe([]);
});

test('sends one request per token with the message', function () {
    fakeFcm();

    $result = app(PushProvider::class)->send(
        new PushMessage('Tu retiro fue pagado', 'Te transferimos 100,00 BOB.', ['kind' => 'withdrawal.paid', 'amount' => 100], url: '/wallet'),
        ['token-a', 'token-b'],
    );

    $sends = fcmRequests(FCM_SEND_URL);
    expect($result->sent)->toBe(2)
        ->and($sends)->toHaveCount(2)
        ->and($sends[0]->header('Authorization'))->toBe(['Bearer ya29.test'])
        ->and(array_map(fn (Request $r) => $r['message']['token'], $sends))->toBe(['token-a', 'token-b'])
        ->and($sends[0]['message'])->toBe([
            'token' => 'token-a',
            'notification' => ['title' => 'Tu retiro fue pagado', 'body' => 'Te transferimos 100,00 BOB.'],
            'android' => ['priority' => 'high'],
            'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            // FCM rechaza un valor que no sea string: el 100 viaja como '100'.
            'data' => ['kind' => 'withdrawal.paid', 'amount' => '100', 'url' => '/wallet'],
        ]);
});

test('a message without data or url sends no data key', function () {
    fakeFcm();

    app(PushProvider::class)->send(new PushMessage('Hola', 'Sin datos'), ['token-a']);

    // `[]` viajaría como lista JSON, y FCM espera un mapa: 400 para todos.
    expect(fcmRequests(FCM_SEND_URL)[0]['message'])->not->toHaveKey('data');
});

test('invalid tokens are reported and other failures only counted', function () {
    $fcmError = ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError'];
    fakeFcm([
        Http::response(['name' => 'ok']),
        fcmError(404, 'NOT_FOUND', 'Requested entity was not found.', [$fcmError + ['errorCode' => 'UNREGISTERED']]),
        fcmError(400, 'INVALID_ARGUMENT', 'The registration token is not a valid FCM registration token', [$fcmError + ['errorCode' => 'INVALID_ARGUMENT']]),
        // Un 400 por el payload NO es culpa del token: no se poda.
        fcmError(400, 'INVALID_ARGUMENT', "Invalid value at 'message.data[0].value'", [
            $fcmError + ['errorCode' => 'INVALID_ARGUMENT'],
            ['@type' => 'type.googleapis.com/google.rpc.BadRequest', 'fieldViolations' => [['field' => 'message.data[0].value']]],
        ]),
        fcmError(503, 'UNAVAILABLE', 'The service is currently unavailable.'),
    ]);

    $result = app(PushProvider::class)->send(new PushMessage('Hola', 'Uno'), ['ok', 'unregistered', 'garbage', 'bad-payload', 'busy', 'offline']);

    expect($result->sent)->toBe(1)
        ->and($result->invalid)->toBe(['unregistered', 'garbage'])
        ->and($result->failed)->toBe(3);
});

test('MkPush::to sends through fcm to the owner devices only', function () {
    fakeFcm();
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    $this->registerDevice($anaToken, ['provider' => 'fcm', 'address' => 'ana-phone', 'platform' => 'android']);
    $this->registerDevice($betoToken, ['provider' => 'fcm', 'address' => 'beto-phone', 'platform' => 'android']);

    MkPush::to($ana)->send(new PushMessage('Hola', 'Ana'));

    expect(array_map(fn (Request $r) => $r['message']['token'], fcmRequests(FCM_SEND_URL)))->toBe(['ana-phone']);
});

test('prunes addresses the provider reports invalid', function () {
    $fcmError = ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError'];
    fakeFcm([
        Http::response(['name' => 'ok']),
        fcmError(404, 'NOT_FOUND', 'Requested entity was not found.', [$fcmError + ['errorCode' => 'UNREGISTERED']]),
    ]);
    [$ana, $anaToken] = $this->pushUser('Ana');
    $this->registerDevice($anaToken, ['provider' => 'fcm', 'address' => 'ana-phone', 'platform' => 'android']);
    $this->registerDevice($anaToken, ['provider' => 'fcm', 'address' => 'ana-old-phone', 'platform' => 'android']);

    MkPush::to($ana)->send(new PushMessage('Hola', 'Ana'));

    // El orden de envío no está garantizado: lo que se mide es que se borró
    // exactamente la dirección que FCM respondió con UNREGISTERED.
    $unregistered = fcmRequests(FCM_SEND_URL)[1]['message']['token'];

    expect(MkPushDevice::query()->pluck('address')->all())
        ->toBe([$unregistered === 'ana-phone' ? 'ana-old-phone' : 'ana-phone']);
});

test('fcm payload maps channel, icon, color, sound and image', function () {
    fakeFcm();

    app(PushProvider::class)->send(
        new PushMessage('Pago', 'Llegó', channel: 'payments', image: 'https://cdn.test/a.png', sound: 'chime.wav', icon: 'ic_payment', color: '#16A34A'),
        ['token-a'],
    );

    $message = fcmRequests(FCM_SEND_URL)[0]['message'];
    expect($message['android'])->toBe([
        'priority' => 'high',
        // Android busca el sonido en res/raw por NOMBRE de recurso: sin extensión.
        'notification' => ['channel_id' => 'payments', 'icon' => 'ic_payment', 'color' => '#16A34A', 'sound' => 'chime', 'image' => 'https://cdn.test/a.png'],
    ])->and($message['apns'])->toBe([
        'payload' => ['aps' => ['sound' => 'chime.wav', 'thread-id' => 'payments', 'mutable-content' => 1]],
        'fcm_options' => ['image' => 'https://cdn.test/a.png'],
    ]);
});

test('the job sends the message resolved with its channel config', function () {
    fakeFcm();
    config(['mk_director.push.channels' => ['payments' => ['name' => 'Pagos', 'sound' => 'chime.wav', 'importance' => 'high', 'color' => '#16A34A']]]);
    config(['mk_director.push.default_channel' => 'default']);
    [$ana, $anaToken] = $this->pushUser('Ana');
    $this->registerDevice($anaToken, ['provider' => 'fcm', 'address' => 'ana-phone', 'platform' => 'android']);

    MkPush::to($ana)->send(new PushMessage('Pago', 'Llegó', channel: 'payments'));
    MkPush::to($ana)->send(new PushMessage('Aviso', 'Otro'));

    [$payment, $notice] = array_map(fn (Request $r) => $r['message'], fcmRequests(FCM_SEND_URL));
    expect($payment['android']['notification'])->toBe(['channel_id' => 'payments', 'color' => '#16A34A', 'sound' => 'chime'])
        ->and($payment['apns']['payload']['aps'])->toBe(['sound' => 'chime.wav', 'thread-id' => 'payments'])
        ->and($notice['android']['notification'])->toBe(['channel_id' => 'default'])
        ->and($notice['apns']['payload']['aps'])->toBe(['sound' => 'default', 'thread-id' => 'default']);
});
