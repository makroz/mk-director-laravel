<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

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
function makeAuthUserCommand(): MakeAuthUserCommand
{
    $command = new MakeAuthUserCommand;

    // Set output a NullOutput wrapped en OutputStyle (mk-director-implementation.md §
    // "Scaffolder testing: modulesPath() override + sub-classing + tempdir").
    $command->setOutput(new OutputStyle(
        new StringInput(''),
        new NullOutput
    ));

    return $command;
}

// ─── BUG-NEW-01 — LEGACY: array_merge bien armado (R-PKG-029 PKG-NEW-15 lo refactoriza) ──
// Históricamente `buildLoginResponseArray()` armaba un array_merge con profile
// fields + roles + abilities top-level. El refactor R-PKG-029 PKG-NEW-15
// (2026-06-28, feedback RETO fase 10b) simplifica esto: la función ahora
// retorna literal `$user` y el shape canónico lo aplica `autoTransform()`
// en `BaseController::sendResponse()` (vía el `apiResource` del modelo).
//
// Pineamos el nuevo comportamiento + documentamos el histórico.

test('BUG-NEW-01 (LEGACY) + PKG-NEW-15: buildLoginResponseArray retorna $user literal', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);
    $method = $reflection->getMethod('buildLoginResponseArray');

    // Sin profile fields.
    $resultEmpty = $method->invoke($command, [], 'email');
    expect($resultEmpty)->toBe('$user');

    // Con profile fields.
    $resultWith = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], 'email');
    expect($resultWith)->toBe('$user');

    // Y el stub del AuthController usa este retorno directamente:
    //   '{{moduleNameLower}}' => $user,
    $stub = stubContents('auth-user.auth-controller.stub');
    expect($stub)->toContain("'{{moduleNameLower}}' => \$user,");

    // El patrón viejo (array_merge ad-hoc) NO debe existir más:
    expect($stub)->not->toMatch("/\\\$user->only\\(\\['id', 'name'/");
    expect($stub)->not->toMatch("/'abilities'\s*=>\s*\\\$user->abilities->pluck/");
});

// ─── BUG-NEW-02 — LEGACY: loginField resuelto en login response (R-PKG-029 PKG-NEW-15) ──
// El bug original era que `buildLoginResponseArray()` emitía `{{loginField}}`
// literal en vez del loginField resuelto. Después del refactor R-PKG-029
// PKG-NEW-15, la función ya no toca loginField — solo retorna `$user`.

test('BUG-NEW-02 (LEGACY): buildLoginResponseArray ignora loginField (refactor PKG-NEW-15)', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);

    $method = $reflection->getMethod('buildLoginResponseArray');

    // Cualquier loginField (email, ci, phone, etc.) → mismo retorno.
    $emailResult = $method->invoke($command, [], 'email');
    expect($emailResult)->toBe('$user');

    $ciResult = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], 'ci');
    expect($ciResult)->toBe('$user');

    // El loginField sigue resolviéndose correctamente en el stub vía el
    // placeholder `{{loginField}}` que SÍ existe en otras partes del stub
    // (validation rule, lookup del user). Pineamos eso para asegurar BC.
    $stub = stubContents('auth-user.auth-controller.stub');
    expect($stub)->toContain('{{loginFieldValidationRule}}');
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
        'BUG-NEW-07: la migration FK polimórfica debe estar eliminada (R-G-033-C + clean rebuild RETO).'
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
    // R-PKG-016 (BUG-NEW-17): el approach cambió. Antes era `whereExists` con
    // join explícito. Ahora es `whereIn` con subquery UNION ALL (más portable
    // y resuelve BUG-NEW-17 de 0 rows para users sin direct abilities).
    //
    // Pineamos que NO haya whereColumn a abilities (que era el bug Postgres)
    // y que SÍ haya un whereIn con subquery.
    expect($src)->not->toMatch("/whereColumn\\(\\s*'ability_role\\.ability_id'\\s*,\\s*'abilities\\.id'\\s*\\)/")
        ->and($src)->toMatch("/->whereIn\\(\\s*'abilities\\.id'/");
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
    expect($commandSrc)->toContain('mk:fix:sanctum-uuids')
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
// ════════════════════════════════════════════════════════════════════════════
// R-PKG-016 — RETO fase 3 feedback fixes (v1.6.0-rc5 → rc6)
// ════════════════════════════════════════════════════════════════════════════
//
// Pineados tras clean rebuild RETO sobre v1.6.0-rc5. 8 bugs nuevos + 2 drift
// fixes (BUG-NEW-10 + BUG-NEW-14). Cada test es regression puro — si el bug
// vuelve, el test falla. Patrón: source-parsing + reflection-based isolation
// (mk-director-implementation.md § "Audit-driven pre-tag discovery").
//
// Source: .makromania/projects/reto/modules/admin/FEEDBACK-TO-MK-DIRECTOR.md
//         (sección "🆕 Bugs nuevos en v1.6.0-rc5" + "⚠️ Bugs de fase 2 NO
//         resueltos en rc5").
// ════════════════════════════════════════════════════════════════════════════

