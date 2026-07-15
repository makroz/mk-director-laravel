<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Cache\CacheManager;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Mk\Director\Auth\Controllers\BaseAuthController;
use Mk\Director\Auth\Events\AuthEvent;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * [SECURITY] E2E (SQLite in-memory + Event::fake) for the two authenticated
 * OTP password-change endpoints.
 *
 * Spec: 2026-07-15-profile-edit-password-otp
 *   - Phase 2.5 tasks.md
 *   - Domain: authenticated-password-change-otp (spec.md)
 *
 * Follows the `AuthUserCompleteFlowE2ETest` pattern: a `ConcreteAuthController`
 * pins the 4 abstracts for a `test` scope. Unlike that "lite" (reflection-only)
 * E2E, this file boots a REAL sqlite connection + a REAL bcrypt Hash facade +
 * a REAL Validator + `Event::fake()` so the full issue→event→confirm→password
 * pipeline runs for real, matching the crypto/attempt-cap/expiry contract in
 * `EmailOtpServiceTest` end-to-end through the controller.
 */
uses(MkLaravelTestCase::class);

final class OtpConcreteAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return 'OtpConcreteAuthController\TestUser';
    }

    protected function authScope(): string
    {
        return 'test';
    }

    protected function loginField(): string
    {
        return 'email';
    }

    protected function passwordResetTable(): string
    {
        return 'test_password_reset_tokens';
    }
}

/**
 * Minimal Authenticatable double — NOT an Eloquent model, so no `tokens()`
 * table is required (config `password_change.revoke_other_sessions` is set
 * to `false` in these tests to keep that branch a no-op).
 */
final class OtpTestUser implements Authenticatable
{
    public array $setPasswordCalls = [];

    public function __construct(private readonly string $id) {}

    public function getAuthIdentifierName() { return 'id'; }
    public function getAuthIdentifier() { return $this->id; }
    public function getAuthPasswordName() { return 'password'; }
    public function getAuthPassword() { return 'irrelevant-hash'; }
    public function getRememberToken() { return null; }
    public function setRememberToken($value) {}
    public function getRememberTokenName() { return 'remember_token'; }
    public function setAuthPassword(string $password): void
    {
        $this->setPasswordCalls[] = $password;
    }
}

