<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Facades\MkPush;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\Providers\OneSignalProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * El driver `onesignal` contra `Http::fake()`. El app id y la clave son de
 * mentira: este repo es público.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

const ONESIGNAL_APP_ID = '00000000-0000-4000-8000-000000000001';
const ONESIGNAL_KEY = 'os_v2_app_fake-test-key';

beforeEach(function () {
    $this->bootPushApp(['driver' => 'onesignal', 'onesignal' => ['app_id' => ONESIGNAL_APP_ID, 'api_key' => ONESIGNAL_KEY]]);
});

afterEach(fn () => $this->tearDownHttpApp());

/** @param list<PromiseInterface> $responses una por request, en orden */
function fakeOneSignal(array $responses = []): void
{
    Http::fake([OneSignalProvider::URL => $responses === []
        ? Http::response(['id' => 'notification-1'])
        : Http::sequence($responses)]);
}

/** @return list<Request> */
function oneSignalRequests(): array
{
    return Http::recorded(fn (Request $r) => $r->url() === OneSignalProvider::URL)->map(fn ($pair) => $pair[0])->values()->all();
}

test('the onesignal driver is built from config', function () {
    expect(app(PushProvider::class))->toBeInstanceOf(OneSignalProvider::class)
        ->and(app(PushProvider::class)->name())->toBe('onesignal');
});

test('a missing app id or key is a clear error that does not echo the key', function (array $onesignal, string $missing) {
    config(['mk_director.push.onesignal' => $onesignal]);

    try {
        app(PushProvider::class);
        $this->fail('debió exigir la config');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain($missing)
            ->and($e->getMessage())->not->toContain(ONESIGNAL_KEY);
    }
})->with([
    'no app id' => [['app_id' => null, 'api_key' => ONESIGNAL_KEY], 'necesita MK_PUSH_ONESIGNAL_APP_ID.'],
    'no key' => [['app_id' => ONESIGNAL_APP_ID, 'api_key' => ''], 'necesita MK_PUSH_ONESIGNAL_API_KEY.'],
]);

test('onesignal payload uses include_subscription_ids and Key auth', function () {
    fakeOneSignal();

    $result = app(PushProvider::class)->send(
        new PushMessage('Tu retiro fue pagado', 'Te transferimos BOB 100,00.', ['kind' => 'withdrawal.paid', 'amount' => 100], url: '/wallet'),
        ['sub-a', 'sub-b'],
    );

    $requests = oneSignalRequests();
    expect($result->sent)->toBe(2)
        ->and($requests)->toHaveCount(1)
        ->and($requests[0]->method())->toBe('POST')
        ->and($requests[0]->header('Authorization'))->toBe(['Key '.ONESIGNAL_KEY])
        // La ruta va en data.url; la `url` de OneSignal abre el NAVEGADOR.
        ->and($requests[0]->data())->toBe([
            'app_id' => ONESIGNAL_APP_ID,
            'include_subscription_ids' => ['sub-a', 'sub-b'],
            // `en` es obligatorio: es el texto para todos los idiomas.
            'headings' => ['en' => 'Tu retiro fue pagado'],
            'contents' => ['en' => 'Te transferimos BOB 100,00.'],
            'data' => ['kind' => 'withdrawal.paid', 'amount' => '100', 'url' => '/wallet'],
        ]);
});

test('onesignal payload maps channel, icon, color, sound and image', function () {
    fakeOneSignal();
    $provider = app(PushProvider::class);

    $provider->send(new PushMessage('Pago', 'Llegó', channel: 'payments', image: 'https://cdn.test/a.png', sound: 'chime.wav', icon: 'ic_payment', color: '#16a34a'), ['sub-a']);
    $provider->send(new PushMessage('Pago', 'Llegó', channel: '3f2c1b4a-9d8e-4f7a-8b6c-5d4e3f2a1b0c'), ['sub-a']);

    [$own, $dashboard] = array_map(fn (Request $r) => $r->data(), oneSignalRequests());
    expect(array_diff_key($own, array_flip(['app_id', 'include_subscription_ids', 'headings', 'contents'])))->toBe([
        // Un id propio es un canal que creó la app, no uno del panel.
        'existing_android_channel_id' => 'payments',
        'thread_id' => 'payments',
        'small_icon' => 'ic_payment',
        'android_accent_color' => 'FF16A34A',
        'big_picture' => 'https://cdn.test/a.png',
        'ios_attachments' => ['id' => 'https://cdn.test/a.png'],
        'ios_sound' => 'chime.wav',
    ])->and($dashboard)->toHaveKey('android_channel_id', '3f2c1b4a-9d8e-4f7a-8b6c-5d4e3f2a1b0c')
        ->and($dashboard)->not->toHaveKey('existing_android_channel_id')
        ->and($own)->not->toHaveKey('url')
        ->and($own)->not->toHaveKey('data');
});

