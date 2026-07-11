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
//
// R-PKG-047 D2 (2026-07-09 22:12): el flag `--with-crud` se ELIMINÓ.
// CRUD es ahora default ON. Para opt-out, usar `--no-crud`. Esto pinea
// R-G-033 "maximo default + minimo custom" (Mario feedback).

test('R-PKG-047 D2: --with-crud flag está ELIMINADO (default ON, --no-crud opt-out)', function () {
    $path = packageRootCrud().'/src/Console/Commands/MakeAuthUserCommand.php';

    expect((string) file_get_contents($path))->not->toContain('--with-crud :');
    expect((string) file_get_contents($path))->toContain('--no-crud :');
});

// ── 17 stubs existen ─────────────────────────────────────────────────────

test('crud pack: stubs existen (incluye enum + policies A1/A3/A8)', function () {
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
        // A8 enum + A1/A3 policies (role/ability apuntan al modelo central).
        'enum-crud-action.stub',
        'policy-role.stub',
        'policy-ability.stub',
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
    // R-PKG-046 F9-B10: el constructor del Controller ya NO pinea
    // `$this->middleware('mk.auth:{{scope}}')` — defense-in-depth pineado
    // per-route en routes/api.php + routes/managed.php. El stub debe tener
    // constructor vacío (no middleware en constructor).
    expect($stub)->not->toContain('$this->middleware(\'mk.auth:{{moduleNameLower}}\');');
    expect($stub)->toContain('public function __construct()');
});

test('RoleController stub extends SmartController y opera sobre Role del paquete', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/role-controller.stub');

    expect($stub)->toContain('class RoleController extends SmartController');
    expect($stub)->toContain('use Mk\Director\Auth\Models\Role;');
    expect($stub)->toContain("'model'           => Role::class");
    expect($stub)->toContain('public function syncAbilities');
    expect($stub)->toContain('$this->middleware(\'mk.auth:{{moduleNameLower}}\');');
    expect($stub)->not->toContain("'searchable'      => ['name', 'description'],");
    expect($stub)->toContain("'searchable'      => ['name'],");
});

test('AbilityController stub extends SmartController y opera sobre Ability del paquete', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/ability-controller.stub');

    expect($stub)->toContain('class AbilityController extends SmartController');
    expect($stub)->toContain('use Mk\Director\Auth\Models\Ability;');
    expect($stub)->toContain('$this->middleware(\'mk.auth:{{moduleNameLower}}\');');
});

// ── Service implementa MkModuleServiceInterface-style API ────────────────

test('AdminService stub (R-PKG-052 T9 v2) implementa MkModuleServiceInterface con hooks puros (NO create/update/mutateData propios — CRUDSmart los invoca automáticamente)', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-service.stub');

    expect($stub)->toContain('class {{ModuleName}}Service');
    expect($stub)->toContain('implements MkModuleServiceInterface');
    // Hooks puros pineados (CRUDSmart los invoca via method_exists checks).
    expect($stub)->toContain('public function beforeCreate(Request $request, array $input): array');
    expect($stub)->toContain('public function afterCreate(Request $request, Model $model, array $input): mixed');
    expect($stub)->toContain('public function beforeUpdate(Request $request, string|int $id, array $input): array');
    expect($stub)->toContain('public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed');
    // Mantiene syncRoleAbilities (específico de Admin, no trivialmente delegable).
    expect($stub)->toContain('public function syncRoleAbilities(Role $role, array $abilityNames): Role');
    // NO pine create()/update()/mutateData() propios — CRUDSmart los invoca.
    expect($stub)->not->toContain('public function create(array $data): {{ModuleName}}');
    expect($stub)->not->toContain('public function update({{ModuleName}} ${{moduleNameLower}}, array $data): {{ModuleName}}');
    expect($stub)->not->toContain('protected function mutateData(array $data): array');
    // NO pinea file fields pipeline — FileStoragePlugin via PluginManager.
    expect($stub)->not->toContain('{{fileFieldsUploadPipeline}}');
    expect($stub)->not->toContain('{{fileFieldsDeleteOldPipeline}}');
    // NO pinea syncRoles/syncDirectAbilities (delegados al Repository).
    expect($stub)->not->toContain('public function syncRoles(');
    expect($stub)->not->toContain('public function syncDirectAbilities(');
});

test('AdminController stub (R-PKG-052 T9) llama al Repository directo para syncRoles/syncDirectAbilities', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/admin-controller.stub');

    // R-PKG-052 T9: el controller ya NO pasa por el Service para sync.
    // Delega directo al Repository (que sigue siendo el SSoT de esos métodos).
    expect($stub)->toContain('app({{ModuleName}}Repository::class)->syncRoles(');
    expect($stub)->toContain('app({{ModuleName}}Repository::class)->syncDirectAbilities(');
    expect($stub)->not->toContain('app({{ModuleName}}Service::class)->syncRoles(');
    expect($stub)->not->toContain('app({{ModuleName}}Service::class)->syncDirectAbilities(');
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

test('StoreAdminRequest stub usa {{loginField}} placeholder (no hardcoded email)', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/store-admin-request.stub');

    // R-PKG-047 D5: el rule del login field es dinámico via placeholder
    // `{{loginFieldValidationRuleStore}}` (no hardcoded 'email'). Esto pinea
    // que --login-field=ci regenera correctamente el StoreRequest.
    expect($stub)->toContain("'{{loginField}}' => {{loginFieldValidationRuleStore}}");

    // Password rule sigue pineado hardcoded.
    expect($stub)->toContain("'password' => ['required', 'string', 'min:8', 'max:255']");

    // Profile fields unique rules (R-PKG-014 BUG-09 prefijo `!`).
    expect($stub)->toContain('{{profileFieldsUniqueRules}}');

    // File fields validation (R-PKG-047 D3 + R-PKG-050).
    expect($stub)->toContain('{{fileFieldsValidationStore}}');

    // Status enum rule (R-PKG-047 D4 — default ON post-D2).
    expect($stub)->toContain('{{statusRequestRuleStore}}');
});

// ── Routes con CRUD extendido ────────────────────────────────────────────

test('routes stub extendido tiene CRUD + roles + abilities endpoints', function () {
    $stub = (string) file_get_contents(packageRootCrud().'/src/Stubs/auth-user/auth-user.routes.with-crud.stub');

    expect($stub)->toContain("Route::prefix('api/{{moduleNamePluralLower}}')");
    // F6-04 (FEEDBACK6): roles/abilities endpoints se namespacean POR SCOPE
    // para no colisionar entre scopes multi --with-crud.
    expect($stub)->toContain("Route::prefix('api/{{moduleNameLower}}/roles')");
    expect($stub)->toContain("Route::prefix('api/{{moduleNameLower}}/abilities')");
    expect($stub)->toContain('assignRoles');
    expect($stub)->toContain('assignDirectAbilities');
});

// ── Command orquesta CRUD pack ───────────────────────────────────────────

test('command handle() orquesta generateCrudPack() cuando --with-crud', function () {
    $path = packageRootCrud().'/src/Console/Commands/MakeAuthUserCommand.php';
    $source = (string) file_get_contents($path);

    expect($source)->toContain('protected function generateCrudPack');
    // handle() entra al bloque --with-crud y llama a generateCrudPack (con
    // $withPolicies computado en el medio — A1/A3).
    expect($source)->toMatch('/if \(\$withCrud\)\s*\{[\s\S]{0,200}?\$this->generateCrudPack/');
});