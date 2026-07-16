<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
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
 * [SECURITY] E2E (SQLite in-memory + Event::fake) for the two UNAUTHENTICATED
 * OTP password-RESET endpoints (forgot-vía-OTP).
 *
 * Spec: 2026-07-15-profile-edit-password-otp — forgot-vía-OTP.
 *
 * Unlike `AuthPasswordCodeE2ETest` (authenticated flow, identifier from the
 * token, a non-Eloquent Authenticatable double), the reset flow resolves the
 * user by `loginField()` — so this file boots a REAL Eloquent model + table so
 * the `where(loginField, ...)` lookup runs for real. Covers what is NEW here:
 * anti-enumeration (unknown email → generic 200, no event, no code), the
 * loginField lookup, and the verdict→HTTP mapping. The crypto/attempt/expiry
 * invariants themselves are already covered by `EmailOtpServiceTest`.
 */
uses(MkLaravelTestCase::class);

final class ResetConcreteAuthController extends BaseAuthController
{
    protected function authModelClass(): string
    {
        return ResetTestUser::class;
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
 * Real Eloquent model so `authModelClass()::query()->where('email', ...)` works.
 * No `tokens()` relation → the `method_exists($user, 'tokens')` revoke branch
 * is a no-op (no Sanctum table needed).
 */
class ResetTestUser extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;

    protected $table = 'reset_test_users';

    public $timestamps = false;

    protected $guarded = [];

    public function getAuthScope(): ?string
    {
        $scope = $this->getAttribute('auth_scope');

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    public function setAuthPassword(string $password): void
    {
        $this->setAttribute('password', $password);
        $this->save();
    }
}

function bootResetCodeE2EContainer(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    $container = new Container;
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
            ],
        ],
        'cache' => [
            'default' => 'array',
            'stores' => ['array' => ['driver' => 'array']],
        ],
    ]));

    $container->singleton('hash', fn ($app) => new HashManager($app));
    $container->singleton('translator', fn () => new Translator(new ArrayLoader, 'en'));
    $container->singleton('validator', fn ($app) => new ValidationFactory($app['translator'], $app));
    $container->alias('validator', Factory::class);
    $container->singleton('events', fn ($app) => new EventDispatcher($app));
    $container->singleton('cache', fn ($app) => new CacheManager($app));

    $factory = \Mockery::mock(ResponseFactory::class);
    $factory->shouldReceive('json')->andReturnUsing(
        fn ($data = [], $status = 200, array $headers = [], $options = 0) => new JsonResponse($data, $status, $headers, $options),
    );
    $container->instance(ResponseFactory::class, $factory);

    Facade::setFacadeApplication($container);
    Facade::clearResolvedInstances();
    Model::setConnectionResolver($capsule->getDatabaseManager());
    Model::setEventDispatcher($container->make('events'));

    if (! Request::hasMacro('validate')) {
        Request::macro('validate', function (array $rules, ...$params) {
            return validator($this->all(), $rules, ...$params)->validate();
        });
    }

    $schema = $capsule->getConnection()->getSchemaBuilder();

    $schema->create('verification_codes', function ($table) {
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

    $schema->create('reset_test_users', function ($table) {
        $table->increments('id');
        $table->string('email');
        $table->string('password');
        $table->string('auth_scope')->default('test');
    });

    return $capsule;
}

function seedResetUser(string $email, string $scope = 'test'): ResetTestUser
{
    return ResetTestUser::query()->create([
        'email' => $email,
        'password' => 'old-hash',
        'auth_scope' => $scope,
    ]);
}

// ── happy path: request → event(PIN) → confirm → password changed ───────────

it('E2E reset: request -> event captured -> confirm -> password changed', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;
    $user = seedResetUser('jane@example.com');

    $req = Request::create('/x', 'POST', ['email' => 'jane@example.com']);
    expect($controller->requestPasswordResetCode($req)->getStatusCode())->toBe(200);

    Event::assertDispatched(AuthEvent::class, function (AuthEvent $event) {
        return $event->type === 'auth.password_reset_code.requested'
            && preg_match('/^\d{6}$/', (string) ($event->payload['code'] ?? '')) === 1;
    });

    $plainCode = collect(Event::dispatched(AuthEvent::class))->first()[0]->payload['code'];

    $confirm = Request::create('/x', 'POST', [
        'email' => 'jane@example.com',
        'code' => $plainCode,
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    expect($controller->confirmPasswordResetCode($confirm)->getStatusCode())->toBe(200);

    Event::assertDispatched(AuthEvent::class, fn (AuthEvent $e) => $e->type === 'auth.password_reset.success');
    expect($user->fresh()->password)->toBe('newSecret123');
    expect(DB::table('verification_codes')->where('identifier', (string) $user->id)->first()->consumed_at)->not->toBeNull();
});

// ── anti-enumeration on request: unknown email → generic 200, no code, no event ─

it('E2E reset: unknown email returns a generic 200 without issuing a code or event', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;

    $req = Request::create('/x', 'POST', ['email' => 'ghost@example.com']);
    expect($controller->requestPasswordResetCode($req)->getStatusCode())->toBe(200);

    expect(DB::table('verification_codes')->count())->toBe(0);
    Event::assertNotDispatched(AuthEvent::class);
});

// ── anti-enumeration on confirm: unknown email → same generic 422 ──────────────

it('E2E reset: confirm with an unknown email returns the generic 422, not a distinct error', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;

    $confirm = Request::create('/x', 'POST', [
        'email' => 'ghost@example.com',
        'code' => '123456',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    $response = $controller->confirmPasswordResetCode($confirm);

    expect($response->getStatusCode())->toBe(422);
    expect(json_decode((string) $response->getContent(), true)['message'])->toBe('Código inválido.');
});

// ── wrong PIN → 422 generic, password unchanged ───────────────────────────────

it('E2E reset: wrong PIN returns 422 and does not change the password', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;
    $user = seedResetUser('bob@example.com');
    $controller->requestPasswordResetCode(Request::create('/x', 'POST', ['email' => 'bob@example.com']));

    $confirm = Request::create('/x', 'POST', [
        'email' => 'bob@example.com',
        'code' => '000000',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    expect($controller->confirmPasswordResetCode($confirm)->getStatusCode())->toBe(422);
    expect($user->fresh()->password)->toBe('old-hash');
});

// ── expired code → 410 ────────────────────────────────────────────────────────

it('E2E reset: expired code is rejected with 410', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;
    $user = seedResetUser('amy@example.com');
    $controller->requestPasswordResetCode(Request::create('/x', 'POST', ['email' => 'amy@example.com']));
    DB::table('verification_codes')->where('identifier', (string) $user->id)->update(['expires_at' => now()->subMinute()]);

    $confirm = Request::create('/x', 'POST', [
        'email' => 'amy@example.com',
        'code' => '123456',
        'password' => 'newSecret123',
        'password_confirmation' => 'newSecret123',
    ]);
    expect($controller->confirmPasswordResetCode($confirm)->getStatusCode())->toBe(410);
});

// ── the plaintext PIN never appears in the request response body ──────────────

it('E2E reset: the plaintext PIN never appears in the request response body', function () {
    bootResetCodeE2EContainer();
    Event::fake([AuthEvent::class]);

    $controller = new ResetConcreteAuthController;
    seedResetUser('kim@example.com');
    $response = $controller->requestPasswordResetCode(Request::create('/x', 'POST', ['email' => 'kim@example.com']));

    $plainCode = collect(Event::dispatched(AuthEvent::class))->first()[0]->payload['code'];
    expect((string) $response->getContent())->not->toContain($plainCode);
});