function bootOtpE2EContainer(): Capsule
{
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $container = new Container();
    Container::setInstance($container);

    $container->instance('db', $capsule->getDatabaseManager());
    $container->bind('db.schema', fn () => $capsule->getConnection()->getSchemaBuilder());

    $container->instance('config', new ConfigRepository([
        'hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]],
        'mk_director' => [
            'auth' => [
                'otp' => [
                    'length' => 6,
                    'ttl_seconds' => 600,
                    'max_attempts' => 5,
                    'throttle' => ['max' => 3, 'window_seconds' => 600],
                ],
                'password_change' => ['revoke_other_sessions' => false],
            ],
        ],
        'cache' => [
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
        ],
    ]));

    $container->singleton('hash', fn ($app) => new HashManager($app));

    $container->singleton('translator', function () {
        return new Translator(new ArrayLoader(), 'en');
    });
    $container->singleton('validator', function ($app) {
        return new ValidationFactory($app['translator'], $app);
    });
    // `validator()` global helper resolves the interface FQCN, not the
    // 'validator' string alias — bind both (see Foundation/helpers.php).
    $container->alias('validator', \Illuminate\Contracts\Validation\Factory::class);

    // Real event Dispatcher so `Event::fake()` (which reads the CURRENT
    // facade root to wrap it) has something real to resolve/swap.
    $container->singleton('events', fn ($app) => new EventDispatcher($app));

    // `Event::fake()` also calls `Cache::refreshEventDispatcher()`, which
    // only exists on the real CacheManager (not a bare Repository) — needs
    // a real 'cache' manager bound (array driver, in-memory).
    $container->singleton('cache', fn ($app) => new CacheManager($app));

    // Minimal ResponseFactory so `response()->json(...)` (used by
    // BaseController::sendResponse()/sendError()) returns a real
    // JsonResponse without booting a full Laravel app (LAR-09 pattern,
    // see BaseControllerSendErrorEnvelopeE2ETest.php).
    $factory = \Mockery::mock(ResponseFactory::class);
    $factory->shouldReceive('json')->andReturnUsing(
        fn ($data = [], $status = 200, array $headers = [], $options = 0) => new JsonResponse($data, $status, $headers, $options),
    );
    $container->instance(ResponseFactory::class, $factory);

    Facade::setFacadeApplication($container);
    Facade::clearResolvedInstances();

    // `Request::validate()` is normally registered as a macro by
    // `FoundationServiceProvider::registerRequestValidation()` during full
    // app boot. The package does not boot a full app (MkLaravelTestCase
    // docblock), so we register the same macro body here — this is the
    // ONLY way `confirmPasswordCode()`'s `$request->validate(...)` call
    // works in this minimal container.
    if (! Request::hasMacro('validate')) {
        Request::macro('validate', function (array $rules, ...$params) {
            return validator($this->all(), $rules, ...$params)->validate();
        });
    }

    $capsule->getConnection()->getSchemaBuilder()->create('verification_codes', function ($table) {
        $table->uuid('id')->primary();
        $table->string('auth_scope');
        $table->string('purpose');
        $table->string('identifier');
        $table->string('code_hash');
        $table->unsignedTinyInteger('attempts')->default(0);
        $table->unsignedTinyInteger('max_attempts');
        $table->timestamp('expires_at');
        $table->timestamp('consumed_at')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    return $capsule;
}

// ── request → event → confirm happy path ───────────────────────────────────

it('E2E: issue -> event captured -> confirm -> password changed', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-1');

    $requestReq = Request::create('/api/test/auth/password/code/request', 'POST');
    $requestReq->setUserResolver(fn () => $user);

    $response = $controller->requestPasswordCode($requestReq);
    expect($response->getStatusCode())->toBe(200);

    Event::assertDispatched(AuthEvent::class, function (AuthEvent $event) {
        return $event->type === 'auth.password_change_code.requested'
            && isset($event->payload['code'])
            && preg_match('/^\d{6}$/', $event->payload['code']) === 1;
    });

    $dispatched = collect(Event::dispatched(AuthEvent::class))->first();
    /** @var AuthEvent $capturedEvent */
    $capturedEvent = $dispatched[0];
    $plainCode = $capturedEvent->payload['code'];

    $confirmReq = Request::create('/api/test/auth/password/code/confirm', 'POST', [
        'code' => $plainCode,
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $confirmReq->setUserResolver(fn () => $user);

    $confirmResponse = $controller->confirmPasswordCode($confirmReq);

    expect($confirmResponse->getStatusCode())->toBe(200);
    expect($user->setPasswordCalls)->toBe(['newSecret123']);

    Event::assertDispatched(AuthEvent::class, fn (AuthEvent $e) => $e->type === 'auth.password_changed');

    $row = DB::table('verification_codes')->where('identifier', 'user-1')->first();
    expect($row->consumed_at)->not->toBeNull();
});

// ── wrong PIN increments attempts without leaking hint ──────────────────────

it('E2E: wrong PIN increments attempts and returns a generic 422 without leaking a hint', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-2');

    $controller->requestPasswordCode(tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)));

    $confirmReq = Request::create('/api/test/auth/password/code/confirm', 'POST', [
        'code' => '000000',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $confirmReq->setUserResolver(fn () => $user);

    $response = $controller->confirmPasswordCode($confirmReq);

    expect($response->getStatusCode())->toBe(422);
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['message'])->toBe('Código inválido.');
    expect($payload['message'])->not->toContain('atte'); // no "attempts remaining" hint
    expect($user->setPasswordCalls)->toBe([]);

    $row = DB::table('verification_codes')->where('identifier', 'user-2')->first();
    expect($row->attempts)->toBe(1);
});

// ── expired / consumed / locked all rejected ────────────────────────────────

it('E2E: expired code is rejected with 410', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-3');

    $controller->requestPasswordCode(tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)));
    DB::table('verification_codes')->where('identifier', 'user-3')->update(['expires_at' => now()->subMinute()]);

    $confirmReq = Request::create('/x', 'POST', [
        'code' => '123456',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $confirmReq->setUserResolver(fn () => $user);

    $response = $controller->confirmPasswordCode($confirmReq);

    expect($response->getStatusCode())->toBe(410);
});

