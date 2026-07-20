<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Illuminate\Console\OutputStyle;
use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;
use ReflectionClass;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * F10-B08 — `--kind=manager|consumer` en `mk:make:auth-user`.
 *
 * Codifica lo que el piloto RETO (run10) tuvo que armar a mano: un scope
 * "member" que solo loguea + edita su propio perfil, administrado por un
 * scope "admin" via --managed-by (FEEDBACK10, hallazgos RBAC jerárquico).
 *
 * **Patrón de testing** (ver `AuthUserFeedbackAuditTest.php` +
 * `MkModuleWithRbacTest.php` § "End-to-end test"): el paquete NO depende de
 * `illuminate/foundation`, así que `app_path()`/`config_path()` no existen
 * en el proceso de test y `handle()` completo no se puede correr (wirea
 * config/auth.php + cors.php + Sanctum con paths reales). Por eso:
 *
 *   - Las validaciones de `handle()` (orden, mensajes de error) se testean
 *     via source-parsing (igual que TODO el resto del scaffolder en este
 *     paquete — no hay precedente de Artisan real end-to-end acá).
 *   - La GENERACIÓN DE ARCHIVOS sí se ejecuta de verdad: `generateCrudPack()`
 *     y `generateManagedResource()` son protected methods invocados via
 *     Reflection contra un tempdir real, usando el override `modulesPath()`
 *     (F10-B08, agregado a `MakeAuthUserCommand` con el mismo patrón que
 *     `MakeModuleCommand::modulesPath()` — ver `MkModuleWithRbacTest.php`).
 *     Se asertan CONTENIDOS REALES de archivos generados, no solo intención.
 */
uses(MkLaravelTestCase::class);

function kindConsumerCmdSource(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php');
}

// ── Signature ────────────────────────────────────────────────────────────

test('command signature incluye --kind=manager option (default BC)', function () {
    expect(kindConsumerCmdSource())->toContain('{--kind=manager');
});

// ── Validaciones en handle() ───────────────────────────────────────────────

test('handle() rechaza --kind fuera de manager|consumer', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toContain("--kind debe ser 'manager' o 'consumer'");
    expect($src)->toMatch('/if \(! in_array\(\$kind, \[\'manager\', \'consumer\'\], true\)\)/');
});

test('handle() rechaza --kind=consumer sin --managed-by, ANTES de crear ningún directorio', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toContain('--kind=consumer requiere --managed-by=<Scope>');

    // Guard de orden: la validación de consumer-requiere-managed-by debe
    // aparecer ANTES de `$basePath = $this->modulesPath($scope)` (primer
    // punto donde el scaffolder toca filesystem), para que el fail-fast sea
    // real (no queden carpetas a medio generar).
    $validationPos = strpos($src, '--kind=consumer requiere --managed-by=<Scope>');
    $firstFsTouchPos = strpos($src, '$basePath = $this->modulesPath($scope);');

    expect($validationPos)->not->toBeFalse();
    expect($firstFsTouchPos)->not->toBeFalse();
    expect($validationPos)->toBeLessThan($firstFsTouchPos);
});

test('handle() selecciona auth-user.routes.consumer.stub para --kind=consumer, auth-user.routes.stub para manager (BC)', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toContain("\$isConsumer ? 'auth-user.routes.consumer.stub' : 'auth-user.routes.stub'");
});

// ── generateCrudPack(): consumer omite RoleController/AbilityController + Policies ──

test('generateCrudPack() omite RoleController/AbilityController cuando $isConsumer', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toMatch(
        '/if \(! \$isConsumer\) \{\s*'
        .'\$this->generateStub\(\$scope, \$scopeLower, \$scopePlural, \$loginField, \'auth-user\/role-controller\.stub\'/'
    );
});

test('generateCrudPack() SIEMPRE genera {Scope}Controller (lo usan las rutas managed)', function () {
    $src = kindConsumerCmdSource();

    // El admin-controller.stub se genera fuera del `if (! $isConsumer)`.
    $adminControllerPos = strpos($src, "generateStub(\$scope, \$scopeLower, \$scopePlural, \$loginField, 'auth-user/admin-controller.stub'");
    $roleControllerGuardPos = strpos($src, 'if (! $isConsumer) {');

    expect($adminControllerPos)->not->toBeFalse();
    expect($roleControllerGuardPos)->not->toBeFalse();
    expect($adminControllerPos)->toBeLessThan($roleControllerGuardPos);
});

