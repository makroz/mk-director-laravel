<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Push\Contracts\PushProvider;
use Mk\Director\Push\Facades\MkPush;
use Mk\Director\Push\Jobs\SendPushJob;
use Mk\Director\Push\Models\MkPushDevice;
use Mk\Director\Push\Models\MkPushTopicSubscription;
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

    /** @param list<string> $invalid lo que el servicio dice que rechazó para siempre */
    public function __construct(private readonly string $name = 'fcm', private readonly array $invalid = []) {}

    public function name(): string
    {
        return $this->name;
    }

    public function send(PushMessage $message, array $addresses): PushResult
    {
        $this->calls[] = $addresses;

        return new PushResult(sent: count($addresses), invalid: $this->invalid);
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

/** Las direcciones que quedan en la base, por proveedor. */
function pushAddresses(): array
{
    return MkPushDevice::query()->orderBy('address')->get()
        ->map(fn (MkPushDevice $d) => $d->provider.':'.$d->address)->all();
}

test('prunes only the addresses the provider reports invalid, so they are never sent again', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $anaToken, 'ana-tablet');
    pushRegister($this, $betoToken, 'beto-phone');
    $this->httpApp->instance(PushProvider::class, $fake = new FakePushProvider('fcm', invalid: ['ana-phone']));

    MkPush::to($ana)->send(pushMessage());

    expect(pushAddresses())->toBe(['fcm:ana-tablet', 'fcm:beto-phone']);

    MkPush::to($ana)->send(pushMessage());

    expect($fake->calls[1])->toBe(['ana-tablet']);
});

test('pruning does not touch the same address of another provider', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'shared-address');
    pushRegister($this, $anaToken, 'shared-address', provider: 'onesignal');
    $this->httpApp->instance(PushProvider::class, new FakePushProvider('fcm', invalid: ['shared-address']));

    MkPush::to($ana)->send(pushMessage());

    expect(pushAddresses())->toBe(['onesignal:shared-address']);
});

test('the log and null drivers never prune', function (string $driver) {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');
    $this->httpApp->instance(PushProvider::class, new FakePushProvider($driver, invalid: ['ana-phone']));

    MkPush::to($ana)->send(pushMessage());

    expect(pushAddresses())->toBe(['fcm:ana-phone']);
})->with(['log', 'null']);

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

/*
|--------------------------------------------------------------------------
| Grupos y temas (corte 5)
|--------------------------------------------------------------------------
*/

/** Otro tipo de dueño, para los grupos mezclados: miembros y admins. */
final class PushTestAdmin extends AuthUser
{
    protected $table = 'push_test_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

/** Un teléfono escrito directo en la base, para dueños que no pasan por la ruta. */
function pushDeviceFor(Model $owner, string $address, string $provider = 'fcm'): void
{
    (new MkPushDevice(['provider' => $provider, 'address' => $address, 'platform' => 'android']))
        ->owner()->associate($owner)->save();
}

/** @return list<string> todas las direcciones que se le pidieron al servicio */
function pushSentAddresses(FakePushProvider $fake): array
{
    return array_merge(...$fake->calls ?: [[]]);
}

test('a group mixes owner types, and the same id under another type is another person', function () {
    Schema::create('push_test_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->timestamps();
    });
    [$ana, $anaToken] = $this->pushUser('Ana');
    [, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $betoToken, 'beto-phone');
    // Mismo id que Ana, otra tabla: el `owner_type` es lo único que las separa.
    // `forceCreate`: `id` no está en el `$fillable` de AuthUser y `create` lo
    // descartaría callado, con otro uuid — y el test no mediría el cruce.
    $admin = PushTestAdmin::forceCreate(['id' => $ana->id, 'name' => 'Admin', 'email' => 'admin@test.local', 'password' => 'x']);
    pushDeviceFor($admin, 'admin-phone');

    MkPush::to($ana)->send(pushMessage());
    // El resultado de una consulta, con un dueño de otro tipo agregado.
    MkPush::to(PushTestAdmin::query()->get()->push($ana))->send(pushMessage());

    expect($this->fake->calls[0])->toBe(['ana-phone'])
        ->and($this->fake->calls[1])->toEqualCanonicalizing(['admin-phone', 'ana-phone']);
});

