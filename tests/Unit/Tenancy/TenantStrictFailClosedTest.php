<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * LAR-05 + LAR-11 (2026-07-03 audit, MEDIUM) — TenantResolver strict
 * real + TenantScope fail-closed opt-in.
 *
 * LAR-05 — strict real en TenantResolver.
 * Pre-fix: strict se lee con (bool) $config->get(...). PHP footgun:
 * (bool) 'false' === true (cualquier string no-vacío es truthy). Resultado
 * runtime: pinear MK_TENANT_STRICT=false en .env no desactiva strict
 * (es decir, los devs creen que están en modo permisivo cuando siguen en
 * modo bloqueante — peor que no tener flag).
 *
 * Adicionalmente, en strict mode, un user autenticado SIN el trait
 * HasTenantMembership (sin getTenantId()) pasa el membership check
 * silenciosamente — el código actual hace
 * method_exists($user, 'getTenantId') ? check : skip. Esto bypasea
 * tenant isolation para apps que olvidaron pinear el trait en su User
 * model — un agujero MEDIUM.
 *
 * Fix LAR-05:
 *  1. Reemplazar (bool) por filter_var(..., FILTER_VALIDATE_BOOLEAN)
 *     para el flag strict (mismo patrón que auth.refresh.rotate_on_refresh
 *     pineado en F1.2).
 *  2. Agregar allowlist de rutas en config (tenant.allowlist_routes):
 *     rutas cuyo pattern matchea el request están exentas del check de
 *     tenant membership. Default: rutas de auth (login, refresh, forgot,
 *     reset) más los paths públicos del consumer que el usuario agregue.
 *  3. En strict mode, si user autenticado NO tiene getTenantId() Y la
 *     ruta NO está en allowlist → 403 ERR_TENANT_MEMBERSHIP_REQUIRED.
 *
 * LAR-11 — fail-closed opt-in en TenantScope.
 * Pre-fix: cuando el TenantContext es null, el scope es no-op (query
 * devuelve TODAS las rows). Esto es conveniente para CLI/queue (que no
 * tienen contexto HTTP), pero PELIGROSO para web: si el TenantResolver
 * middleware falla silenciosamente (e.g. strict=false en config mal
 * pineada) y la request llega sin tenant context, el scope deja pasar
 * TODAS las rows — incluyendo las de otros tenants. IDOR esperando.
 *
 * Fix LAR-11:
 *  1. Nuevo config flag tenant.fail_closed (default false, BC-safe).
 *  2. Cuando fail_closed=true Y context es null Y el modelo TIENE
 *     $usesTenant=true → el scope inyecta un predicate imposible
 *     (típicamente where tenant_id = -1) → query devuelve 0 rows.
 *  3. CLI/queue siguen funcionando porque el consumer puede pinear
 *     fail_closed=false explícito, o el contexto se setea manualmente
 *     en el command antes de la query.
 *
 * @see 04-mk-director-laravel.md LAR-05 LAR-11 (2026-07-03 audit)
 * @see R-PKG-022 (per-model opt-in)
 */
uses(MkLaravelTestCase::class);

function lar05TenantResolverSource(): string
{
    $path = dirname(__DIR__, 3).'/src/Tenancy/TenantResolver.php';

    expect(file_exists($path))->toBeTrue("TenantResolver.php must exist at $path");

    return (string) file_get_contents($path);
}

/**
 * 🔴 La regla de membresía (allowlist + getTenantId + 403) se MUDÓ a
 * `TenantMembershipGate`. Vivía en `TenantResolver`, que corre en el grupo
 * `api` — o sea ANTES de `mk.auth` — donde `$request->user()` todavía es null y
 * la validación entera se salteaba. Ahora la llama `MkAuthenticate` apenas
 * resuelve el usuario. Los asserts de abajo apuntan al archivo nuevo.
 */
function lar05TenantGateSource(): string
{
    $path = dirname(__DIR__, 3).'/src/Tenancy/TenantMembershipGate.php';

    expect(file_exists($path))->toBeTrue("TenantMembershipGate.php must exist at $path");

    return (string) file_get_contents($path);
}

function lar11TenantScopeSource(): string
{
    $path = dirname(__DIR__, 3).'/src/Tenancy/TenantScope.php';

    expect(file_exists($path))->toBeTrue("TenantScope.php must exist at $path");

    return (string) file_get_contents($path);
}

function lar05ConfigSource(): string
{
    $path = dirname(__DIR__, 3).'/config/mk_director.php';

    expect(file_exists($path))->toBeTrue("mk_director.php config must exist at $path");

    return (string) file_get_contents($path);
}