test('generateCrudPack() omite RolePolicy/AbilityPolicy (no {Scope}Policy) cuando $isConsumer', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toMatch(
        '/generateStub\(\$scope, \$scopeLower, \$scopePlural, \$loginField, \'auth-user\/policy-user\.stub\'.*?'
        .'if \(! \$isConsumer\) \{\s*'
        .'\$this->generateStub\(\$scope, \$scopeLower, \$scopePlural, \$loginField, \'auth-user\/policy-role\.stub\'/s'
    );
});

test('generateCrudPack() NO extiende routes/api.php propio con CRUD cuando $isConsumer', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toMatch('/if \(! \$isConsumer\) \{\s*\$this->extendRoutesWithCrud\(/');
});

test('extendServiceProviderWithPolicies() omite Gate::policy de Role/Ability cuando $isConsumer', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toContain('protected function extendServiceProviderWithPolicies(string $basePath, string $scope, bool $isConsumer = false): void');

    // El registro de Role/Ability vive dentro del `if (! $isConsumer)`. No se
    // matchea el cuerpo literal: adentro ahora está además el guard de colisión
    // (`centralPolicyOwner()`), y un regex sobre el layout exacto se rompe con
    // cualquier refactor sin que haya regresión real.
    $desde = (int) mb_strpos($src, 'protected function extendServiceProviderWithPolicies');
    $bloque = mb_substr($src, $desde);

    expect($bloque)->toMatch('/if \(! \$isConsumer\) \{.*Auth\\\\\\\\Models\\\\\\\\Role::class/s');
});

// ── Stub de rutas consumer ─────────────────────────────────────────────────

function consumerRoutesStub(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/src/Stubs/auth-user.routes.consumer.stub');
}

test('auth-user.routes.consumer.stub tiene auth + self-profile, SIN CRUD/roles/abilities propias', function () {
    $stub = consumerRoutesStub();

    // Auth routes presentes.
    expect($stub)->toContain("Route::post('login', [AuthController::class, 'login'])");
    expect($stub)->toContain("Route::post('refresh', [AuthController::class, 'refresh'])");
    expect($stub)->toContain("Route::post('logout', [AuthController::class, 'logout'])");
    expect($stub)->toContain("Route::get('me', [AuthController::class, 'me'])");
    expect($stub)->toContain('{{updateProfileRoute}}'); // PATCH /me (B06, condicional a profile-fields).
    expect($stub)->toContain("Route::post('password/forgot', [AuthController::class, 'forgotPassword'])");
    expect($stub)->toContain("Route::post('password/reset', [AuthController::class, 'resetPassword'])");

    // NADA de CRUD propio, roles o abilities del scope (eso lo administra el manager).
    expect($stub)->not->toContain('{{ModuleName}}Controller');
    expect($stub)->not->toContain("Route::prefix('api/{{moduleNamePluralLower}}')");
    expect($stub)->not->toContain("Route::prefix('api/{{moduleNameLower}}/roles')");
    expect($stub)->not->toContain("Route::prefix('api/{{moduleNameLower}}/abilities')");
    expect($stub)->not->toContain('RoleController');
    expect($stub)->not->toContain('AbilityController');
});

// ── modulesPath() override (testability, F10-B08) ──────────────────────────

test('MakeAuthUserCommand has overridable modulesPath() for testability', function () {
    $src = kindConsumerCmdSource();

    expect($src)->toMatch('/protected function modulesPath\(string \$moduleName = \'\'\): string/');
    expect($src)->toMatch('/function modulesPath[\s\S]*?app_path\(/');
});

// ── End-to-end: generación real de archivos en tempdir ─────────────────────

/**
 * Helper: instancia MakeAuthUserCommand subclaseada con modulesPath()
 * redirigido a un tempdir (mismo patrón de MkModuleWithRbacTest.php).
 */
