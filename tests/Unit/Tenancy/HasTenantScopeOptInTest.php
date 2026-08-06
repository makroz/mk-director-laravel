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
 * 🔴 ACÁ VIVÍA UNA LIMITACIÓN DEL LENGUAJE QUE ERA, EN REALIDAD, UN BUG.
 *
 * Este docblock explicaba que "una clase que usa un trait NO PUEDE redeclarar
 * la propiedad estática del trait con otro default", y de ahí deducía que había
 * que testear el opt-in por reflexión y por posición en el source, porque
 * declararlo de verdad era imposible.
 *
 * La premisa era cierta y la conclusión estaba al revés: si el camino que
 * documenta el trait no compila, lo que hay que cambiar es el trait, no el
 * test. El trait ya no declara `$usesTenant`, así que el modelo puede
 * declararla y el ejemplo de la doc funciona. El caso positivo se prueba
 * ejecutándolo, en `tests/Feature/Tenancy/HasTenantScopeDocumentedOptInTest.php`.
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

test('🔴 el trait NO declara $usesTenant — declararla hacía imposible el opt-in documentado', function () {
    // Antes se afirmaba lo contrario: que el trait TENÍA la propiedad con
    // default false. Y ahí estaba el bug: si el trait la declara, el modelo no
    // puede redeclararla con `= true` (fatal de composición de traits), o sea
    // que el único camino documentado para prender el aislamiento no compilaba.
    // Ver tests/Feature/Tenancy/HasTenantScopeDocumentedOptInTest.php.
    $traitReflection = new \ReflectionClass(HasTenantScope::class);

    expect($traitReflection->hasProperty('usesTenant'))->toBeFalse();
});

test('el default sigue siendo OFF: agregar el trait solo no prende nada (R2-006)', function () {
    $model = new class extends Model
    {
        use HasTenantScope;

        protected $table = 'fake_default_off_anon';
    };

    expect($model::isTenantEnabled())->toBeFalse();
});

test('bootHasTenantScope short-circuits cuando el modelo no optó', function () {
    enableTenantFeature();

    // Create a model class dynamically that does NOT override the
    // $usesTenant default. We cannot use a `final class` here because
    // PHP rejects redeclaration; we use an anonymous class extending
    // Model and using the trait.
    $model = new class extends Model
    {
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

test('bootHasTenantScope chequea el opt-in del modelo ANTES que el flag global', function () {
    $src = (string) file_get_contents(__DIR__.'/../../../src/Tenancy/HasTenantScope.php');

    $optInCheck = strpos($src, 'if (! static::isTenantEnabled())');
    $tenantEnabledCheck = strpos($src, 'self::tenantEnabled()');

    expect($optInCheck)->toBeGreaterThan(0);
    expect($tenantEnabledCheck)->toBeGreaterThan(0);

    // El opt-in del modelo va primero para que pueda optar por afuera
    // independientemente del flag global de config.
    expect($optInCheck)->toBeLessThan($tenantEnabledCheck);
});

test('isTenantEnabled() lee la propiedad del modelo con property_exists (el trait ya no la declara)', function () {
    $src = (string) file_get_contents(__DIR__.'/../../../src/Tenancy/HasTenantScope.php');

    expect($src)->toContain("property_exists(static::class, 'usesTenant')");

    // Que el trait NO declare la propiedad se afirma por REFLEXIÓN, arriba en
    // este mismo archivo. Un `not->toContain` sobre el .php diría que falla por
    // el docblock que EXPLICA por qué se sacó — grepear no distingue código de
    // comentario, y la reflexión sí.
});

test('HasTenantScope source: when both $usesTenant and tenantEnabled are true, the scope IS registered', function () {
    // Verify the boot() code path includes a TenantScope::class registration.
    $src = (string) file_get_contents(__DIR__.'/../../../src/Tenancy/HasTenantScope.php');

    $addScopePos = strpos($src, "static::addGlobalScope('tenant'");
    expect($addScopePos)->toBeGreaterThan(0);

    // It must reference TenantScope (the actual scope class).
    // R-PKG-027 note: Pint's `new_with_parentheses` rule normaliza
    // `new TenantScope()` to `new TenantScope` when no constructor args.
    // Aceptamos ambos formatos via regex `\b` (word boundary).
    expect($src)->toMatch('/new\s+TenantScope\b/');

    // 🔴 Los dos guards tienen que ESTAR, no sólo estar antes. Antes esto se
    // afirmaba sólo con `expect($pos)->toBeLessThan($addScopePos)`, y cuando
    // `strpos` no encontraba nada devolvía `false` — que en una comparación
    // laxa es menor que cualquier entero. La aserción pasaba justamente en el
    // caso en que el guard NO existía.
    $optInPos = strpos($src, 'if (! static::isTenantEnabled())');
    $tenantEnabledPos = strpos($src, 'if (! self::tenantEnabled())');

    expect($optInPos)->toBeGreaterThan(0);
    expect($tenantEnabledPos)->toBeGreaterThan(0);
    expect($optInPos)->toBeLessThan($addScopePos);
    expect($tenantEnabledPos)->toBeLessThan($addScopePos);
});