// ─── BUG-NEW-13 — routes/api.php con DOS bloques PHP → loadRoutesFrom crashea ───

test('BUG-NEW-13: extendRoutesWithCrud extrae use statements y los inyecta al inicio (NO crea 2do bloque PHP)', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    // El método extendRoutesWithCrud debe:
    //   1. Tener un regex para extraer `use ...;` del stub (preg_match_all).
    //   2. Insertar esos use statements al inicio del routes/api.php (después de <?php).
    //   3. Insertar el cuerpo de las rutas (sin use statements, sin <?php) antes del cierre del grupo.

    // Step 1: extracción de use statements via regex.
    expect($src)->toContain("preg_match_all('/^use\\s+");
    expect($src)->toContain("preg_match_all('/^use\\s+[^;]+;\\s*\$/m");

    // Step 2: inserción después del primer <?php (regex match_offset).
    expect($src)->toContain('PREG_OFFSET_CAPTURE');

    // Step 3: el cuerpo insertado NO debe tener `<?php` opener — buscar preg_replace quitándolo.
    expect($src)->toMatch("/preg_replace\\(\\s*'\\/\\^<\\\\\\?php\\\\s\\*\\\\n\\/',\\s*''/s");

    // Step 4: NO debe quedar duplicado si el use statement ya existe.
    expect($src)->toContain('str_contains($content, "use {$fqcn};")');

    // Y el stub sigue conteniendo los 3 use statements (para que el scaffolder los extraiga).
    $stub = stubContents('auth-user/auth-user.routes.with-crud.stub');
    expect($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\AbilityController;')
        ->and($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\RoleController;')
        ->and($stub)->toContain('use App\Modules\{{ModuleName}}\Http\Controllers\{{ModuleName}}Controller;');
});

// ─── BUG-NEW-14 — docblock de profile fields sin newline entre docblocks ───────

test('BUG-NEW-14: buildProfileFieldsReplacements emite docblock con newline simple al final (separación vive en el stub)', function () {
    $command = makeAuthUserCommand();
    $reflection = new ReflectionClass($command);

    $method = $reflection->getMethod('buildProfileFieldsReplacements');
    $result = $method->invoke($command, ['full_name' => ['type' => 'string', 'unique' => false]], []);

    $docblock = $result['{{profileFieldsDocblock}}'];

    // R-PKG-015 BUG-NEW-11 → R-PKG-016 BUG-NEW-14 → R-PKG-017 BUG-NEW-25 timeline:
    //
    //  - R-PKG-015 BUG-NEW-11 (1er ciclo): pineo previo emitía `     */\n` (1 newline).
    //    Resultado: el próximo `/**` quedaba PEGADO al `*/` (sin blank line).
    //
    //  - R-PKG-016 BUG-NEW-14 (2do ciclo): fix movió el `\n\n` al cierre
    //    del docblock generado (`     */\n\n`). Resultado: blank line entre
    //    docblocks PERO drift en indentación cuando hay 5+ profile fields
    //    (el doble newline acumulaba blank lines).
    //
    //  - R-PKG-017 BUG-NEW-25 (3er ciclo): fix robusta. El docblock generado
    //    cierra con `\n` simple (`     */\n`) y el control de blank line entre
    //    docblocks vive en el STUB (`{{profileFieldsDocblock}}\n\n    /**`).
    //    Esto elimina el drift y mantiene el control de espaciado en UN lugar.

    expect($docblock)->toMatch('/     \*\\/\n$/s');

    // NO debe quedar la versión vieja con doble newline que era drift-prone.
    expect($docblock)->not->toMatch('/     \*\\/\n\n$/s');
});

// ─── BUG-NEW-15 — create-super-admin name autogenerado del email ──────────────

test('BUG-NEW-15: AuthCreateSuperAdminCommand autogenera name del email local-part si no se provee', function () {
    $src = pkgFileContents('src/Console/Commands/AuthCreateSuperAdminCommand.php');

    // Debe tener fallback chain:
    //   1. --name flag
    //   2. ask() prompt
    //   3. autogenerar del email local-part (split antes de @)

    // R-PKG-046 F9-B05: el split del email local-part ahora aplica solo
    // cuando loginField='email' (no hardcoded). El patrón sigue ahí pero
    // dentro del branch condicional.
    expect($src)->toContain(
        "explode('@', \$loginFieldValue, 2)",
    );

    // El fallback debe setear un valor NO vacío cuando name es null.
    expect($src)->toContain('ucfirst(strtolower($localPart))');

    // Debe haber un fallback final a 'Admin' si el local-part está vacío (email malformado, ya validado antes).
    expect($src)->toContain("'Admin'");
});

// ─── BUG-NEW-16 — HasRoles/HasAbilities mutations sin user_type en pivot ───────

test('BUG-NEW-16: HasRoles/HasAbilities mutations setean user_type cuando la pivot tiene la columna (MME-polimórfico)', function () {
    $hasRolesSrc = pkgFileContents('src/Auth/Concerns/HasRoles.php');
    $hasAbilitiesSrc = pkgFileContents('src/Auth/Concerns/HasAbilities.php');

    // HasRoles::assignRole debe:
    //   1. Detectar via Schema::hasColumn('role_user', 'user_type').
    //   2. Si existe, agregar extras ['user_type' => static::class] al syncWithoutDetaching.

    expect($hasRolesSrc)->toContain('use Illuminate\\Support\\Facades\\Schema;')
        ->and($hasRolesSrc)->toMatch("/Schema::hasColumn\\(\\s*'role_user'\\s*,\\s*'user_type'\\s*\\)/")
        ->and($hasRolesSrc)->toContain("'user_type' => static::class");

    // HasRoles::syncRoles idem.
    expect($hasRolesSrc)->toMatch('/function\\s+syncRoles/');

    // HasAbilities::giveAbilityTo + syncDirectAbilities idem pero para ability_user.
    expect($hasAbilitiesSrc)->toContain('use Illuminate\\Support\\Facades\\Schema;')
        ->and($hasAbilitiesSrc)->toMatch("/Schema::hasColumn\\(\\s*'ability_user'\\s*,\\s*'user_type'\\s*\\)/")
        ->and($hasAbilitiesSrc)->toContain("'user_type' => static::class");

    // Y debe haber un helper method pivotExtras() / abilityPivotExtras() que encapsule el check.
    expect($hasRolesSrc)->toMatch('/function\\s+pivotExtras\\s*\\(/');
    expect($hasAbilitiesSrc)->toMatch('/function\\s+abilityPivotExtras\\s*\\(/');

    // BC: si la pivot NO tiene user_type, el comportamiento es idéntico al previo (sin extras).
    // Lo verificamos buscando el pattern `[]` como retorno del helper cuando NO tiene la columna.
    expect($hasRolesSrc)->toContain('return $hasUserType ? [\'user_type\' => static::class] : [];');
    expect($hasAbilitiesSrc)->toContain('return $hasUserType ? [\'user_type\' => static::class] : [];');
});

// ─── BUG-NEW-17 — HasAbilities::abilities() SQL roto (WHERE ability_id IS NULL) ────

test('BUG-NEW-17: HasAbilities::abilities() NO hace JOIN directo a ability_user (usa subqueries UNION)', function () {
    $src = pkgFileContents('src/Auth/Concerns/HasAbilities.php');

    // El bug era el JOIN directo a `ability_user` que retornaba 0 rows para users
    // sin direct abilities. El fix correcto: usar `whereIn` con subqueries UNION.

    // 1. El método abilities() debe usar whereIn con subquery UNION ALL.
    expect($src)->toMatch("/->whereIn\\(\\s*'abilities\\.id'\\s*,\\s*function\\s*\\(/s");

    // 2. La subquery debe contener los DOS paths: ability_user directo + ability_role via roles.
    expect($src)->toMatch("/->from\\(\\s*'ability_user'\\s*\\)/");

    // 3. Debe usar unionAll para combinar los paths.
    expect($src)->toContain('->unionAll(');

    // 4. La subquery para ability_role debe joinear role_user y filtrar por user_id.
    expect($src)->toMatch("/->join\\(\\s*'role_user'\\s*,\\s*'role_user\\.role_id'\\s*,\\s*'='\\s*,\\s*'ability_role\\.role_id'\\s*\\)/");

    // 5. NO debe haber un whereExists (que era el patrón anterior y era insuficiente).
    //    Verificamos que NO esté en el método abilities() — el grep es una
    //    aproximación; si el método tiene whereExists en OTRO método, no falla.
    //    Para ser más estrictos, vamos a verificar que la firma de abilities()
    //    NO contenga whereExists.
    $abilitiesMethod = extractMethod($src, 'abilities');
    expect($abilitiesMethod)->not->toContain('whereExists');

    // 6. DB facade debe estar importado (para unionAll con subquery explícita).
    expect($src)->toContain('use Illuminate\\Support\\Facades\\DB;');
});

// ─── BUG-NEW-18 — AbilityController with:['roles'] no existe en Ability model ─────

test('BUG-NEW-18: ability-controller stub tiene with:[] y allowedIncludes:[] (no declara roles relation)', function () {
    $stub = stubContents('auth-user/ability-controller.stub');

    // El bug era `with: ['roles']` que crasheaba porque Ability model NO tiene
    // relation `roles()` (solo Role tiene `abilities()`).
    expect($stub)->not->toMatch("/'with'\\s*=>\\s*\\[\\s*'roles'/");

    // El fix correcto: arrays VACÍOS.
    expect($stub)->toMatch("/'with'\\s*=>\\s*\\[\\s*\\]/");
    expect($stub)->toMatch("/'allowedIncludes'\\s*=>\\s*\\[\\s*\\]/");

    // Y debe documentar el bug en el docblock.
    expect($stub)->toContain('BUG-NEW-18 fix');
});

// ─── BUG-NEW-19 — rutas con { admin } (espacios) → no matchea URL ───────────

test('BUG-NEW-19: routes with-crud stub emite rutas SIN espacios dentro de {param}', function () {
    $stub = stubContents('auth-user/auth-user.routes.with-crud.stub');

    // El bug era `'{ admin }'` (con espacios alrededor del param name) que
    // Laravel interpretaba como ` admin` (con espacio).
    // El fix: `'{admin}'` (sin espacios).

    // Después del str_replace con `admin` (scope=Admin → moduleNameLower=admin),
    // las rutas con params dinámicos del scope deben quedar como `/{admin}` sin espacios.
    $stubResolved = str_replace(
        ['{{ModuleName}}', '{{moduleNameLower}}', '{{moduleNamePluralLower}}'],
        ['Admin', 'admin', 'admins'],
        $stub,
    );

    // Filtrar las líneas que son RUTAS PHP (no comentarios) — el bug estaba en
    // las líneas Route::xxx, no en los comentarios explicativos.
    //
    // R-PKG-NEW FASE18-C: desde v2.0.1-rc0, cada Route::xxx carga
    // `->middleware([...])` chained. Las verb calls (`->get('/{admin}', ...)`)
    // ahora viven en líneas separadas del `Route::middleware(...)` que las
    // precede. Filtramos por ambos patrones: lineas con `Route::` (group +
    // middleware blocks) Y lineas con verb calls (`->get`, `->post`, etc.).
    $routeLines = array_values(array_filter(
        explode("\n", $stubResolved),
        static fn (string $line): bool => (str_contains($line, 'Route::') || preg_match('/->(?:get|post|put|patch|delete)\(/', $line)) && ! str_starts_with(trim($line), '//'),
    ));

    // 6 rutas con param dinámico del scope Admin.
    foreach ($routeLines as $line) {
        // Las rutas con param scope dinámico (admin) NO deben tener espacios dentro de `{}`.
        expect($line)->not->toMatch("/'\\/\\{\\s+admin\\s+\\}/");
    }

    // Las rutas del role/ability usan {role}/{ability} que NO tienen el bug (ya correcto).
    // Verificamos que se mantuvieron sin tocar.
    expect($stubResolved)->toContain("'/{role}'")
        ->and($stubResolved)->toContain("'/{ability}'");

    // Conteo de rutas con param scope: 6 (show, update×2, destroy, assignRoles, assignDirectAbilities).
    // Filtramos las líneas de rutas que tienen `{admin}` o `{admin}/` (sin espacios).
    //
    // R-PKG-NEW FASE18-C: el pattern ahora matchea ambas formas — lineas con
    // `Route::` (group + middleware) Y lineas con verb calls (`->get`, etc.).
    $scopeRouteLines = array_filter($routeLines, static fn (string $line): bool => (bool) preg_match("/'\\/\\{admin\\}(['\\/])/", $line));
    expect(count($scopeRouteLines))->toBe(6);
});

// ─── BUG-NEW-20 — SmartController::show(int $id) rompe con UUIDs ──────────────

test('BUG-NEW-20: CRUDSmart acepta string|int en show/update/destroy (UUIDs-friendly)', function () {
    $src = pkgFileContents('src/Traits/CRUDSmart.php');

    // show(), update(), destroy() deben tener `string|int $id` en la signature,
    // NO `int $id` (que era el bug). El regex usa `\\$` para matchear el `$` literal
    // del nombre de variable en el código fuente.

    // show
    expect($src)->toMatch('/function\s+show\(\s*Request\s+\$request\s*,\s*string\|int\s+\$id\s*\)/');
    // update
    expect($src)->toMatch('/function\s+update\(\s*Request\s+\$request\s*,\s*string\|int\s+\$id\s*\)/');
    // destroy
    expect($src)->toMatch('/function\s+destroy\(\s*Request\s+\$request\s*,\s*string\|int\s+\$id\s*\)/');

    // NO debe quedar `int $id` en esos 3 métodos (defensivo: que NO matchee).
    expect($src)->not->toMatch('/function\s+show\(\s*Request[^,]*,\s*int\s+\$id/');
    expect($src)->not->toMatch('/function\s+update\(\s*Request[^,]*,\s*int\s+\$id/');
    expect($src)->not->toMatch('/function\s+destroy\(\s*Request[^,]*,\s*int\s+\$id/');
});

// ─── BUG-NEW-10 drift — checkSanctumInstalled() con fallback file_exists ──────

test('BUG-NEW-10 drift: checkSanctumInstalled tiene fallback file_exists para drift post composer require', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    // Debe tener un método isSanctumInstalled() separado (testeable).
    expect($src)->toMatch('/function\\s+isSanctumInstalled\\s*\\(/');

    // El helper debe chequear `class_exists` PRIMERO y luego `file_exists` como fallback.
    // R-PKG-027 note: el rule `fully_qualified_strict_types` de Pint ahora
    // normaliza el FQCN a alias via `use`. El código usa `HasApiTokens::class`
    // (con `use Laravel\Sanctum\HasApiTokens;` arriba). Pineamos ambos formatos.
    expect($src)->toMatch('/class_exists\(\s*(\\\\?Laravel\\\\Sanctum\\\\)?HasApiTokens::class\s*\)/');

    // Y el fallback debe apuntar a `vendor/laravel/sanctum/composer.json`.
    expect($src)->toContain('vendor/laravel/sanctum/composer.json');
    expect($src)->toMatch('/file_exists\(/');
});