function makeConsumerTestCommand(string $tempDir): MakeAuthUserCommand
{
    $command = new class extends MakeAuthUserCommand
    {
        public string $testBasePath = '';

        protected function modulesPath(string $moduleName = ''): string
        {
            return $this->testBasePath.($moduleName !== '' ? "/{$moduleName}" : '');
        }

        // base_path() requiere una Application Laravel completa (bootea
        // via illuminate/foundation, que este paquete NO instala) — no está
        // disponible en el Container liviano de MkLaravelTestCase. No-op,
        // igual que MkModuleWithRbacTest.php hace con registerServiceProvider().
        protected function registerProviderInBootstrap(string $providerFqcn, ?string $afterProvider = null): void
        {
            // no-op en tests: no debe tocar bootstrap/providers.php real.
        }
    };
    $command->testBasePath = $tempDir;
    $command->setOutput(new OutputStyle(new StringInput(''), new NullOutput));

    return $command;
}

function invokeProtected(object $command, string $method, array $args): mixed
{
    $ref = new ReflectionClass($command);
    $m = $ref->getMethod($method);

    return $m->invoke($command, ...$args);
}

test('generateCrudPack($isConsumer=true) genera {Scope}Controller pero NO RoleController/AbilityController ni sus Policies', function () {
    $tempDir = sys_get_temp_dir().'/mk-kind-consumer-test-'.uniqid();
    mkdir($tempDir, 0755, true);
    mkdir("{$tempDir}/Member/Providers", 0755, true);

    $command = makeConsumerTestCommand($tempDir);

    // Seed del ServiceProvider (generateCrudPack lo extiende con binding + policies).
    invokeProtected($command, 'generateStub', [
        'Member', 'member', 'members', 'email',
        'auth-user.service-provider.stub', 'Providers', 'MemberServiceProvider.php', [],
    ]);

    // Seed del api.php propio con el stub CONSUMER (lo que handle() haría
    // para --kind=consumer, ANTES de llamar generateCrudPack()).
    invokeProtected($command, 'generateStub', [
        'Member', 'member', 'members', 'email',
        'auth-user.routes.consumer.stub', 'Http/Routes', 'api.php',
        [
            '{{rbacLoginThrottle}}' => '', '{{rbacForgotThrottle}}' => '', '{{rbacResetThrottle}}' => '',
            '{{registerRoute}}' => '', '{{emailVerifyRoutes}}' => '', '{{verifiedMiddleware}}' => '',
            '{{updateProfileRoute}}' => "\n        Route::patch('me', [AuthController::class, 'updateProfile']);",
        ],
    ]);

    invokeProtected($command, 'generateCrudPack', [
        'Member', 'member', 'members', 'email', [], [], true, false, [], true,
    ]);

    $base = "{$tempDir}/Member";

    // SIEMPRE se genera (lo usa managed.php).
    expect(file_exists("{$base}/Http/Controllers/MemberController.php"))->toBeTrue();
    expect(file_exists("{$base}/Policies/MemberPolicy.php"))->toBeTrue();

    // NO se generan (orphaned sin rutas propias, administrados por el manager).
    expect(file_exists("{$base}/Http/Controllers/RoleController.php"))->toBeFalse();
    expect(file_exists("{$base}/Http/Controllers/AbilityController.php"))->toBeFalse();
    expect(file_exists("{$base}/Policies/RolePolicy.php"))->toBeFalse();
    expect(file_exists("{$base}/Policies/AbilityPolicy.php"))->toBeFalse();

    // El api.php propio queda EXACTAMENTE como lo dejó el stub consumer — sin
    // CRUD/roles/abilities inyectados por extendRoutesWithCrud().
    $routes = (string) file_get_contents("{$base}/Http/Routes/api.php");
    expect($routes)->toContain("Route::get('me', [AuthController::class, 'me'])");
    expect($routes)->toContain("Route::patch('me', [AuthController::class, 'updateProfile'])");
    expect($routes)->not->toContain('RoleController');
    expect($routes)->not->toContain('AbilityController');
    expect($routes)->not->toContain("Route::prefix('api/members')");
    expect($routes)->not->toContain("Route::prefix('api/member/roles')");
    expect($routes)->not->toContain("Route::prefix('api/member/abilities')");

    // El ServiceProvider solo registra Gate::policy de MemberPolicy (no Role/Ability).
    $provider = (string) file_get_contents("{$base}/Providers/MemberServiceProvider.php");
    expect($provider)->toContain('MemberPolicy::class');
    expect($provider)->not->toContain('RolePolicy::class');
    expect($provider)->not->toContain('AbilityPolicy::class');

    // Cleanup.
    exec('rm -rf '.escapeshellarg($tempDir));
});

