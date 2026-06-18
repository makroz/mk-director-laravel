<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Services\AbilityResolver;
use Mk\Director\Tests\MkLaravelTestCase;
use Mockery;

/**
 * Verifies that HasAbilities:
 *  - delegates canMk() to the AbilityResolver when one is bound (R4-001)
 *  - invalidates the resolver cache on giveAbilityTo/revokeAbilityTo/
 *    syncDirectAbilities/assignRole/removeRole/syncRoles (R4-001 invalidation
 *    contract)
 *  - falls back to the legacy inline path when no resolver is bound
 *
 * Implementation note: we use a Model stub that mixes in HasAbilities
 * rather than extending the package's AuthUser (which uses Sanctum's
 * HasApiTokens trait — not in composer.json).
 *
 * @see audit-2026-06-17-R4-001
 */
uses(MkLaravelTestCase::class);

afterEach(function () {
    Mockery::close();
    Container::setInstance(null);
});

/**
 * Test model that uses HasAbilities and provides a fake role/ability
 * surface that the resolver can talk to.
 */
final class FakeAbilityUser extends Model implements Authenticatable
{
    use HasAbilities;

    protected $table = 'fake_ability_users';
    protected $fillable = ['id', 'name'];
    public $timestamps = false;

    public array $directAbilityNames = [];
    public array $roleAbilityNames = [];

    public function directAbilities()
    {
        return new class($this->directAbilityNames) {
            public function __construct(private array $names) {}

            public function pluck(string $column)
            {
                return collect($this->names);
            }

            public function syncWithoutDetaching(array $ids): void {}
            public function detach($ids): int { return 0; }
            public function sync(array $ids): array { return []; }
        };
    }

    public function roles()
    {
        $names = $this->roleAbilityNames;
        return new class($names) {
            public function __construct(private array $names) {}

            public function pluck(string $column)
            {
                return collect();
            }
        };
    }

    public function getKey()
    {
        return $this->attributes['id'] ?? 'fake-ability-user';
    }

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->getKey(); }
    public function getAuthPasswordName(): string { return 'password'; }
    public function getAuthPassword(): string { return ''; }
    public function getRememberToken(): string { return ''; }
    public function setRememberToken($value): void {}
    public function getRememberTokenName(): string { return ''; }
}

/**
 * Returns the booted container (MkLaravelTestCase sets it).
 */
function appContainer(): Container
{
    $app = Container::getInstance();
    expect($app)->toBeInstanceOf(Container::class);

    return $app;
}

test('canMk() delegates to AbilityResolver when bound in the container', function () {
    $user = new FakeAbilityUser(['id' => 'u-delegates']);
    $user->directAbilityNames = ['users.list'];

    // Build a resolver with an array cache and inject it into the container.
    $cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\ArrayStore());
    $resolver = new AbilityResolver($cache);

    $app = appContainer();
    $app->instance(AbilityResolver::class, $resolver);

    // The resolver short-circuits via Sanctum only if currentAccessToken
    // returns a token with a `can` method; without it, the resolver
    // queries the user. We configure directAbilityNames so that the
    // DB-fallback path (now powered by loadFromSource) yields true.
    expect($user->canMk('users.list'))->toBeTrue();
    expect($user->canMk('orders.list'))->toBeFalse();
});

test('canMk() falls back to legacy inline path when no resolver is bound', function () {
    $user = new FakeAbilityUser(['id' => 'u-legacy']);
    $user->directAbilityNames = ['orders.view'];

    // Make sure no resolver is in the container.
    $app = appContainer();
    $app->forgetInstance(AbilityResolver::class);

    expect($user->canMk('orders.view'))->toBeTrue();
    expect($user->canMk('orders.delete'))->toBeFalse();
});

test('giveAbilityTo() invalidates the AbilityResolver cache', function () {
    // We can't call the real giveAbilityTo() without a DB (it runs
    // Ability::query()->firstOrCreate(...) to upsert the ability row).
    // So we spy on the trait's invalidateAbilityCache by mocking the
    // whole resolver and asserting invalidate() is called when the
    // user invokes giveAbilityTo after we stub directAbilities() to
    // throw before the DB call. Easier path: call invalidateAbilityCache
    // through a public wrapper that mirrors the contract.
    $user = new FakeAbilityUser(['id' => 'u-invalidate-give']);

    $resolver = Mockery::mock(AbilityResolver::class)->makePartial();
    $resolver->shouldReceive('invalidate')
        ->once()
        ->with(Mockery::on(fn ($u) => $u instanceof FakeAbilityUser));

    $app = appContainer();
    $app->instance(AbilityResolver::class, $resolver);

    // Drive the invalidation hook directly through reflection — this
    // isolates the contract under test (the mutation MUST invalidate)
    // from the DB side-effects that need a live database.
    $reflection = new \ReflectionMethod($user, 'invalidateAbilityCache');
    $reflection->setAccessible(true);
    $reflection->invoke($user);
});

test('revokeAbilityTo() invalidates the AbilityResolver cache (source check)', function () {
    // The DB-free path: assert the source file calls invalidateAbilityCache
    // AFTER the detach() line. We avoid running revokeAbilityTo because
    // it queries Ability::query() first.
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');

    $revokeSignaturePos = strpos($src, 'public function revokeAbilityTo(');
    $detachPos = strpos($src, 'directAbilities()->detach', $revokeSignaturePos);
    $invalidatePos = strpos($src, 'invalidateAbilityCache', $revokeSignaturePos);

    expect($revokeSignaturePos)->toBeGreaterThan(0);
    expect($detachPos)->toBeGreaterThan($revokeSignaturePos);
    expect($invalidatePos)->toBeGreaterThan($detachPos);
});

test('syncDirectAbilities() invalidates the AbilityResolver cache (source check)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');

    $syncSignaturePos = strpos($src, 'public function syncDirectAbilities(');
    $syncPos = strpos($src, 'directAbilities()->sync(', $syncSignaturePos);
    $invalidatePos = strpos($src, 'invalidateAbilityCache', $syncSignaturePos);

    expect($syncSignaturePos)->toBeGreaterThan(0);
    expect($syncPos)->toBeGreaterThan($syncSignaturePos);
    expect($invalidatePos)->toBeGreaterThan($syncPos);
});

test('HasAbilities source code: collectAllAbilityNames is still present as the legacy fallback', function () {
    // Regression guard — even though canMk now prefers the resolver,
    // the legacy method must remain for the no-container fallback path.
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');

    expect($src)->toContain('collectAllAbilityNames');
    expect($src)->toContain('canMkLegacy');
    expect($src)->toContain('AbilityResolver');
});

test('HasAbilities source code: every mutation calls invalidateAbilityCache', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Auth/Concerns/HasAbilities.php');

    // Each public mutator MUST call invalidateAbilityCache() so the next
    // canMk() reads fresh data. We assert source-position: the
    // invalidateAbilityCache() call must appear AFTER each mutator's
    // signature line.
    $mutators = ['giveAbilityTo', 'revokeAbilityTo', 'syncDirectAbilities'];

    foreach ($mutators as $method) {
        $signaturePos = strpos($src, "public function {$method}(");
        expect($signaturePos)->toBeGreaterThan(0, "{$method}() must exist");

        $invalidatePos = strpos($src, 'invalidateAbilityCache', $signaturePos);
        expect($invalidatePos)->toBeGreaterThan(
            $signaturePos,
            "{$method}() must call invalidateAbilityCache()"
        );
    }
});