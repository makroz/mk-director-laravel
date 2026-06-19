<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Services\AbilityResolver;
use Mk\Director\Tests\MkLaravelTestCase;
use Mockery;

/**
 * AbilityResolver test suite — T1.5, T1.6, T1.7.
 *
 * Verifies that the AbilityResolver service caches a user's resolved
 * abilities per-request (TTL configurable) and that a Sanctum token's
 * `tokenCan()` short-circuits before any DB query is issued.
 *
 * NOTE: We avoid extending the package's own AuthUser in tests because the
 * parent class uses the `HasApiTokens` trait from laravel/sanctum, which is
 * NOT a runtime dependency of the package (see composer.json). Resolving
 * the trait throws a fatal Error in unit context. The FakeAuthUser below
 * implements Authenticatable directly and exposes only the surface the
 * resolver actually consumes (getKey, getAuthIdentifierName).
 *
 * @see audit-2026-06-17-R4-001
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Mockery::close();
});

/**
 * Authenticatable stub used by the resolver tests. We deliberately do NOT
 * extend the package's `AuthUser` to avoid dragging laravel/sanctum into
 * the unit test runtime.
 */
final class FakeAuthUser extends Model implements Authenticatable
{
    protected $table = 'fake_auth_users_for_resolver';

    protected $fillable = ['id', 'name'];

    public bool $userBooted = false;

    /**
     * Configurable ability list used by the resolver's underlying-lookup
     * fallback. Setting `$user->directAbilityNames = [...]` controls what
     * the resolver returns when the cache misses and the Sanctum shortcut
     * does not apply.
     */
    public array $directAbilityNames = [];

    public function roles()
    {
        // The resolver's DB-backed path would call ->roles()->pluck('id')
        // here. Returning a value object that exposes pluck() lets us assert
        // the resolver executed the path WITHOUT needing a real DB.
        return new class {
            public function pluck(string $column): \Illuminate\Support\Collection
            {
                return collect();
            }
        };
    }

    public function directAbilities()
    {
        // Lazy-instantiated so the test can configure FakeAuthUser
        // directly via $user->directAbilityNames = [...] and we still
        // return a consistent stub that reflects the configured list.
        $names = $this->directAbilityNames;
        return new class($names) {
            public function __construct(private readonly array $names)
            {
            }

            public function pluck(string $column): \Illuminate\Support\Collection
            {
                return collect($this->names);
            }
        };
    }

    public function abilities()
    {
        return new class {
            public function pluck(string $column): \Illuminate\Support\Collection
            {
                return collect();
            }
        };
    }

    public function getKey()
    {
        return $this->attributes['id'] ?? 'fake-user-key';
    }

    // Authenticatable contract ----------------------------------------------------

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}

function makeResolver(?CacheRepository $cache = null, int $ttl = 300): AbilityResolver
{
    return new AbilityResolver(
        cache: $cache ?? new CacheRepository(new ArrayStore()),
        ttl: $ttl,
    );
}

test('AbilityResolver: first call executes the underlying ability lookup', function () {
    // @see audit-2026-06-17-R4-001 (T1.5)
    $user = new FakeAuthUser(['id' => 'user-1']);
    $user->directAbilityNames = ['users.list', 'users.edit'];

    // Use a recording cache so we can assert what got written.
    $cache = new class extends CacheRepository {
        public array $writes = [];

        public function __construct()
        {
            parent::__construct(new ArrayStore());
        }

        public function put($key, $value, $ttl = null): void
        {
            $this->writes[$key] = $value;
            parent::put($key, $value, $ttl);
        }
    };

    $resolver = makeResolver($cache);

    $result = $resolver->can($user, 'users.edit');

    expect($result)->toBeTrue();
    expect($cache->writes)->not->toBeEmpty();
    expect($cache->writes[AbilityResolver::cacheKey($user)] ?? null)->toBe(['users.list', 'users.edit']);
});