test('generateCrudPack($isConsumer=false, default manager) SIGUE generando RoleController/AbilityController + CRUD routes (BC)', function () {
    $tempDir = sys_get_temp_dir().'/mk-kind-manager-test-'.uniqid();
    mkdir($tempDir, 0755, true);
    mkdir("{$tempDir}/Admin/Providers", 0755, true);

    $command = makeConsumerTestCommand($tempDir);

    invokeProtected($command, 'generateStub', [
        'Admin', 'admin', 'admins', 'email',
        'auth-user.service-provider.stub', 'Providers', 'AdminServiceProvider.php', [],
    ]);
    invokeProtected($command, 'generateStub', [
        'Admin', 'admin', 'admins', 'email',
        'auth-user.routes.stub', 'Http/Routes', 'api.php',
        [
            '{{rbacLoginThrottle}}' => '', '{{rbacForgotThrottle}}' => '', '{{rbacResetThrottle}}' => '',
            '{{registerRoute}}' => '', '{{emailVerifyRoutes}}' => '', '{{verifiedMiddleware}}' => '',
            '{{updateProfileRoute}}' => '',
        ],
    ]);

    // $isConsumer default false — llamada SIN el 10mo argumento (manager path, BC).
    invokeProtected($command, 'generateCrudPack', [
        'Admin', 'admin', 'admins', 'email', [], [], true, false, [],
    ]);

    $base = "{$tempDir}/Admin";

    expect(file_exists("{$base}/Http/Controllers/AdminController.php"))->toBeTrue();
    expect(file_exists("{$base}/Http/Controllers/RoleController.php"))->toBeTrue();
    expect(file_exists("{$base}/Http/Controllers/AbilityController.php"))->toBeTrue();
    expect(file_exists("{$base}/Policies/RolePolicy.php"))->toBeTrue();
    expect(file_exists("{$base}/Policies/AbilityPolicy.php"))->toBeTrue();

    $routes = (string) file_get_contents("{$base}/Http/Routes/api.php");
    expect($routes)->toContain("Route::prefix('api/admins')");
    expect($routes)->toContain("Route::prefix('api/admin/roles')");
    expect($routes)->toContain("Route::prefix('api/admin/abilities')");

    $provider = (string) file_get_contents("{$base}/Providers/AdminServiceProvider.php");
    expect($provider)->toContain('AdminPolicy::class');
    expect($provider)->toContain('RolePolicy::class');
    expect($provider)->toContain('AbilityPolicy::class');

    exec('rm -rf '.escapeshellarg($tempDir));
});

test('generateManagedResource() genera managed.php + seeder para un consumer administrado por Admin', function () {
    $tempDir = sys_get_temp_dir().'/mk-kind-managed-test-'.uniqid();
    mkdir($tempDir, 0755, true);

    $command = makeConsumerTestCommand($tempDir);
    $base = "{$tempDir}/Member";
    mkdir("{$base}/Providers", 0755, true);

    // Seed del ServiceProvider (generateManagedResource lo extiende con el SP separado).
    invokeProtected($command, 'generateStub', [
        'Member', 'member', 'members', 'email',
        'auth-user.service-provider.stub', 'Providers', 'MemberServiceProvider.php', [],
    ]);

    invokeProtected($command, 'generateManagedResource', [$base, 'Member', 'member', 'members', 'Admin']);

    expect(file_exists("{$base}/Http/Routes/managed.php"))->toBeTrue();
    expect(file_exists("{$base}/Database/Seeders/MemberManagedByAdminSeeder.php"))->toBeTrue();

    $managed = (string) file_get_contents("{$base}/Http/Routes/managed.php");
    expect($managed)->toContain("Route::prefix('api/admin/members')");
    expect($managed)->toContain('mk.auth:admin');
    expect($managed)->toContain('mk.ability:admin.members.viewAny');
    expect($managed)->toContain('MemberController::class');

    exec('rm -rf '.escapeshellarg($tempDir));
});
