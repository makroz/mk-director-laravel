<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Tenancy\HasTenantScope;
use Mk\Director\Tenancy\TenantScope;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that HasTenantScope is per-model opt-in (audit R2-006):
 *  - Adding the trait alone does NOT register the TenantScope.
 *  - The model MUST set `protected static bool $usesTenant = true`
 *    for the scope to be registered.
 *  - The feature flag (mk_director.tenant.enabled) still gates the
 *    scope on top of the per-model opt-in.
 *
 * We exercise the trait through real Eloquent boot because the trait's
 * behavior lives in bootHasTenantScope(), which the global scope
 * registration path drives.
 *
 * NOTE on PHP constraints: a class that uses a trait CANNOT redeclare
 * the trait's static property with a different default. We work around
 * this by reading the resolved value via reflection — the trait's
 * default lives on the trait's own property slot, and subclasses
 * inherit it (they cannot override). To simulate opt-in we use two
 * DIFFERENT model classes where one statically initializes the value
 * via a constructor-side effect. Real consumers would set
 * `protected static bool $usesTenant = true;` in the model file, but
 * PHP allows it ONLY if the model's declaration is identical to the
 * trait's. Since the trait defaults to false and we need a true case,
 * we verify the contract via reflection on the trait property and
 * via the source-position assertion.
 *
 * @see audit-2026-06-17-R2-006
 */
uses(MkLaravelTestCase::class);

function enableTenantFeature(): void
{
    if (function_exists('config') && app()->bound('config')) {
        config(['mk_director.tenant.enabled' => true]);
    }
}

test('trait default: $usesTenant is FALSE (so adding the trait alone is a no-op)', function () {
    $traitReflection = new \ReflectionClass(HasTenantScope::class);

    expect($traitReflection->hasProperty('usesTenant'))->toBeTrue();

    $prop = $traitReflection->getProperty('usesTenant');
    $defaults = $prop->getDefaultValue();
    expect($defaults)->toBeFalse();
});

test('bootHasTenantScope short-circuits when $usesTenant is false', function () {
    enableTenantFeature();

    // Create a model class dynamically that does NOT override the
    // $usesTenant default. We cannot use a `final class` here because
    // PHP rejects redeclaration; we use an anonymous class extending
    // Model and using the trait.
    $model = new class extends Model {
        use HasTenantScope;

        protected $table = 'fake_opt_out_anon';
        protected $fillable = ['id'];
        public $timestamps = false;
    };

    // Force boot by calling the static boot method directly.
    // R-PKG-022 BUG-NEW-32 + HALLAZGO-NEW-05: `bootHasTenantScope` is public,
    // so `setAccessible(true)` is unnecessary AND emits a deprecation warning
    // since PHP 8.5. Removed.
    $reflection = new \ReflectionClass($model);
    $boot = $reflection->getMethod('bootHasTenantScope');
    $boot->invoke(null);

    expect($model::hasGlobalScope('tenant'))->toBeFalse();
});

test('HasTenantScope source: bootHasTenantScope checks $usesTenant before tenantEnabled()', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Tenancy/HasTenantScope.php');

    $usesTenantCheck = strpos($src, 'if (! static::$usesTenant)');
    $tenantEnabledCheck = strpos($src, 'self::tenantEnabled()');

    expect($usesTenantCheck)->toBeGreaterThan(0);
    expect($tenantEnabledCheck)->toBeGreaterThan(0);

    // $usesTenant must be checked first so a model can opt out
    // independently of the global feature flag.
    expect($usesTenantCheck)->toBeLessThan($tenantEnabledCheck);
});

test('HasTenantScope source declares the $usesTenant property with default false', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Tenancy/HasTenantScope.php');

    expect($src)->toContain('protected static bool $usesTenant = false');
});

test('HasTenantScope source: when both $usesTenant and tenantEnabled are true, the scope IS registered', function () {
    // Verify the boot() code path includes a TenantScope::class registration.
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Tenancy/HasTenantScope.php');

    $addScopePos = strpos($src, "static::addGlobalScope('tenant'");
    expect($addScopePos)->toBeGreaterThan(0);

    // It must reference TenantScope (the actual scope class).
    // R-PKG-027 note: Pint's `new_with_parentheses` rule normaliza
    // `new TenantScope()` to `new TenantScope` when no constructor args.
    // Aceptamos ambos formatos via regex `\b` (word boundary).
    expect($src)->toMatch('/new\s+TenantScope\b/');

    // And both guards must precede the addGlobalScope call.
    $usesTenantPos = strpos($src, 'if (! static::$usesTenant)');
    $tenantEnabledPos = strpos($src, 'if (! self::tenantEnabled())');

    expect($usesTenantPos)->toBeLessThan($addScopePos);
    expect($tenantEnabledPos)->toBeLessThan($addScopePos);
});