test('more than 20,000 addresses go in several requests', function () {
    fakeOneSignal();
    $addresses = array_map(fn (int $i) => "sub-{$i}", range(1, OneSignalProvider::MAX_PER_REQUEST + 1));

    $result = app(PushProvider::class)->send(new PushMessage('Hola', 'Todos'), $addresses);

    $requests = oneSignalRequests();
    expect($requests)->toHaveCount(2)
        ->and($requests[0]['include_subscription_ids'])->toHaveCount(OneSignalProvider::MAX_PER_REQUEST)
        ->and($requests[1]['include_subscription_ids'])->toBe(['sub-'.(OneSignalProvider::MAX_PER_REQUEST + 1)])
        ->and($result->sent)->toBe(OneSignalProvider::MAX_PER_REQUEST + 1);
});

test('invalid subscription ids are reported and other failures only counted', function () {
    fakeOneSignal([
        Http::response(['id' => 'notification-1', 'errors' => ['invalid_player_ids' => ['sub-gone']]]),
        // Ninguna suscripta: `errors` es una lista de textos, no se sabe cuál es cuál.
        Http::response(['id' => '', 'errors' => ['All included players are not subscribed']]),
        Http::response(['errors' => ['Message Notifications must have English language content']], 400),
    ]);
    $provider = app(PushProvider::class);

    $first = $provider->send(new PushMessage('Hola', 'Uno'), ['sub-ok', 'sub-gone']);
    $second = $provider->send(new PushMessage('Hola', 'Dos'), ['sub-off']);
    $third = $provider->send(new PushMessage('Hola', 'Tres'), ['sub-ok']);

    expect([$first->sent, $first->invalid, $first->failed])->toBe([1, ['sub-gone'], 0])
        ->and([$second->sent, $second->invalid, $second->failed])->toBe([0, [], 1])
        ->and([$third->sent, $third->invalid, $third->failed])->toBe([0, [], 1]);
});

test('a rejected key throws, so the job fails visibly, without echoing the key', function () {
    fakeOneSignal([Http::response(['errors' => ['Access denied.  Please include an \'Authorization: ...\' header with a valid API key']], 403)]);

    try {
        app(PushProvider::class)->send(new PushMessage('Hola', 'Uno'), ['sub-a']);
        $this->fail('debió tirar');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('HTTP 403')->and($e->getMessage())->not->toContain(ONESIGNAL_KEY);
    }
});

test('prunes the subscription ids onesignal reports invalid', function () {
    fakeOneSignal([Http::response(['id' => 'notification-1', 'errors' => ['invalid_player_ids' => ['ana-old-phone']]])]);
    [$ana, $anaToken] = $this->pushUser('Ana');
    $this->registerDevice($anaToken, ['provider' => 'onesignal', 'address' => 'ana-phone', 'platform' => 'android']);
    $this->registerDevice($anaToken, ['provider' => 'onesignal', 'address' => 'ana-old-phone', 'platform' => 'android']);
    $this->registerDevice($anaToken, ['provider' => 'fcm', 'address' => 'ana-fcm-phone', 'platform' => 'android']);

    MkPush::to($ana)->send(new PushMessage('Hola', 'Ana'));

    expect(oneSignalRequests()[0]['include_subscription_ids'])->toEqualCanonicalizing(['ana-phone', 'ana-old-phone'])
        ->and(MkPushDevice::query()->orderBy('address')->pluck('address')->all())->toBe(['ana-fcm-phone', 'ana-phone']);
});