test('AbilityResolver: subsequent call uses cache (no second lookup)', function () {
    // @see audit-2026-06-17-R4-001 (T1.6)
    $user = new FakeAuthUser(['id' => 'user-2']);

    $cache = new CacheRepository(new ArrayStore());

    // Pre-warm cache with the resolved ability list.
    $cache->put(
        AbilityResolver::cacheKey($user),
        ['users.list'],
        300,
    );

    // If the resolver re-queries the DB instead of using the cache,
    // the user's directAbilities()->names is empty and the test fails.
    $user->directAbilityNames = [];

    $resolver = makeResolver($cache);

    expect($resolver->can($user, 'users.list'))->toBeTrue();
});

test('AbilityResolver: Sanctum token with matching ability takes precedence (no DB query)', function () {
    // @see audit-2026-06-17-R4-001 (T1.7) — also covers R2-002 partial path.
    //
    // NOTE: We use an anonymous Authenticatable stub instead of extending
    // AuthUser because the package currently does not require laravel/sanctum
    // (see composer.json). The Sanctum-token short-circuit is exercised via
    // the user model's `currentAccessToken()` returning an object with a
    // `can()` method (PersonalAccessToken contract).
    $token = new class {
        public function can(string $ability): bool
        {
            return $ability === 'users.delete';
        }
    };

    $user = new class($token) implements \Illuminate\Contracts\Auth\Authenticatable {
        public function __construct(private readonly object $token)
        {
        }

        public function currentAccessToken(): ?object
        {
            return $this->token;
        }

        public function getAuthIdentifierName() { return 'id'; }
        public function getAuthIdentifier() { return 'user-sanctum'; }
        public function getAuthPasswordName() { return 'password'; }
        public function getAuthPassword() { return ''; }
        public function getRememberToken() { return ''; }
        public function setRememberToken($value) {}
        public function getRememberTokenName() { return ''; }
    };

    // The user MUST NOT have its abilities queried from any DB-backed source.
    // We verify this by leaving the stub without any roles/abilities methods
    // — if the resolver hits them, PHP throws a fatal Error.

    $cache = new CacheRepository(new ArrayStore());
    $resolver = makeResolver($cache);

    expect($resolver->can($user, 'users.delete'))->toBeTrue();
});

test('AbilityResolver: invalidate clears the cached ability set', function () {
    // Side benefit — invalidation hook used by assignRole/revokeRole/giveAbilityTo (T2.6).
    $user = new FakeAuthUser(['id' => 'user-3']);

    $cache = new CacheRepository(new ArrayStore());
    $cache->put(
        AbilityResolver::cacheKey($user),
        ['old.ability'],
        300,
    );

    $resolver = makeResolver($cache);

    expect($cache->has(AbilityResolver::cacheKey($user)))->toBeTrue();

    $resolver->invalidate($user);

    expect($cache->has(AbilityResolver::cacheKey($user)))->toBeFalse();
});

test('AbilityResolver: respects wildcard "*" and "<resource>.*" patterns', function () {
    $user = new FakeAuthUser(['id' => 'user-4']);
    $user->directAbilityNames = ['*'];

    $resolver = makeResolver();

    expect($resolver->can($user, 'users.delete'))->toBeTrue();
    expect($resolver->can($user, 'anything.at.all'))->toBeTrue();
});

test('AbilityResolver: matches "<resource>.*" wildcard for scoped checks', function () {
    $user = new FakeAuthUser(['id' => 'user-5']);
    $user->directAbilityNames = ['users.*'];

    $resolver = makeResolver();

    expect($resolver->can($user, 'users.list'))->toBeTrue();
    expect($resolver->can($user, 'users.delete'))->toBeTrue();
    expect($resolver->can($user, 'orders.list'))->toBeFalse();
});

test('AbilityResolver: returns false for unknown ability', function () {
    $user = new FakeAuthUser(['id' => 'user-6']);
    $user->directAbilityNames = ['users.list'];

    $resolver = makeResolver();

    expect($resolver->can($user, 'orders.delete'))->toBeFalse();
});

test('AbilityResolver: cacheKey is stable across calls for the same user', function () {
    $user = new FakeAuthUser(['id' => 'stable-key-7']);

    $key1 = AbilityResolver::cacheKey($user);
    $key2 = AbilityResolver::cacheKey($user);

    expect($key1)->toBe($key2);
    expect($key1)->toContain('stable-key-7');
});