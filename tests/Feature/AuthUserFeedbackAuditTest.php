<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;

/**
 * Audit-driven regression tests for RETO feedback fase 2 (R-PKG-015).
 *
 * Pinea los 11 bugs + 2 obs reportados en
 * `.makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md`
 * (clean rebuild RETO sobre v1.6.0-rc4). Cada test es regression: si el bug
 * vuelve, el test falla.
 *
 * **Patrón** (mk-director-implementation.md § "Audit-driven pre-tag discovery"):
 * source-parsing + reflection-based isolation. No se ejecuta el command end-to-end
 * (eso requiere `apps/sandbox-laravel`, no incluido en el paquete). En su lugar
 * se invocan métodos protected del command via Reflection con un stub `$scope`
 * controlado.
 *
 * **Cuándo correr**: `vendor/bin/pest tests/Feature/AuthUserFeedbackAuditTest.php`
 * antes de tag de cualquier RC que toque el scaffolder `mk:make:auth-user` o el
 * trait `HasAbilities`.
 *
 * Spec: MK-LAR-1.6.0-rc5 (R-PKG-015).
 */
uses(MkLaravelTestCase::class);

/**
 * Helper: lee un stub del paquete (src/Stubs/...).
 */
function stubContents(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/src/Stubs/'.$relativePath;

    if (! file_exists($path)) {
        test()->fail("Stub no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

/**
 * Helper: lee un archivo PHP del paquete (excluyendo vendor).
 */
function pkgFileContents(string $relativePath): string
{
    $path = dirname(__DIR__, 2).'/'.$relativePath;

    if (! file_exists($path)) {
        test()->fail("Archivo no encontrado: {$path}");
    }

    return (string) file_get_contents($path);
}

/**
 * Helper: instancia MakeAuthUserCommand sin ejecutar handle(), configurando
 * opciones via Reflection para poder testear métodos protected.
 */
function makeAuthUserCommand(): \Mk\Director\Console\Commands\MakeAuthUserCommand
{
    $command = new \Mk\Director\Console\Commands\MakeAuthUserCommand();

    // Set output a NullOutput wrapped en OutputStyle (mk-director-implementation.md §
    // "Scaffolder testing: modulesPath() override + sub-classing + tempdir").
    $command->setOutput(new \Illuminate\Console\OutputStyle(
        new \Symfony\Component\Console\Input\StringInput(''),
        new \Symfony\Component\Console\Output\NullOutput()
    ));

    return $command;
}

// ─── BUG-NEW-01 — array_merge bien armado (no key '0' suelta) ──────────────

test('BUG-NEW-01: buildLoginResponseArray arma array_merge correctamente sin key 0 suelta', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildLoginResponseArray');

    // Sin profile fields: usa `+` para concatenar el sub-array (sin coma suelta).
    $resultEmpty = $method->invoke($command, [], 'email');
    expect($resultEmpty)->toStartWith("\$user->only(")
        ->and($resultEmpty)->toContain(") + [");

    // Con profile fields: el sub-array de roles/abilities DEBE estar DENTRO del
    // array_merge() como último argumento, NO afuera separado por coma suelta.
    // El bug original era: `array_merge($user->only(...), $user->only(...)), [...]`
    // (coma suelta entre `)` y `[`). El fix correcto es: `array_merge(..., ..., [...])`
    // (el `[` está DENTRO de los paréntesis del array_merge).
    $resultWith = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], 'email');
    expect($resultWith)->toStartWith('array_merge(')
        ->and($resultWith)->toContain("'full_name'")
        // El bug original tenía `array_merge(...)), [` — un `)` seguido de `, [`.
        // El fix correcto tiene `array_merge(..., ...), [\n` — sin `)` antes del `, [`.
        ->and($resultWith)->not->toContain(')), [') // No `))` seguido de `, [` (bug original)
        ->and($resultWith)->toMatch("/\[[^\]]+\]\), \[/"); // cierra profileFieldsArray con `]), [` DENTRO del array_merge
});

// ─── BUG-NEW-02 — {{loginField}} resuelto en login response ────────────────

test('BUG-NEW-02: buildLoginResponseArray emite loginField resuelto, no placeholder', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);

    $method = $reflection->getMethod('buildLoginResponseArray');

    // Email field.
    $emailResult = $method->invoke($command, [], 'email');
    expect($emailResult)->toContain("'email'")
        ->and($emailResult)->not->toContain("'{{loginField}}'");

    // Login field no-email (RETO Bolivia usa `ci`).
    $ciResult = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], 'ci');
    expect($ciResult)->toContain("'ci'")
        ->and($ciResult)->not->toContain("'{{loginField}}'");
});