test('a repeated owner counts once: one request per address', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $anaToken, 'ana-tablet');
    pushRegister($this, $betoToken, 'beto-phone');

    $group = collect([$ana, $beto, $ana, PushTestUser::query()->find($ana->id)]);
    MkPush::to($group)->send(pushMessage());

    // La base sola ya no repite (una dirección es de un solo teléfono); el
    // dueño repetido se colapsa antes, para que el job encolado no lo cargue.
    expect((fn () => $this->owners)->call(MkPush::to($group)))->toHaveCount(2)
        ->and(pushSentAddresses($this->fake))->toHaveCount(3)
        ->and(pushSentAddresses($this->fake))->toEqualCanonicalizing(['ana-phone', 'ana-tablet', 'beto-phone']);
});

test('an empty group or a topic nobody follows sends nothing', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');
    MkPush::subscribe($ana, 'news');

    MkPush::to(collect())->send(pushMessage());
    MkPush::to(PushTestUser::query()->whereRaw('1 = 0')->get())->send(pushMessage());
    MkPush::topic('nobody-follows')->send(pushMessage());

    expect($this->fake->calls)->toBe([]);
});

test('a topic send reaches only its subscribers, on the active provider', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');
    [, $caroToken] = $this->pushUser('Caro');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $anaToken, 'ana-tablet');
    pushRegister($this, $anaToken, 'ana-onesignal', provider: 'onesignal');
    pushRegister($this, $betoToken, 'beto-phone');
    pushRegister($this, $caroToken, 'caro-phone');
    MkPush::subscribe($ana, 'news');
    MkPush::subscribe($beto, 'other');

    MkPush::topic('news')->send(pushMessage());

    expect($this->fake->calls)->toHaveCount(1)
        ->and($this->fake->calls[0])->toEqualCanonicalizing(['ana-phone', 'ana-tablet']);
});

test('subscribe is idempotent: one row, one request per address', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    MkPush::subscribe($ana, 'news');
    MkPush::subscribe($ana, 'news');
    MkPush::topic('news')->send(pushMessage());

    expect(MkPushTopicSubscription::query()->count())->toBe(1)
        ->and($this->fake->calls)->toBe([['ana-phone']]);
});

test('unsubscribe stops delivery of that topic only', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $betoToken, 'beto-phone');
    MkPush::subscribe($ana, 'news');
    MkPush::subscribe($beto, 'news');
    MkPush::subscribe($beto, 'other');

    MkPush::unsubscribe($beto, 'news');
    MkPush::unsubscribe($beto, 'never-subscribed');
    MkPush::topic('news')->send(pushMessage());
    MkPush::topic('other')->send(pushMessage());

    expect($this->fake->calls)->toBe([['ana-phone'], ['beto-phone']]);
});

test('topic subscriptions use the morph alias', function () {
    Relation::morphMap(['push-user' => PushTestUser::class]);

    try {
        [$ana, $anaToken] = $this->pushUser('Ana');
        pushRegister($this, $anaToken, 'ana-phone');

        MkPush::subscribe($ana, 'news');
        MkPush::topic('news')->send(pushMessage());

        expect(MkPushTopicSubscription::query()->sole()->owner_type)->toBe('push-user')
            ->and($this->fake->calls)->toBe([['ana-phone']]);
    } finally {
        Relation::$morphMap = [];
    }
});

