<?php

declare(strict_types=1);

use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Registrar y desregistrar un teléfono por las rutas del paquete, con la
 * cadena de middleware real (`['api', 'mk.auth:admin']`).
 *
 * 🔴 Todo se afirma en la BASE, no sólo en el código HTTP: un 404 que igual
 * borró el teléfono de otro es justo el bug que estos tests existen para ver.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

beforeEach(fn () => $this->bootPushApp());

afterEach(fn () => $this->tearDownHttpApp());

function pushDevice(string $address, string $provider = 'fcm', string $platform = 'android'): array
{
    return ['provider' => $provider, 'address' => $address, 'platform' => $platform];
}

test('registers a device for the authenticated user', function () {
    [$ana, $token] = $this->pushUser('Ana');

    expect($this->registerDevice($token, pushDevice('token-ana')))->toBe(204);

    $device = MkPushDevice::query()->sole();
    expect($device->owner_type)->toBe($ana->getMorphClass())
        ->and($device->owner_id)->toBe($ana->id)
        ->and($device->provider)->toBe('fcm')
        ->and($device->address)->toBe('token-ana')
        ->and($device->platform)->toBe('android')
        ->and($device->last_seen_at)->not->toBeNull();
});

test('the owner comes from the session, never from the body', function () {
    [, $token] = $this->pushUser('Ana');
    [$beto] = $this->pushUser('Beto');

    $this->registerDevice($token, pushDevice('token-ana') + ['owner_type' => PushTestUser::class, 'owner_id' => $beto->id]);

    expect(MkPushDevice::query()->ownedBy($beto)->count())->toBe(0);
});

test('re-registering an address moves it to the new owner', function () {
    [, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');

    $this->registerDevice($anaToken, pushDevice('shared-phone'));
    expect($this->registerDevice($betoToken, pushDevice('shared-phone', platform: 'ios')))->toBe(204);

    $device = MkPushDevice::query()->sole();
    expect($device->owner_id)->toBe($beto->id)
        ->and($device->platform)->toBe('ios');
});

test('rejects unknown platform and provider', function (array $body) {
    [, $token] = $this->pushUser('Ana');

    expect($this->registerDevice($token, $body))->toBe(422)
        ->and(MkPushDevice::query()->count())->toBe(0);
})->with([
    'platform' => [pushDevice('t', platform: 'windows')],
    'provider' => [pushDevice('t', provider: 'apns')],
    'log is not a provider' => [pushDevice('t', provider: 'log')],
    'address too long' => [pushDevice(str_repeat('a', 513))],
]);

test('an address of exactly 512 characters is accepted', function () {
    [, $token] = $this->pushUser('Ana');

    expect($this->registerDevice($token, pushDevice(str_repeat('a', 512))))->toBe(204)
        ->and(MkPushDevice::query()->count())->toBe(1);
});

test('unregister only deletes own device', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    $this->registerDevice($anaToken, pushDevice('token-ana'));

    expect($this->unregisterDevice($betoToken, 'token-ana'))->toBe(404);

    expect(MkPushDevice::query()->ownedBy($ana)->where('address', 'token-ana')->exists())->toBeTrue();

    expect($this->unregisterDevice($anaToken, 'token-ana'))->toBe(204)
        ->and(MkPushDevice::query()->count())->toBe(0);
});

test('requires authentication', function () {
    expect($this->registerDevice(null, pushDevice('token-x')))->toBe(401)
        ->and(MkPushDevice::query()->count())->toBe(0);
});

test('without auth middleware the controller still fails closed', function () {
    // El `route_middleware` por defecto es `['api']`, sin auth: un consumer que
    // se olvide de sumar `mk.auth:{scope}` no puede guardar teléfonos sin dueño.
    $this->tearDownHttpApp();
    $this->bootPushApp(['route_middleware' => ['api']]);

    expect($this->registerDevice(null, pushDevice('token-x')))->toBe(401)
        ->and(MkPushDevice::query()->count())->toBe(0);
});

test('routes are opt-in', function () {
    $this->tearDownHttpApp();
    $this->bootPushApp(['register_routes' => false]);
    [, $token] = $this->pushUser('Ana');

    expect($this->registerDevice($token, pushDevice('token-ana')))->toBe(404);
});