// ─── BUG-NEW-03 — seeder no setea 'module' en abilities ────────────────────

test('BUG-NEW-03: AdminRolesSeeder stub no setea columna module en abilities', function () {
    $stub = stubContents('auth-user/admin-roles-seeder.stub');

    // El paquete `abilities` migration solo tiene id, name, description, timestamps.
    expect($stub)->not->toContain("'module' =>");
});

// ─── BUG-NEW-04 — seeder no setea 'description' en roles ───────────────────

test('BUG-NEW-04: AdminRolesSeeder stub no setea columna description en roles', function () {
    $stub = stubContents('auth-user/admin-roles-seeder.stub');

    // El paquete `roles` migration solo tiene id, name, guard, timestamps.
    expect($stub)->not->toMatch('/\[\'guard\'\s*=>\s*[^]]+\'description\'\s*=>/');
});

// ─── BUG-NEW-05 — routes with-crud stub importa los 3 controllers ─────────

test('BUG-NEW-05: routes.with-crud stub importa AdminController + RoleController + AbilityController', function () {
    $stub = stubContents('auth-user/auth-user.routes.with-crud.stub');

    expect($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\{{ModuleName}}Controller;')
        ->and($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\RoleController;')
        ->and($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\AbilityController;');
});

// ─── BUG-NEW-06 — command genera overrides de roles()/directAbilities() ────

test('BUG-NEW-06: command genera overrides de roles() y directAbilities() cuando --with-crud', function () {
    // Source-parsing del command: verificar que cuando --with-crud está activo
    // se generan los overrides con FKs explícitas.
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain("'{{rolesRelationOverride}}'")
        ->and($src)->toContain("'{{directAbilitiesRelationOverride}}'")
        // El override debe usar 'user_id' explícitamente (NO 'admin_id' inferido).
        ->and($src)->toMatch("/->belongsToMany\(\s*\\\\?Mk\\\\?Director\\\\?Auth\\\\?Models\\\\?Role\\\\?::class,\s*'role_user',\s*'user_id',\s*'role_id'/s")
        ->and($src)->toMatch("/->belongsToMany\(\s*\\\\?Mk\\\\?Director\\\\?Auth\\\\?Models\\\\?Ability\\\\?::class,\s*'ability_user',\s*'user_id',\s*'ability_id'/s")
        // Y debe usar wherePivot con user_type polimórfico (MME R-MK-001).
        ->and($src)->toContain("wherePivot('user_type', static::class)");

    // Y el model stub debe tener los placeholders correspondientes.
    $modelStub = stubContents('auth-user.model.stub');
    expect($modelStub)->toContain('{{rolesRelationOverride}}')
        ->and($modelStub)->toContain('{{directAbilitiesRelationOverride}}');
});

// ─── BUG-NEW-07 — migration FK polimórfica eliminada ───────────────────────

test('BUG-NEW-07: migration add_fk_role_user_to_auth_users está eliminada', function () {
    $migrationPath = dirname(__DIR__, 2)
        .'/src/Auth/Database/Migrations/2026_06_18_000001_add_fk_role_user_to_auth_users.php';

    expect(file_exists($migrationPath))->toBeFalse(
        "BUG-NEW-07: la migration FK polimórfica debe estar eliminada (R-G-033-C + clean rebuild RETO)."
    );

    // Y su test asociado también.
    $testPath = dirname(__DIR__, 2).'/tests/Unit/Auth/RoleUserFkMigrationTest.php';
    expect(file_exists($testPath))->toBeFalse();
});

// ─── BUG-NEW-08 — HasAbilities SQL Postgres-compatible (join explícito) ────

test('BUG-NEW-08: HasAbilities::abilities() usa join() explícito a abilities (Postgres-compatible)', function () {
    $src = pkgFileContents('src/Auth/Concerns/HasAbilities.php');

    // El bug era `whereColumn('ability_role.ability_id', 'abilities.id')` sin
    // join explícito. MySQL/MariaDB lo tolera, Postgres no.
    //
    // Chequeamos: NO debe haber un whereColumn que referencie `abilities.id` sin join.
    // Verificamos con regex negativo (no debe matchear un whereColumn que use
    // 'abilities.id' como segundo argumento).
    expect($src)->not->toMatch("/whereColumn\\(\\s*'ability_role\\.ability_id'\\s*,\\s*'abilities\\.id'\\s*\\)/")
        // El nuevo código usa ->join('abilities', ...) explícito dentro del whereExists.
        ->and($src)->toMatch("/->join\\(\\s*'abilities'\\s*,\\s*'abilities\\.id'/");
});

// ─── BUG-NEW-09 — mk:fix:sanctum-uuids command existe + registrado ────────

test('BUG-NEW-09: FixSanctumUuidsCommand existe y está registrado en MkServiceProvider', function () {
    $commandPath = dirname(__DIR__, 2)
        .'/src/Console/Commands/FixSanctumUuidsCommand.php';

    expect(file_exists($commandPath))->toBeTrue(
        'BUG-NEW-09: FixSanctumUuidsCommand debe existir.'
    );

    $providerSrc = pkgFileContents('src/MkServiceProvider.php');

    expect($providerSrc)->toContain('use Mk\Director\Console\Commands\FixSanctumUuidsCommand;')
        ->and($providerSrc)->toContain('FixSanctumUuidsCommand::class');

    // El command debe tener signature con --dry-run.
    $commandSrc = (string) file_get_contents($commandPath);
    expect($commandSrc)->toContain("mk:fix:sanctum-uuids")
        ->and($commandSrc)->toContain('--dry-run')
        ->and($commandSrc)->toContain("uuidMorphs('tokenable')")
        ->and($commandSrc)->toContain("morphs('tokenable')");
});

// ─── BUG-NEW-10 — MakeAuthUserCommand documenta Sanctum en output ─────────

test('BUG-NEW-10: MakeAuthUserCommand invoca checkSanctumInstalled() y tiene el método definido', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain('$this->checkSanctumInstalled()')
        ->and($src)->toMatch('/function\s+checkSanctumInstalled\s*\(/')
        ->and($src)->toContain('laravel/sanctum:^4.3')
        ->and($src)->toContain('php artisan install:api')
        // Sugerir también el fix de UUID.
        ->and($src)->toContain('mk:fix:sanctum-uuids');
});

// ─── BUG-NEW-11 — docblock de profile fields con header + indentación ──────

test('BUG-NEW-11: buildProfileFieldsReplacements emite docblock con header e indentación correcta', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);

    $method = $reflection->getMethod('buildProfileFieldsReplacements');
    $result = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], []);

    expect($result)->toHaveKey('{{profileFieldsDocblock}}');

    $docblock = $result['{{profileFieldsDocblock}}'];

    // Debe tener el header "Profile fields per-scope".
    expect($docblock)->toContain('Profile fields per-scope');

    // Las líneas de @property deben estar indentadas con 5 espacios (alineadas con `     *`).
    expect($docblock)->toMatch('/^     \* @property/sm');

    // El docblock debe estar bien cerrado (con \n antes del */).
    expect($docblock)->toMatch('/\n     \*\/\n$/s');

    // No debe haber un docblock suelto (otro /**) antes del */.
    expect(substr_count($docblock, '/**'))->toBe(1);
});