test('an invalid topic name is rejected and writes nothing', function (string $topic) {
    [$ana] = $this->pushUser('Ana');

    expect(fn () => MkPush::topic($topic))->toThrow(InvalidArgumentException::class, 'Tema de push inválido')
        ->and(fn () => MkPush::subscribe($ana, $topic))->toThrow(InvalidArgumentException::class)
        ->and(fn () => MkPush::unsubscribe($ana, $topic))->toThrow(InvalidArgumentException::class)
        ->and(MkPushTopicSubscription::query()->count())->toBe(0);
})->with([
    'mayúsculas' => 'News',
    'espacio' => 'the news',
    'vacío' => '',
    'salto de línea al final' => "news\n",
    'comilla' => "news'--",
    'barra' => 'a/b',
    'no ascii' => 'ñandú',
    '101 caracteres' => str_repeat('a', 101),
]);

test('a valid topic name is accepted', function (string $topic) {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    MkPush::subscribe($ana, $topic);
    MkPush::topic($topic)->send(pushMessage());

    expect($this->fake->calls)->toBe([['ana-phone']]);
})->with(['project:42.news_v-1', str_repeat('a', 100)]);

test('a large topic goes to the provider in chunks', function () {
    config(['mk_director.push.chunk' => 2]);

    foreach (['ana', 'beto', 'caro', 'dani', 'eli'] as $name) {
        [$user, $token] = $this->pushUser(ucfirst($name));
        pushRegister($this, $token, $name.'-phone');
        MkPush::subscribe($user, 'news');
    }

    MkPush::topic('news')->send(pushMessage());

    expect($this->fake->calls)->toHaveCount(3)
        ->and(array_map('count', $this->fake->calls))->toBe([2, 2, 1])
        ->and(pushSentAddresses($this->fake))->toEqualCanonicalizing(['ana-phone', 'beto-phone', 'caro-phone', 'dani-phone', 'eli-phone']);
});

test('a topic send prunes the invalid addresses and keeps going', function () {
    config(['mk_director.push.chunk' => 1]);
    [$ana, $anaToken] = $this->pushUser('Ana');
    [$beto, $betoToken] = $this->pushUser('Beto');
    pushRegister($this, $anaToken, 'ana-phone');
    pushRegister($this, $betoToken, 'beto-phone');
    MkPush::subscribe($ana, 'news');
    MkPush::subscribe($beto, 'news');
    // El falso dice «inválida» en CADA tanda, y se elige la que sale ÚLTIMA:
    // si el job borrara lo que no mandó en esa tanda, nunca le llegaría.
    $last = MkPushDevice::query()->orderByDesc('id')->value('address');
    $first = $last === 'ana-phone' ? 'beto-phone' : 'ana-phone';
    $this->httpApp->instance(PushProvider::class, $fake = new FakePushProvider('fcm', invalid: [$last]));

    MkPush::topic('news')->send(pushMessage());

    // Borrar en medio de las tandas no se salta a nadie: las dos salieron.
    expect($fake->calls)->toBe([[$first], [$last]])
        ->and(pushAddresses())->toBe(['fcm:'.$first])
        // El teléfono se fue; la suscripción de la persona, no.
        ->and(MkPushTopicSubscription::query()->count())->toBe(2);
});

test('a job queued before topics existed still runs', function () {
    [$ana, $anaToken] = $this->pushUser('Ana');
    pushRegister($this, $anaToken, 'ana-phone');

    // El payload que dejó en la cola el corte 4: el mismo job, sin `topic`.
    $payload = serialize(new SendPushJob(pushMessage(), [[$ana->getMorphClass(), (string) $ana->id]]));
    expect(substr_count($payload, 's:5:"topic";N;'))->toBe(1);
    $old = preg_replace_callback(
        '/^O:(\d+):"([^"]+)":(\d+):/',
        fn (array $m) => 'O:'.$m[1].':"'.$m[2].'":'.($m[3] - 1).':',
        str_replace('s:5:"topic";N;', '', $payload),
    );

    unserialize($old)->handle($this->fake);

    expect($this->fake->calls)->toBe([['ana-phone']]);
});
