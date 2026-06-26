<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-014 MEJORA-02 / BUG-08 — `mk:make:auth-user --with-crud`.
 *
 * Pinea que el flag --with-crud genera los 17 archivos esperados:
 *   - 3 Controllers (Admin, Role, Ability)
 *   - 4 Requests (StoreAdmin, UpdateAdmin, AssignRoles, AssignDirectAbilities)
 *   - 3 Resources (Admin, Role, Ability)
 *   - 2 DTOs (AdminData, AdminFilterData)
 *   - 1 Repository
 *   - 1 Repository Interface
 *   - 1 Service
 *   - 1 Factory
 *   - 1 Seeder
 *   - 1 routes.with-crud.stub
 */
uses(MkLaravelTestCase::class);

function packageRootCrud(): string
{
    return dirname(__DIR__, 3);
}

function crudStubExists(string $name): bool
{
    return file_exists(packageRootCrud().'/src/Stubs/auth-user/'.$name);
}

// ── Flag --with-crud en la signature ─────────────────────────────────────

test('command signature incluye --with-crud option', function () {
    $path = packageRootCrud().'/src/Console/Commands/MakeAuthUserCommand.php';

    expect((string) file_get_contents($path))->toContain('--with-crud :');
});

// ── 17 stubs existen ─────────────────────────────────────────────────────

test('crud pack: 17 stubs existen', function () {
    $required = [
        'admin-controller.stub',
        'role-controller.stub',
        'ability-controller.stub',
        'store-admin-request.stub',
        'update-admin-request.stub',
        'assign-roles-request.stub',
        'assign-abilities-request.stub',
        'admin-resource.stub',
        'role-resource.stub',
        'ability-resource.stub',
        'admin-data-dto.stub',
        'admin-filter-dto.stub',
        'admin-repository.stub',
        'admin-repository-interface.stub',
        'admin-service.stub',
        'admin-factory.stub',
        'admin-roles-seeder.stub',
        'auth-user.routes.with-crud.stub',
    ];

    foreach ($required as $stub) {
        expect(crudStubExists($stub))->toBeTrue("Stub {$stub} debe existir");
    }
});

// ── Controllers extienden SmartController ────────────────────────────────

test('AdminController stub extends SmartController y tiene mkConfig', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-controller.stub');

    expect($stub)->toContain('class {{ModuleName}}Controller extends SmartController');
    expect($stub)->toContain("'model'           => {{ModuleName}}::class");
    expect($stub)->toContain("'service'         => {{ModuleName}}Service::class");
});

test('RoleController stub extends SmartController y opera sobre Role del paquete', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/role-controller.stub');

    expect($stub)->toContain('class RoleController extends SmartController');
    expect($stub)->toContain('use Mk\Director\Auth\Models\Role;');
    expect($stub)->toContain("'model'           => Role::class");
    expect($stub)->toContain('public function syncAbilities');
});

test('AbilityController stub extends SmartController y opera sobre Ability del paquete', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/ability-controller.stub');

    expect($stub)->toContain('class AbilityController extends SmartController');
    expect($stub)->toContain('use Mk\Director\Auth\Models\Ability;');
});

// ── Service implementa MkModuleServiceInterface-style API ────────────────

test('AdminService stub tiene create/update/syncRoles/syncDirectAbilities', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-service.stub');

    expect($stub)->toContain('class {{ModuleName}}Service');
    expect($stub)->toContain('public function create(array $data): {{ModuleName}}');
    expect($stub)->toContain('public function update({{ModuleName}} ${{moduleNameLower}}, array $data): {{ModuleName}}');
    expect($stub)->toContain('public function syncRoles({{ModuleName}} ${{moduleNameLower}}, array $roleNames): {{ModuleName}}');
    expect($stub)->toContain('public function syncDirectAbilities({{ModuleName}} ${{moduleNameLower}}, array $abilityNames): {{ModuleName}}');
});

// ── Repository implementa interface ──────────────────────────────────────

test('AdminRepository stub implementa interface y tiene CRUD methods', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-repository.stub');

    expect($stub)->toContain('class {{ModuleName}}Repository implements {{ModuleName}}RepositoryInterface');
    expect($stub)->toContain('public function paginate(array $filters): LengthAwarePaginator');
    expect($stub)->toContain('public function findById(string $id, array $with = []): ?{{ModuleName}}');
    expect($stub)->toContain('public function create(array $data): {{ModuleName}}');
    expect($stub)->toContain('public function update({{ModuleName}} ${{moduleNameLower}}, array $data): {{ModuleName}}');
    expect($stub)->toContain('public function delete({{ModuleName}} ${{moduleNameLower}}): bool');
    expect($stub)->toContain('public function syncRoles({{ModuleName}} ${{moduleNameLower}}, array $roleNames): {{ModuleName}}');
    expect($stub)->toContain('public function syncDirectAbilities({{ModuleName}} ${{moduleNameLower}}, array $abilityNames): {{ModuleName}}');
});

// ── Seeder siembra 4 roles predefinidos ──────────────────────────────────

test('AdminRolesSeeder stub siembra super-admin, admin, editor, viewer', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-roles-seeder.stub');

    expect($stub)->toContain("'super-admin'");
    expect($stub)->toContain("'admin'");
    expect($stub)->toContain("'editor'");
    expect($stub)->toContain("'viewer'");
});

// ── FormRequest validación ───────────────────────────────────────────────

test('StoreAdminRequest stub valida email + password + profile fields unique', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/store-admin-request.stub');

    expect($stub)->toContain("'email' => ['required', 'email', 'max:255', 'unique:{{moduleNamePluralLower}},email']");
    expect($stub)->toContain("'password' => ['required', 'string', 'min:8', 'max:255']");
    expect($stub)->toContain('{{profileFieldsUniqueRules}}');
});

// ── Routes con CRUD extendido ────────────────────────────────────────────

test('routes stub extendido tiene CRUD + roles + abilities endpoints', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/auth-user.routes.with-crud.stub');

    expect($stub)->toContain("Route::prefix('api/{{moduleNamePluralLower}}')");
    expect($stub)->toContain("Route::prefix('api/roles')");
    expect($stub)->toContain("Route::prefix('api/abilities')");
    expect($stub)->toContain('assignRoles');
    expect($stub)->toContain('assignDirectAbilities');
});

// ── Command orquesta CRUD pack ───────────────────────────────────────────

test('command handle() orquesta generateCrudPack() cuando --with-crud', function () {
    $path = packageRootCrud().'/src/Console/Commands/MakeAuthUserCommand.php';
    $source = (string) file_get_contents($path);

    expect($source)->toContain('protected function generateCrudPack');
    expect($source)->toMatch('/if \(\$withCrud\)\s*\{\s*\$this->generateCrudPack/');
});