// ─── OBS-NEW-01 — discoverAbilitiesFromMkConfig existe + merge ─────────────

test('OBS-NEW-01: DiscoverAbilitiesCommand incluye discoverAbilitiesFromMkConfig()', function () {
    $src = pkgFileContents('src/Console/Commands/DiscoverAbilitiesCommand.php');

    expect($src)->toMatch('/function\s+discoverAbilitiesFromMkConfig\s*\(/')
        // Lee $mkConfig del SmartController.
        ->and($src)->toContain('SmartController::class')
        ->and($src)->toContain("'mkConfig'")
        // Genera abilities del estilo {scope}.{model}.{verb}.
        ->and($src)->toContain("'viewAny'")
        ->and($src)->toContain("'view'")
        ->and($src)->toContain("'create'")
        ->and($src)->toContain("'update'")
        ->and($src)->toContain("'delete'");
});

// ─── OBS-NEW-02 — authorizeAbility() indentación correcta en logout ────────

test('OBS-NEW-02: placeholders rbacAbilityCheck* tienen indentación correcta (8 espacios)', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    // El bug era 4 espacios extra. Después del fix, deben ser 8 espacios exactos
    // (no 4 que sumaba al stub que ya tenía 4 = 8 total → 12 = 4 de más).
    //
    // El código tiene escapes `\$this` en strings PHP (no se interpreta como variable).
    // Para testear, buscamos el código fuente directamente con substrings literales.
    $me = "'{{rbacAbilityCheckMe}}' => \"        ";
    $logout = "'{{rbacAbilityCheckLogout}}' => \"        ";

    expect($src)->toContain($me)
        ->and($src)->toContain($logout);
});