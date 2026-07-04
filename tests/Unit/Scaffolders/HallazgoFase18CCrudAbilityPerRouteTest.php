<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO-NEW-FASE18-C — Regression guard for missing `mk.ability` per-route
 * middleware in the CRUD routes scaffolder.
 *
 * Symptom (RETO feedback 2026-07-04, sprint `makromania/2026-07-04-1855--s8-fase6-admin-clean-rebuild`):
 *   - Editor (role with abilities `[admin.admins.viewAny, view, update]`,
 *     WITHOUT `create` or `delete`) was able to execute
 *     `POST /api/admins/{id}/roles` and assign `super-admin` to themselves.
 *   - Root cause: pre-fix, CRUD routes (`/api/admins/*`, `/api/roles/*`,
 *     `/api/abilities/*`) only applied `mk.auth:{scope}` middleware at the
 *     group level. The RBAC was wired into `AuthController` (via
 *     `--with-auth-rbac`) but NOT per-route on CRUD endpoints, so any
 *     authenticated user could execute any CRUD action regardless of
 *     ability set. HIGH escalation risk.
 *
 * Fix (R-PKG-NEW proposed, 2026-07-04): change
 * `src/Stubs/auth-user/auth-user.routes.with-crud.stub` so each `Route::xxx`
 * carries `->middleware(['mk.auth:{scope}', 'mk.ability:{scope}.{resource}.{action}'])`
 * PER-ROUTE (no group-level ability).
 *
 * Action mapping (canónico Laravel conventions):
 *   - index       → viewAny
 *   - show        → view
 *   - store       → create
 *   - update      → update   (cubre assignRoles + assignDirectAbilities + syncAbilities)
 *   - destroy     → delete
 *
 * BC BREAK documented in CHANGELOG (R-G-033 authorizes). Migration:
 * consumers sin abilities pre-pineadas deben correr
 * `php artisan mk:discover-abilities --force` antes de subir el paquete.
 *
 * This test pins INTENCIÓN (HALLAZGO-NEW-03). Runtime EFECTIVIDAD is
 * validated in RETO next session (clean rebuild against the patched stub).
 *
 * @see /Users/marioguzman/Desktop/Makromania/.makromania/projects/mk-director/operations/s8-f6-admin-clean-rebuild-result.md § Hallazgo adicional
 */
uses(MkLaravelTestCase::class);

$crudStubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user/auth-user.routes.with-crud.stub';

