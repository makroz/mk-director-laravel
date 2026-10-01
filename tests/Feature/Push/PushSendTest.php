<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Facades\MkPush;
use Mk\Director\Push\Jobs\SendPushJob;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\Providers\LogProvider;
use Mk\Director\Push\Providers\NullProvider;
use Mk\Director\Push\PushMessage;
use Mk\Director\Push\PushResult;
use Mk\Director\Tests\Concerns\BootsPushApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;
use Psr\Log\AbstractLogger;

/**
 * `MkPush::to($user)->send(...)` de punta a punta: el job corre en la cola
 * `sync` de verdad y el proveedor es un falso que anota a qué direcciones le
 * pidieron mandar. Lo que se afirma es QUIÉN recibe.
 */
uses(MkLaravelTestCase::class, BootsPushApp::class);

/** Un servicio falso: anota cada envío. */
final class FakePushProvider implements PushProvider
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly string $name = 'fcm') {}

    public function name(): string
    {
        return $this->name;
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        $this->calls[] = $addresses;

        return new PushResult(sent: count($addresses));
    }
}

/** Un log que guarda lo que se escribe, para ver qué hizo el driver `log`. */
final class CapturingPushLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $lines = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = [(string) $message, $context];
    }
}

beforeEach(function () {
    $this->bootPushApp();
    $this->fake = new FakePushProvider;
    $this->httpApp->instance(PushProvider::class, $this->fake);
});

afterEach(fn () => $this->tearDownHttpApp());

function pushRegister(object $test, string $token, string $address, string $provider = 'fcm'): void
{
    $test->registerDevice($token, ['provider' => $provider, 'address' => $address, 'platform' => 'android']);
}

function pushMessage(): PushMessage
{
    return new PushMessage('Tu retiro fue pagado', 'Te transferimos 100,00 BOB.', ['kind' => 'withdrawal.paid'], url: '/wallet');
}

test('sends to every device of the owner and nobody else', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $anaToken, 'ana-tablet');
    pushRegister($this, $betoToken, 'beto-phone');

    MkPush::to($ana)->send(pushMessage());

    expect($this->fake->calls)->toHaveCount(1)
        ->and($this->fake->calls[0])->toEqualCanonicalizing(['ana-phone', 'ana-tablet']);
});

test('sends to several owners at once', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');
    [, $caroToken] = $this->pushUser('Caro');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $betoToken, 'beto-phone');
    pushRegister($this, $caroToken, 'caro-phone');

    MkPush::to([$ana, $beto])->send(pushMessage());

    expect($this->fake->calls[0])->toEqualCanonicalizing(['ana-phone', 'beto-phone']);
});

test('a job without owners sends nothing (never every device)', function () {
    [, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    MkPush::to([])->send(pushMessage());
    (new SendPushJob(pushMessage(), []))->handle($this->fake);

    expect($this->fake->calls)->toBe([]);
});

test('with a morph map the owner is matched by its alias', function () {
    // RETO registra `member` en el morph map: `owner_type` guarda el ALIAS, y
    // un envío que buscara por `::class` no encontraría ningún teléfono.
    Relation::morphMap(['push-user' => PushTestUser::class]);

    try {
        [$ana, $anaToken] = $this->pushUser('Ana');
        pushRegister($this, $anaToken, 'ana-phone');

        MkPush::to($ana)->send(pushMessage());

        expect(MkPushDevice::query()->sole()->owner_type)->toBe('push-user')
            ->and($this->fake->calls)->toBe([['ana-phone']]);
    } finally {
        Relation::$morphMap = [];
    }
});

test('only devices of the active provider are targeted', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-fcm');
    pushRegister($this, $anaToken, 'ana-onesignal', provider: 'onesignal');

    MkPush::to($ana)->send(pushMessage());

    expect($this->fake->calls)->toBe([['ana-fcm']]);
});

test('no job when the transaction rolls back', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    try {
        DB::transaction(function () use ($ana) {
            MkPush::to($ana)->send(pushMessage());

            throw new RuntimeException('la operación que avisaba falló');
        });
    } catch (RuntimeException) {
    }

    expect($this->fake->calls)->toBe([]);

    // El caso inverso, para que el verde no sea "nunca manda nada".
    DB::transaction(fn () => MkPush::to($ana)->send(pushMessage()));

    expect($this->fake->calls)->toBe([['ana-phone']]);
});

test('the driver comes from config, and an unknown one throws', function () {
    $this->tearDownHttpApp();
    $this->bootPushApp(['driver' => 'null']);

    expect(app(PushProvider::class))->toBeInstanceOf(NullProvider::class);

    config(['mk_director.push.driver' => 'log']);
    expect(app(PushProvider::class))->toBeInstanceOf(LogProvider::class);

    config(['mk_director.push.driver' => 'onesignal']);
    expect(fn () => app(PushProvider::class))->toThrow(RuntimeException::class, 'todavía no está implementado');

    config(['mk_director.push.driver' => 'fmc']);
    expect(fn () => app(PushProvider::class))->toThrow(InvalidArgumentException::class, 'desconocido');
});

test('the package default driver is null', function () {
    $this->tearDownHttpApp();
    $app = $this->bootHttpApp(stdClass::class);

    expect($app['config']->get('mk_director.push.driver'))->toBe('null')
        ->and($app['config']->get('mk_director.push.register_routes'))->toBeFalse();
});

test('null driver sends nothing', function () {
    $this->tearDownHttpApp();
    $this->bootPushApp(['driver' => 'null']);
    $logger = new CapturingPushLogger;
    $this->httpApp->instance('log', $logger);
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    MkPush::to($ana)->send(pushMessage());

    expect($logger->lines)->toBe([]);
});

test('log driver writes the push and its addresses to the log', function () {
    $this->tearDownHttpApp();
    $this->bootPushApp(['driver' => 'log']);
    $logger = new CapturingPushLogger;
    $this->httpApp->instance('log', $logger);
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $anaToken, 'ana-onesignal', provider: 'onesignal');
    pushRegister($this, $betoToken, 'beto-phone');

    MkPush::to($ana)->send(pushMessage());

    // `log` no es un servicio: muestra todos los teléfonos del dueño, de
    // cualquier proveedor — y ninguno ajeno.
    expect($logger->lines)->toHaveCount(1)
        ->and($logger->lines[0][0])->toContain('Tu retiro fue pagado')
        ->and($logger->lines[0][1]['addresses'])->toEqualCanonicalizing(['ana-phone', 'ana-onesignal'])
        ->and($logger->lines[0][1]['url'])->toBe('/wallet');
});