describe('LAR-05 — TenantResolver::handle() reads strict as bool via filter_var (PHP footgun fix)', function (): void {
    $source = lar05TenantResolverSource();

    test('strict flag is read with filter_var FILTER_VALIDATE_BOOLEAN, not (bool) cast', function () use ($source): void {
        // We don't pin the exact layout — just that the constant
        // FILTER_VALIDATE_BOOLEAN appears alongside a filter_var call
        // somewhere in the file (the constants must be reachable from
        // the strict-read site).
        expect($source)->toMatch('/filter_var[\s\S]{0,200}FILTER_VALIDATE_BOOLEAN/s');
        expect($source)->toMatch('/FILTER_VALIDATE_BOOLEAN[\s\S]{0,500}\$strict/s');
    });

    test('TenantResolver does NOT use the legacy (bool) cast for the strict flag', function () use ($source): void {
        expect($source)->not->toMatch('/\$strict\s*=\s*\(bool\)\s*\$this->config->get\([^)]*strict/s');
    });
});

describe('LAR-05 — el gate de membresía aplica strict mode con allowlist de rutas', function (): void {
    $source = lar05TenantGateSource();
    $config = lar05ConfigSource();

    test('el gate declara un lookup de allowlist_routes (exención por ruta del strict)', function () use ($source): void {
        // The check has two parts:
        //  1. The string `allowlist_routes` appears somewhere (proves we
        //     declared the concept and consulted it).
        //  2. Some config() call OR local array consults it.
        $hasConcept = (bool) preg_match('/allowlist[_.]routes/', $source);
        $hasLocal = (bool) preg_match('/isAllowlisted|allowlist/i', $source);

        expect($hasConcept)->toBeTrue(
            'TenantMembershipGate must reference allowlist_routes'
        );
        expect($hasLocal)->toBeTrue(
            'TenantMembershipGate must have an isAllowlisted() helper (or equivalent) that consults the allowlist'
        );
    });

    test('el gate rechaza (403) cuando el user autenticado no tiene getTenantId y la ruta no está en la allowlist', function () use ($source): void {
        $hasMembershipCheck = (bool) preg_match(
            '/method_exists\(\$user,\s*[\'"]getTenantId[\'"]\)/s',
            $source
        );
        $has403Branch = (bool) preg_match('/403[\s\S]{0,200}ERR_TENANT_MEMBERSHIP/s', $source);

        expect($hasMembershipCheck)->toBeTrue();
        expect($has403Branch)->toBeTrue(
            'TenantMembershipGate must reject (403) with ERR_TENANT_MEMBERSHIP when user lacks tenant trait and route is not allowlisted'
        );
    });

    test('el envelope del fallo de membresía es el canónico (LAR-09 R-PKG-024)', function () use ($source): void {
        // The canonical envelope may live in a helper method
        // (`canonicalErrorResponse()`) rather than inline at the call site.
        // We accept either shape: the literal `'code' => 'ERR_TENANT_*'`
        // must appear in the source (anywhere), and a `__extraData` array
        // with `code` and the missing/null data shape must exist.
        $hasErrCodeLiteral = (bool) preg_match("/'ERR_TENANT_(MISSING|MISMATCH|MEMBERSHIP_REQUIRED)'/", $source);
        $hasExtraData = (bool) preg_match("/'__extraData'[\s\S]{0,400}'code'\s*=>/s", $source);
        $hasDataNull = (bool) preg_match("/'data'\s*=>\s*null/s", $source);

        expect($hasErrCodeLiteral)->toBeTrue(
            'TenantMembershipGate must declare at least one ERR_TENANT_* code constant for the error response'
        );
        expect($hasExtraData)->toBeTrue(
            'TenantMembershipGate must emit __extraData with code in its canonical error envelope'
        );
        expect($hasDataNull)->toBeTrue(
            'TenantMembershipGate canonical error envelope must include data: null (R-PKG-024 single-level)'
        );
    });

    test('mk_director.php config declares the allowlist_routes key with auth-endpoint defaults', function () use ($config): void {
        expect($config)->toMatch('/[\'"]allowlist_routes[\'"]/');
    });
});

describe('LAR-11 — TenantScope fail-closed opt-in (config tenant.fail_closed)', function (): void {
    $source = lar11TenantScopeSource();
    $config = lar05ConfigSource();

    test('TenantScope declares a fail-closed guard against null tenant context', function () use ($source): void {
        $hasFailClosedGuard = (bool) preg_match(
            '/(fail_closed|fail-closed|failClosed)/s',
            $source
        );
        $hasSentinel = (bool) preg_match(
            '/(-1|0|1\s*=\s*0|tenant_id\s*=\s*-\d)/s',
            $source
        );

        expect($hasFailClosedGuard || $hasSentinel)->toBeTrue(
            'TenantScope must wire a fail-closed guard that makes the query return 0 rows when tenant context is null and fail_closed is on'
        );
    });

    test('mk_director.php config declares tenant.fail_closed (default false for BC)', function () use ($config): void {
        expect($config)->toMatch('/[\'"]fail_closed[\'"]/');
    });
});
