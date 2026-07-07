<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Scaffolder guard (2026-07-07) — the auth-user CRUD stubs ship a `setExtraData`
 * hook so the admin + roles list endpoints carry the domain metadata their
 * create/edit forms need, WITHOUT the form refetching it every time it opens.
 *
 * Contract:
 *   - Admin controller (`{{ModuleName}}Controller`) → `__extraData.roles`
 *     (roles of the scope, `Role::where('guard', $scope)`).
 *   - Role controller (`RoleController`) → `__extraData.abilities`
 *     (abilities of the scope, `Ability::where('name', 'like', "$scope.%")`).
 *
 * `setExtraData` is gated behind the `__extraData` query param (CRUDSmart), so
 * the front (`useMkList`) requests it once on the first load and caches it.
 *
 * These are source-parsing tests (INTENCIÓN). Runtime EFECTIVIDAD is validated
 * in the RETO pilot consumer on a clean rebuild.
 */
uses(MkLaravelTestCase::class);

$adminStubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user/admin-controller.stub';
$roleStubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user/role-controller.stub';

test('admin-controller stub exposes setExtraData with the scope roles', function () use ($adminStubPath) {
    $stub = (string) file_get_contents($adminStubPath);
    expect($stub)->toBeString();

    // Imports needed by the hook.
    expect($stub)
        ->toContain('use Illuminate\Http\Request;')
        ->toContain('use Mk\Director\Auth\Models\Role;');

    // The hook itself, keyed under `roles`, filtered by the scope guard.
    expect($stub)
        ->toContain('public function setExtraData(Request $request, $data): array')
        ->toContain("'roles' => Role::query()")
        ->toContain("->where('guard', \$scope)");
});

test('role-controller stub exposes setExtraData with the scope abilities', function () use ($roleStubPath) {
    $stub = (string) file_get_contents($roleStubPath);
    expect($stub)->toBeString();

    // Imports needed by the hook.
    expect($stub)
        ->toContain('use Illuminate\Http\Request;')
        ->toContain('use Mk\Director\Auth\Models\Ability;');

    // The hook itself, keyed under `abilities`, filtered by the scope name prefix.
    expect($stub)
        ->toContain('public function setExtraData(Request $request, $data): array')
        ->toContain("'abilities' => Ability::query()")
        ->toContain("->where('name', 'like', \$scope.'.%')");
});
