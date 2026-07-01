<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mk\Director\Auth\Middleware\MkAbility;
use Mk\Director\Tests\MkLaravelTestCase;
use Mockery;

/**
 * Verifies MkAbility middleware behavior:
 *  - R2-003: empty abilities list returns 500 ERR_MIDDLEWARE_MISCONFIGURED
 *  - R2-002: Sanctum token's can($ability) short-circuits the role check
 *  - OR semantics: any matching ability passes
 *  - 401 on no user
 *  - 403 on no match
 *
 * We exercise the middleware against an anonymous Authenticatable stub
 * because the package's AuthUser requires laravel/sanctum (not in
 * composer.json). Tests assert the JSON shape and HTTP status; the
 * internal AbilityResolver delegation is covered by HasAbilitiesDelegationTest.
 *
 * @see audit-2026-06-17-R2-002, audit-2026-06-17-R2-003
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Build a User stub with optional canMk() and Sanctum-style token.
 */
function makeUser(?array $canMkResult = null, ?object $token = null): object
{
    $tokenProp = $token;
    return new class($canMkResult, $tokenProp) implements \Illuminate\Contracts\Auth\Authenticatable {
        public function __construct(
            private readonly ?array $canMkResult,
            private readonly ?object $token,
        ) {}

        public function currentAccessToken(): ?object
        {
            return $this->token;
        }

        public function canMk(string $ability): bool
        {
            return in_array($ability, $this->canMkResult ?? [], true);
        }

        public function getAuthIdentifierName(): string { return 'id'; }
        public function getAuthIdentifier(): mixed { return 'fake-user'; }
        public function getAuthPasswordName(): string { return 'password'; }
        public function getAuthPassword(): string { return ''; }
        public function getRememberToken(): string { return ''; }
        public function setRememberToken($value): void {}
        public function getRememberTokenName(): string { return ''; }
    };
}

function middleware(): MkAbility
{
    return new MkAbility();
}

test('MkAbility returns 500 ERR_MIDDLEWARE_MISCONFIGURED when no abilities are passed', function () {
    $request = Request::create('/api/v1/foo', 'GET');
    $user = makeUser([]);
    $request->setUserResolver(fn () => $user);

    $response = middleware()->handle($request, fn () => 'next', '');

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(500);

    $data = json_decode($response->getContent(), true);
    // R-PKG-044: shape is now single-level envelope with __extraData.code
    expect($data['__extraData']['code'])->toBe('ERR_MIDDLEWARE_MISCONFIGURED');
    expect($data['success'])->toBeFalse();
});

test('MkAbility returns 500 when the only argument is a comma string with no entries', function () {
    $request = Request::create('/api/v1/foo', 'GET');
    $user = makeUser([]);
    $request->setUserResolver(fn () => $user);

    $response = middleware()->handle($request, fn () => 'next', ',,,  , ');

    expect($response->getStatusCode())->toBe(500);
});

test('MkAbility returns 401 when no user is on the request', function () {
    $request = Request::create('/api/v1/foo', 'GET');
    $request->setUserResolver(fn () => null);

    $response = middleware()->handle($request, fn () => 'next', 'users.edit');

    expect($response)->toBeInstanceOf(JsonResponse::class);
    expect($response->getStatusCode())->toBe(401);
});

test('MkAbility grants access via Sanctum token can() without invoking canMk', function () {
    $request = Request::create('/api/v1/users/42', 'DELETE');
    $tokenGranted = false;

    $token = new class($tokenGranted) {
        public function __construct(private bool &$tokenGranted) {}
        public function can(string $ability): bool
        {
            if ($ability === 'users.delete') {
                $this->tokenGranted = true;
                return true;
            }
            return false;
        }
    };

    $user = makeUser(canMkResult: [], token: $token);
    $request->setUserResolver(fn () => $user);

    $nextCalled = false;
    $response = middleware()->handle($request, function () use (&$nextCalled) {
        $nextCalled = true;
        return 'passed';
    }, 'users.delete');

    expect($response)->toBe('passed');
    expect($nextCalled)->toBeTrue();
    expect($tokenGranted)->toBeTrue('token->can() should be invoked and return true');
});

test('MkAbility falls through to canMk when token does not grant the ability', function () {
    $request = Request::create('/api/v1/users', 'GET');
    $token = new class {
        public function can(string $ability): bool { return false; } // token denies
    };

    $user = makeUser(canMkResult: ['users.list'], token: $token);
    $request->setUserResolver(fn () => $user);

    $nextCalled = false;
    $response = middleware()->handle($request, function () use (&$nextCalled) {
        $nextCalled = true;
        return 'next';
    }, 'users.list');

    expect($response)->toBe('next');
    expect($nextCalled)->toBeTrue();
});

test('MkAbility OR semantics: any matching ability passes', function () {
    $request = Request::create('/api/v1/users', 'GET');
    $user = makeUser(canMkResult: ['users.edit']); // does NOT have users.delete
    $request->setUserResolver(fn () => $user);

    $nextCalled = false;
    $response = middleware()->handle($request, function () use (&$nextCalled) {
        $nextCalled = true;
        return 'next';
    }, 'users.delete', 'users.edit');

    expect($response)->toBe('next');
    expect($nextCalled)->toBeTrue();
});

test('MkAbility returns 403 when no ability matches', function () {
    $request = Request::create('/api/v1/foo', 'GET');
    $user = makeUser(canMkResult: ['users.list']);
    $request->setUserResolver(fn () => $user);

    $response = middleware()->handle($request, fn () => 'next', 'orders.delete');

    expect($response->getStatusCode())->toBe(403);
    $data = json_decode($response->getContent(), true);
    expect($data['message'])->toBe('Forbidden.');
    // R-PKG-044: single-level envelope
    expect($data['__extraData']['code'])->toBe('ERR_FORBIDDEN');
});

test('MkAbility accepts comma-separated ability strings (single argument)', function () {
    $request = Request::create('/api/v1/users', 'GET');
    $user = makeUser(canMkResult: ['users.delete']);
    $request->setUserResolver(fn () => $user);

    $response = middleware()->handle($request, fn () => 'next', 'users.list,users.delete');

    expect($response)->toBe('next');
});

test('MkAbility swallows exceptions from token->can() and falls through to canMk', function () {
    $request = Request::create('/api/v1/users', 'GET');

    $token = new class {
        public function can(string $ability): bool
        {
            throw new \RuntimeException('token backend offline');
        }
    };

    $user = makeUser(canMkResult: ['users.list'], token: $token);
    $request->setUserResolver(fn () => $user);

    $response = middleware()->handle($request, fn () => 'next', 'users.list');

    expect($response)->toBe('next');
});