<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Tenancy;

use Illuminate\Http\Request;
use Mk\Director\Plugins\Enterprise\MkMultiTenantPlugin;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Verifies MkMultiTenantPlugin::beforeDelete uses strict comparison
 * (audit R2-018).
 *
 * Before this change, the comparison was `$model->{$col} != $tenantId`.
 * PHP's loose != coerces strings and ints: the UUID
 * '00000000-0000-0000-0000-000000000000' (string) coerces-equal to
 * integer 0, so an attacker who sent X-Tenant-ID: 0 on a request
 * could DELETE rows owned by the empty-string-tenant.
 *
 * After this change, both sides are cast to string and compared
 * with !==, so a '0' (string) tenant never equals the empty-uuid
 * string.
 *
 * @see audit-2026-06-17-R2-018
 */
uses(MkLaravelTestCase::class);

test('beforeDelete: matching tenant string passes (no exception)', function () {
    $plugin = new MkMultiTenantPlugin(['column' => 'client_id']);

    $model = new \stdClass();
    $model->client_id = 'tenant-42';

    $user = new \stdClass();
    $user->client_id = 'tenant-42';

    $request = Request::create('/api/v1/foo/1', 'DELETE');
    $request->setUserResolver(fn () => $user);

    expect(fn () => $plugin->beforeDelete($model, $request))->not->toThrow(UnauthorizedHttpException::class);
});

test('beforeDelete: empty-string-uuid vs integer 0 does NOT pass (strict comparison)', function () {
    $plugin = new MkMultiTenantPlugin(['column' => 'client_id']);

    // Simulate the bug: model.client_id is the empty-string UUID,
    // the request "user" claims tenant id = 0 (int). Loose != would
    // coerce them equal; strict !== rejects.
    $model = new \stdClass();
    $model->client_id = '00000000-0000-0000-0000-000000000000';

    $user = new \stdClass();
    $user->client_id = 0;

    $request = Request::create('/api/v1/foo/1', 'DELETE');
    $request->setUserResolver(fn () => $user);

    expect(fn () => $plugin->beforeDelete($model, $request))
        ->toThrow(UnauthorizedHttpException::class);
});

test('beforeDelete: different tenants throw UnauthorizedHttpException', function () {
    $plugin = new MkMultiTenantPlugin(['column' => 'client_id']);

    $model = new \stdClass();
    $model->client_id = 'tenant-42';

    $user = new \stdClass();
    $user->client_id = 'tenant-99';

    $request = Request::create('/api/v1/foo/1', 'DELETE');
    $request->setUserResolver(fn () => $user);

    expect(fn () => $plugin->beforeDelete($model, $request))
        ->toThrow(UnauthorizedHttpException::class);
});

test('beforeDelete: source uses !== and string cast (regression guard)', function () {
    $src = (string) file_get_contents(__DIR__ . '/../../../src/Plugins/Enterprise/MkMultiTenantPlugin.php');

    // Must use strict !== (not just !=) for the tenant comparison.
    expect($src)->toContain('!==');
    // Both sides must be cast to string so int/string mixing is handled.
    expect($src)->toContain('(string) $model->');
    expect($src)->toContain('(string) $tenantId');
});