// ─── FEEDBACK10 — file fields son DYNAMIC, no photo_path hardcoded ──────────

test('FEEDBACK10: migration stub NO pinea photo_path hardcoded (columnas file son del scaffolder)', function () {
    $migration = stubContents('auth-user.migration.stub');

    // FEEDBACK10 (R-PKG-050, Mario 2026-07-10): la columna `photo_path` ya
    // NO se pinea hardcoded en el migration stub. Los file fields (avatar,
    // cover_photo, etc.) se generan dinámicamente via
    // `{{profileFieldsColumns}}` desde los `:file` suffix de --profile-fields.
    //
    // El stub debe usar el placeholder `{{profileFieldsColumns}}` (que se
    // resuelve en runtime con las columnas del profile field).
    expect($migration)->not->toContain("\$table->string('photo_path')");
    expect($migration)->toContain('{{profileFieldsColumns}}');
});

test('FEEDBACK10: model stub NO pinea photo_path hardcoded, usa {{fileFields*}} placeholders', function () {
    $model = stubContents('auth-user.model.stub');

    // El model stub debe tener los placeholders `{{fileFields*}}` que el
    // scaffolder resuelve con los file fields declarados.
    expect($model)->toContain('{{fileFieldsFillableEntries}}');
    expect($model)->toContain('{{fileFieldsAccessors}}');

    // Y NO debe pinear `photo_path` ni `getPhotoUrlAttribute` hardcoded.
    expect($model)->not->toContain("'photo_path',");
    expect($model)->not->toMatch('/function\s+getPhotoUrlAttribute/');
});