test('HALLAZGO-NEW-FASE18-C — CRUD stub removed group-level ability middleware (defense-in-depth)', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);
    expect($stub)->toBeString();

    // Pre-fix pattern (group-level): `->middleware('mk.auth:{scope}')` directly
    // on the Route::prefix group. Post-fix: groups have NO middleware; each
    // Route::xxx carries its own middleware list.
    expect($stub)
        ->not->toContain("->middleware('mk.auth:{{moduleNameLower}}')->group(function () {");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: viewAny + view + create actions on the {moduleNamePluralLower} group', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // The three "index/show/store" actions MUST be guarded by the
    // corresponding `viewAny`, `view`, `create` abilities.
    expect($stub)
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.viewAny'")
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.view'")
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.create'");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: update + delete actions on the {moduleNamePluralLower} group', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // PUT + PATCH + assignRoles + assignDirectAbilities → `update`.
    // DELETE → `delete`.
    expect($stub)
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.update'")
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.delete'");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: assignRoles + assignDirectAbilities require update ability (escalation guard)', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // The two PRIVILEGE-ESCALATING routes (assignRoles, assignDirectAbilities)
    // MUST be guarded by `update` ability. Pre-fix they only had
    // `mk.auth:{scope}`, allowing any authenticated user to mutate roles.
    // We verify by locating each route's middleware line + URL path.
    expect($stub)
        ->toContain("'mk.ability:{{moduleNameLower}}.{{moduleNamePluralLower}}.update'")
        ->toContain("'/{{{moduleNameLower}}}/roles'")
        ->toContain("'/{{{moduleNameLower}}}/abilities'");

    // Combined check: the lines for `->post('/{{{moduleNameLower}}}/roles'` and
    // `->post('/{{{moduleNameLower}}}/abilities'` MUST be preceded (within 200
    // chars) by the `update` ability middleware. Use simpler regex anchored
    // at the action token to avoid multi-backslash escape headaches.
    preg_match_all('/update[\'"]?\s*\][^;]*?->post\(\s*\'\/\{\{\{(\w+)\}\}\}\/roles\'/s', $stub, $rolesMatches);
    preg_match_all('/update[\'"]?\s*\][^;]*?->post\(\s*\'\/\{\{\{(\w+)\}\}\}\/abilities\'/s', $stub, $abilitiesMatches);

    expect(count($rolesMatches[0]))->toBeGreaterThanOrEqual(1, 'assignRoles route must use update ability middleware');
    expect(count($abilitiesMatches[0]))->toBeGreaterThanOrEqual(1, 'assignDirectAbilities route must use update ability middleware');
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: roles routes use scope.roles.{action} ability', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // Roles routes are global (prefix `api/roles`) but gated by the scope
    // (only the scope's admins can manage roles for that scope).
    // Action mapping: viewAny/view/create/update/delete + syncAbilities(update).
    expect($stub)
        ->toContain("'mk.ability:{{moduleNameLower}}.roles.viewAny'")
        ->toContain("'mk.ability:{{moduleNameLower}}.roles.view'")
        ->toContain("'mk.ability:{{moduleNameLower}}.roles.create'")
        ->toContain("'mk.ability:{{moduleNameLower}}.roles.update'")
        ->toContain("'mk.ability:{{moduleNameLower}}.roles.delete'");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: abilities routes use scope.abilities.{action} ability', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // Abilities routes are global (prefix `api/abilities`) but gated by
    // the scope. Action mapping: viewAny/view/create/update/delete.
    expect($stub)
        ->toContain("'mk.ability:{{moduleNameLower}}.abilities.viewAny'")
        ->toContain("'mk.ability:{{moduleNameLower}}.abilities.view'")
        ->toContain("'mk.ability:{{moduleNameLower}}.abilities.create'")
        ->toContain("'mk.ability:{{moduleNameLower}}.abilities.update'")
        ->toContain("'mk.ability:{{moduleNameLower}}.abilities.delete'");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: every Route::xxx carries mk.auth + mk.ability middleware', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // Count `Route::middleware(` invocations vs `Route::xxx(` (verb-only)
    // calls. Each non-group `Route::xxx` call (get/post/put/patch/delete)
    // MUST be preceded by a `Route::middleware([...])` line.
    //
    // We count:
    //   - middleware blocks: `Route::middleware([`
    //   - route calls (verbs only): `->get(`, `->post(`, `->put(`, `->patch(`, `->delete(`
    // The 3 group-level `Route::prefix(...)->group(function () {` lines
    // are excluded from middleware count.
    preg_match_all('/Route::middleware\(\[/', $stub, $m1);
    preg_match_all('/->(?:get|post|put|patch|delete)\(/', $stub, $m2);

    $middlewareCount = count($m1[0]);
    $verbCount = count($m2[0]);

    // 8 admin routes + 7 roles routes + 6 abilities routes = 21 routes
    // total. Each MUST have a middleware block.
    expect($middlewareCount)->toBeGreaterThanOrEqual(21);
    expect($verbCount)->toBe($middlewareCount, "Number of verb routes ({$verbCount}) MUST equal number of middleware blocks ({$middlewareCount}).");
});

test('HALLAZGO-NEW-FASE18-C — CRUD stub: BC break is documented inline (R-G-033 compliance)', function () use ($crudStubPath) {
    $stub = (string) file_get_contents($crudStubPath);

    // The stub MUST document the BC break so that future readers know why
    // the per-route middleware structure changed. R-G-033 requires
    // explicit BC break disclosure.
    expect($stub)
        ->toContain('HALLAZGO-NEW-FASE18-C')
        ->toContain('BC BREAK')
        ->toContain('mk:discover-abilities --force');
});
