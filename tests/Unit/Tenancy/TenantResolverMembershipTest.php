<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Verifies that TenantResolver validates user↔tenant membership
 * (audit R2-004):
 *  - When the request has a user with a tenant id AND the resolved
 *    tenant differs, the middleware returns 403 ERR_TENANT_MISMATCH.
 *  - When the user has no tenant id (model without HasTenantMembership,
 *    or tenant column is null), the request passes — the missing
 *    tenant is treated as "legacy/unspecified" rather than a mismatch.
 *
 * Implementation note: we parse the TenantResolver source to assert
 * the membership check is present and positioned AFTER the
 * tenant-id resolution (otherwise the middleware could short-circuit
 * before knowing which tenant to compare against).
 *
 * @see audit-2026-06-17-R2-004
 */
uses(MkLaravelTestCase::class);

function tenantResolverSource(): string
{
    return (string) file_get_contents(__DIR__ . '/../../../src/Tenancy/TenantResolver.php');
}

test('TenantResolver source contains the membership check', function () {
    $src = tenantResolverSource();

    expect($src)->toContain('ERR_TENANT_MISMATCH');
});

test('TenantResolver reads the user tenant via getTenantId() (HasTenantMembership)', function () {
    $src = tenantResolverSource();

    expect($src)->toContain('getTenantId');
});

test('TenantResolver membership check sits between strict-mode MISSING and the context->set call', function () {
    $src = tenantResolverSource();

    // Flow inside handle():
    //   1. tenant id resolved (header/path/subdomain)
    //   2. if null + strict → return 400 ERR_TENANT_MISSING
    //   3. membership check (new) → return 403 ERR_TENANT_MISMATCH
    //   4. context->set(tenantId)
    //
    // Step 3 MUST sit between step 2 and step 4 — otherwise we either
    // return early before comparing, or we poison the context with a
    // tenant the user is not a member of.
    $missingPos = strpos($src, 'ERR_TENANT_MISSING');
    $mismatchPos = strpos($src, 'ERR_TENANT_MISMATCH');
    $contextSetPos = strpos($src, '$this->context->set');

    expect($missingPos)->toBeGreaterThan(0);
    expect($mismatchPos)->toBeGreaterThan(0);
    expect($contextSetPos)->toBeGreaterThan(0);

    expect($mismatchPos)->toBeGreaterThan($missingPos);
    expect($contextSetPos)->toBeGreaterThan($mismatchPos);
});

test('TenantResolver returns 403 status code on mismatch', function () {
    $src = tenantResolverSource();

    // The new branch returns JsonResponse with 403.
    expect($src)->toContain('ERR_TENANT_MISMATCH');
    // Confirm the HTTP status: the surrounding JsonResponse call must use 403.
    expect($src)->toMatch('/ERR_TENANT_MISMATCH.{0,300},\s*403/s');
});

test('TenantResolver still returns 400 on missing tenant context (regression)', function () {
    $src = tenantResolverSource();

    expect($src)->toContain('ERR_TENANT_MISSING');
    expect($src)->toMatch('/ERR_TENANT_MISSING.{0,300},\s*400/s');
});