test('FEEDBACK10: admin-resource stub NO pinea photo_path/photo_url hardcoded, usa {{fileFieldsResourceEntry}}', function () {
    $resource = stubContents('auth-user/admin-resource.stub');

    expect($resource)->toContain('{{fileFieldsResourceEntry}}');
    expect($resource)->not->toContain("'photo_path' => \$this->photo_path");
    expect($resource)->not->toContain("'photo_url' => \$this->photo_url");
});

test('FEEDBACK10: admin-service stub NO pinea photo/photo_path hardcoded, usa {{fileFieldsUploadPipeline}} + {{fileFieldsDeleteOldPipeline}}', function () {
    $service = stubContents('auth-user/admin-service.stub');

    // Stub pinea signatures hardcoded (mutateData + update) con placeholders
    // para los cuerpos dinámicos. Si NO hay file fields, los placeholders
    // quedan como string vacío (passthrough).
    expect($service)->toContain('{{fileFieldsUploadPipeline}}');
    expect($service)->toContain('{{fileFieldsDeleteOldPipeline}}');
    expect($service)->toContain('protected function mutateData(array $data): array');
    expect($service)->toContain('public function update({{ModuleName}} ${{moduleNameLower}}, array $data): {{ModuleName}}');
    expect($service)->not->toContain("\$data['photo_path'] = \$path");
    expect($service)->not->toContain("isset(\$data['photo'])");
});