it('E2E: consumed code cannot be reused', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-4');

    $controller->requestPasswordCode(tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)));
    $plainCode = collect(Event::dispatched(AuthEvent::class))->first()[0]->payload['code'];

    $confirm = fn () => $controller->confirmPasswordCode(tap(
        Request::create('/x', 'POST', [
            'code' => $plainCode,
            'password' => 'newSecret123',
            'password_confirmation' => 'newSecret123',
        ]),
        fn ($r) => $r->setUserResolver(fn () => $user),
    ));

    expect($confirm()->getStatusCode())->toBe(200);
    expect($confirm()->getStatusCode())->toBe(422);
});

it('E2E: locked code (attempts exhausted) is rejected with 423', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-5');

    $controller->requestPasswordCode(tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)));
    DB::table('verification_codes')->where('identifier', 'user-5')->update(['max_attempts' => 1, 'attempts' => 1]);

    $confirmReq = Request::create('/x', 'POST', [
        'code' => '000000',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $confirmReq->setUserResolver(fn () => $user);

    $response = $controller->confirmPasswordCode($confirmReq);

    expect($response->getStatusCode())->toBe(423);
});

// ── unauthenticated → 401 on both endpoints ─────────────────────────────────

it('E2E: requestPasswordCode returns 401 when unauthenticated and does NOT generate a code', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();

    $requestReq = Request::create('/x', 'POST');
    $requestReq->setUserResolver(fn () => null);

    $response = $controller->requestPasswordCode($requestReq);

    expect($response->getStatusCode())->toBe(401);
    expect(DB::table('verification_codes')->count())->toBe(0);
    Event::assertNotDispatched(AuthEvent::class);
});

it('E2E: confirmPasswordCode returns 401 when unauthenticated', function () {
    bootOtpE2EContainer();

    $controller = new OtpConcreteAuthController();

    $confirmReq = Request::create('/x', 'POST', [
        'code' => '123456',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $confirmReq->setUserResolver(fn () => null);

    $response = $controller->confirmPasswordCode($confirmReq);

    expect($response->getStatusCode())->toBe(401);
});

// ── password_confirmation mismatch → validation error, password unchanged ──

it('E2E: password_confirmation mismatch fails validation and does NOT consume the code or change the password', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-6');

    $controller->requestPasswordCode(tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)));
    $plainCode = collect(Event::dispatched(AuthEvent::class))->first()[0]->payload['code'];

    $confirmReq = Request::create('/x', 'POST', [
        'code' => $plainCode,
        'password' => 'newSecret123',
        'password_confirmation' => 'DOES-NOT-MATCH',
    ]);
    $confirmReq->setUserResolver(fn () => $user);

    expect(fn () => $controller->confirmPasswordCode($confirmReq))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    expect($user->setPasswordCalls)->toBe([]);
    $row = DB::table('verification_codes')->where('identifier', 'user-6')->first();
    expect($row->consumed_at)->toBeNull();
});

// ── the plaintext PIN never appears in the request-code response body ──────

it('E2E: the plaintext PIN never appears in the requestPasswordCode response body', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-7');

    $response = $controller->requestPasswordCode(
        tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user)),
    );

    $plainCode = collect(Event::dispatched(AuthEvent::class))->first()[0]->payload['code'];
    $body = (string) $response->getContent();

    expect($body)->not->toContain($plainCode);
});

// ── request throttle ─────────────────────────────────────────────────────

it('E2E: a second requestPasswordCode within the throttle window returns 429', function () {
    bootOtpE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new OtpConcreteAuthController();
    $user = new OtpTestUser('user-8');

    $req = fn () => tap(Request::create('/x', 'POST'), fn ($r) => $r->setUserResolver(fn () => $user));

    $first = $controller->requestPasswordCode($req());
    expect($first->getStatusCode())->toBe(200);

    $second = $controller->requestPasswordCode($req());
    expect($second->getStatusCode())->toBe(429);
});
