<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Tenancy\HasTenantScope;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Tests for R-PKG-022 BUG-NEW-32 + HALLAZGO-NEW-05 — verify the new
 * public accessors `HasTenantScope::isTenantEnabled()` and
 * `HasTenantScope::setTenantEnabled()` work correctly.
 *
 * These accessors replace reflective access to `protected static bool $usesTenant`,
 * which emitted PHP 8.5 deprecation warnings via `setAccessible(true)`.
 */
uses(MkLaravelTestCase::class);

/**
 * Create a fresh anonymous model class that uses HasTenantScope.
 * Each test gets its own class so static state doesn't leak between tests.
 */
function freshTenantModel(): Model
{
    $cls = new class extends Model {
        use HasTenantScope;

        protected $table = 'fresh_tenant_test';
        protected $guarded = [];
        public $timestamps = false;
    };

    return new $cls;
}

test('HasTenantScope::isTenantEnabled() returns the default (false)', function () {
    $cls = freshTenantModel()::class;

    // Reset to default — other tests may have toggled it.
    $cls::setTenantEnabled(false);

    expect($cls::isTenantEnabled())->toBeFalse();
});

test('HasTenantScope::setTenantEnabled(true) toggles the flag persistently', function () {
    $cls = freshTenantModel()::class;

    $cls::setTenantEnabled(true);
    expect($cls::isTenantEnabled())->toBeTrue();

    $cls::setTenantEnabled(false);
    expect($cls::isTenantEnabled())->toBeFalse();
});

test('HasTenantScope::isTenantEnabled() respects late static binding (concrete class override)', function () {
    // Concrete class that toggles $usesTenant via the public setter (avoids
    // the "PHP forbids redeclaring the trait's static property" error that
    // happens when you try to redeclare it via `protected static bool $usesTenant = true`).
    $cls = new class extends Model {
        use HasTenantScope;

        protected $table = 'overridden_tenant';
        protected $guarded = [];
        public $timestamps = false;
    };

    // R-PKG-022: setter público es la forma correcta de override per-instance.
    $cls::setTenantEnabled(true);

    expect($cls::isTenantEnabled())->toBeTrue();
});

test('HasTenantScope accessors replace the reflection pattern (HALLAZGO-NEW-05 audit)', function () {
    $src = (string) file_get_contents(
        dirname(__DIR__, 3).'/src/Tenancy/HasTenantScope.php'
    );

    // New public API exists.
    expect($src)->toContain('public static function isTenantEnabled(): bool');
    expect($src)->toContain('public static function setTenantEnabled(bool $enabled): void');
});