test('FEEDBACK10: store/update-admin-request stubs NO pinean photo hardcoded, usan {{fileFieldsValidation*}}', function () {
    $store = stubContents('auth-user/store-admin-request.stub');
    $update = stubContents('auth-user/update-admin-request.stub');

    // El request stub pinea el placeholder que el scaffolder resuelve
    // con las rules de los file fields.
    expect($store)->toContain('{{fileFieldsValidationStore}}');
    expect($update)->toContain('{{fileFieldsValidationUpdate}}');

    // Y NO pinea `'photo' => [...]` hardcoded.
    expect($store)->not->toContain("'photo' => ['nullable', 'file', 'image'");
    expect($update)->not->toContain("'photo' => ['sometimes', 'nullable', 'file', 'image'");
});

test('FEEDBACK10: buildFileFieldsConfig() pine IDENTITY map (no sufijo _path)', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    // Localizar el helper buildFileFieldsConfig() y pinear que el map es
    // identity (request field === column), no `'avatar' => 'avatar_path'`.
    // El helper puede ser `private` o `protected` — matcheamos cualquiera
    // de los dos (visibility no afecta la intención pineada).
    $helperPos = strpos($src, 'function buildFileFieldsConfig(');
    expect($helperPos)->not->toBeFalse();
    $helperBody = substr($src, (int) $helperPos);

    // Pin: el map pinea el field name como value (identity), no sufijo _path.
    expect($helperBody)->toContain("\$map[\$fieldName] = \$fieldName;");
    expect($helperBody)->not->toContain("\$map[\$fieldName] = \$fieldName . '_path'");
});

// ─── FEEDBACK-A7 — RBAC seeder cubre TODOS los recursos ruteados ─────────────

test('FEEDBACK-A7: seeder concede al admin roles + abilities, no solo el modulo', function () {
    $seeder = stubContents('auth-user/admin-roles-seeder.stub');

    // La lista de recursos incluye los tres que el scaffolder rutea.
    expect($seeder)->toContain("\$resources = ['{{moduleNamePluralLower}}', 'roles', 'abilities'];");
    // El admin itera sobre $resources usando el enum canónico CrudAction (A8).
    expect($seeder)->toMatch('/foreach\s*\(\s*\$resources\s+as\s+\$resource\s*\)/');
    expect($seeder)->toContain('CrudAction::all()');
});

// ─── FEEDBACK-A8 — enum canónico CrudAction ─────────────────────────────────

test('FEEDBACK-A8: existe el stub enum-crud-action con all() + ability()', function () {
    $enum = stubContents('auth-user/enum-crud-action.stub');

    expect($enum)->toContain('enum CrudAction: string');
    expect($enum)->toContain("case ViewAny = 'viewAny';");
    expect($enum)->toContain('public static function all(): array');
    expect($enum)->toContain('public function ability(string $scope, string $resource): string');
});

test('FEEDBACK-A8: generateCrudPack genera el enum CrudAction', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain("'auth-user/enum-crud-action.stub'");
    expect($src)->toContain("'Enums'");
});

// ─── FEEDBACK-A1/A3 — Policies default-deny en el pack --with-crud ───────────

test('FEEDBACK-A1/A3: --with-crud genera las 3 Policies + registra Gate::policy', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    // Genera {Scope}Policy (module-rbac) + Role/Ability (variantes auth-user).
    expect($src)->toContain("'module-rbac/policy-user.stub'");
    expect($src)->toContain("'auth-user/policy-role.stub'");
    expect($src)->toContain("'auth-user/policy-ability.stub'");
    // Registro vía Gate::policy en el ServiceProvider.
    expect($src)->toContain('function extendServiceProviderWithPolicies');
    expect($src)->toContain('Gate::policy(');
    // Escape hatch.
    expect($src)->toContain('skip-policies');
});

test('FEEDBACK-A1/A3: las policies role/ability apuntan al modelo CENTRAL del paquete', function () {
    // Regresión: NO deben referenciar App\Modules\{Scope}\Models\Role|Ability
    // (que no existen en el camino --with-crud), sino Mk\Director\Auth\Models\*.
    $role = stubContents('auth-user/policy-role.stub');
    $ability = stubContents('auth-user/policy-ability.stub');

    expect($role)->toContain('use Mk\\Director\\Auth\\Models\\Role;');
    expect($role)->not->toContain('use App\\Modules\\{{ModuleName}}\\Models\\Role;');
    expect($ability)->toContain('use Mk\\Director\\Auth\\Models\\Ability;');
    expect($ability)->not->toContain('use App\\Modules\\{{ModuleName}}\\Models\\Ability;');
});

// ─── FEEDBACK-A9 — flags de orquestación post-scaffold ──────────────────────

test('FEEDBACK-A9: command expone --migrate/--seed/--discover/--setup-sanctum', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    foreach (['--migrate', '--seed', '--discover', '--setup-sanctum', '--skip-auth-wire'] as $opt) {
        expect($src)->toContain($opt);
    }

    // Orquestador que corre los pasos en orden seguro.
    expect($src)->toContain('function runPostScaffoldSteps');
    expect($src)->toMatch("/\\\$this->call\\(\\s*'migrate'/");
    expect($src)->toMatch("/\\\$this->call\\(\\s*'db:seed'/");
    expect($src)->toMatch("/\\\$this->call\\(\\s*'mk:discover-abilities'/");
});

test('FEEDBACK-A9/Sanctum: setupSanctum publica + parchea a UUID', function () {
    $src = pkgFileContents('src/Console/Commands/MakeAuthUserCommand.php');

    expect($src)->toContain('function setupSanctum');
    expect($src)->toContain('sanctum-migrations');
    expect($src)->toContain('mk:fix:sanctum-uuids');
});

/**
 * Helper: extrae el cuerpo de un método del código fuente via reflection-style parsing.
 * Usado por BUG-NEW-17 para aislar el método `abilities()` del resto del trait.
 */
function extractMethod(string $source, string $methodName): string
{
    if (preg_match('/function\s+'.preg_quote($methodName, '/').'\s*\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $matches)) {
        return $matches[1];
    }

    return '';
}
