<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-010 — RBAC defaults en `mk:make:auth-user`.
 *
 * **R-PKG-047 D2 (2026-07-09 22:12)**: el flag `--with-auth-rbac` se ELIMINÓ.
 * RBAC es ahora default ON (igual que `--with-crud` y `--with-status`). Para
 * opt-out, usar `--no-rbac`. La simplificación pinea R-G-033 "maximo default +
 * minimo custom" (Mario feedback).
 *
 * **R-PKG-047 D1**: el AuthController scaffoldeado pasó de ~500 LOC a un
 * thin wrapper de 148 LOC que solo override 4 abstracts:
 * `authModelClass()`, `authScope()`, `loginField()`, `passwordResetTable()`.
 * La lógica RBAC vive en `BaseAuthController` (SSoT canónico).
 *
 * Contrato pineado acá:
 *   - El command NO acepta `--with-auth-rbac` (eliminado en D2).
 *   - El command acepta `--no-rbac` (D2 opt-out).
 *   - El command tiene `buildRbacReplacements()` que popula los placeholders
 *     legacy (mantenidos por compat con stubs viejos que aún pinean
 *     `{{rbac*}}` — todos resuelven a string vacío post-D1).
 *   - El stub `auth-user.auth-controller.stub` es thin wrapper que
 *     `extends BaseAuthController` (NO `BaseController`).
 *   - `mk_director.auth.abilities` y `mk_director.auth.rate_limits` están en
 *     `config/mk_director.php`.
 *
 * Spec: R-PKG-010 ACR-001..004 + R-PKG-047 D1+D2.
 * @see \Mk\Director\Console\Commands\MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRoot010(): string
{
    return dirname(__DIR__, 3);
}

function commandSource010(): string
{
    $path = packageRoot010().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource010(string $name): string
{
    $path = packageRoot010()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

function configSource010(): string
{
    $path = packageRoot010().'/config/mk_director.php';
    expect(file_exists($path))->toBeTrue("Config must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command signature ───────────────────────────────────────────────────

test('R-PKG-047 D2: --with-auth-rbac flag está ELIMINADO (default ON, --no-rbac opt-out)', function () {
    $source = commandSource010();

    // R-PKG-047 D2 (2026-07-09 22:12): --with-auth-rbac se eliminó. RBAC es
    // default ON. Para opt-out, --no-rbac. Esto pinea el nuevo contrato.
    expect($source)->not->toMatch('/--with-auth-rbac\s*:/');

    // Y el opt-out SÍ existe.
    expect($source)->toMatch('/--no-rbac\s*:/');
});

test('command has buildRbacReplacements() method (legacy BC for stub placeholders)', function () {
    $source = commandSource010();

    // El método se mantiene por BC con stubs viejos que aún pinean los
    // placeholders {{rbac*}}. Post-D1 todos resuelven a string vacío (la
    // lógica RBAC vive en BaseAuthController ahora).
    expect($source)->toContain('protected function buildRbacReplacements');
});

test('command merges rbacReplacements with loginFieldReplacements via $extraReplacements', function () {
    $source = commandSource010();

    // El handle() hace array_merge que incluye ambos sets (R-PKG-010).
    // R-PKG-011 extendió a 4 sets pero loginField + rbac siguen presentes
    // en el mismo array_merge (orden puede variar).
    expect($source)->toMatch('/array_merge\([^)]*\$loginFieldReplacements[^)]*\$rbacReplacements/s');
});

// ── Default mode (BC) ──────────────────────────────────────────────────

test('default mode (sin --with-auth-rbac) preserva BC con v1.5.0-rc3', function () {
    $source = commandSource010();

    // El command, sin flag, debe pasar placeholders RBAC como string vacío
    // via array_fill_keys con lista completa de placeholders.
    expect($source)->toMatch("/\\\$withAuthRbac\\s*\\?\\s*\\\$this->buildRbacReplacements/");
    expect($source)->toContain("array_fill_keys([");
    expect($source)->toContain("'{{rbacImports}}'");
    expect($source)->toContain("'{{rbacConstructor}}'");
    expect($source)->toContain("'{{rbacAbilityCheckMe}}'");
    expect($source)->toContain("'{{rbacAbilityCheckLogout}}'");
    expect($source)->toContain("'{{rbacAuditLoginSuccess}}'");
    expect($source)->toContain("'{{rbacAuditLoginFailed}}'");
    expect($source)->toContain("'{{rbacAuditLogout}}'");
    expect($source)->toContain("'{{rbacAuditForgot}}'");
    expect($source)->toContain("'{{rbacAuthorizeAbilityMethod}}'");
    expect($source)->toContain("'{{rbacLoginThrottle}}'");
    expect($source)->toContain("'{{rbacForgotThrottle}}'");
    expect($source)->toContain("'{{rbacResetThrottle}}'");
});

// ── RBAC mode ──────────────────────────────────────────────────────────

test('buildRbacReplacements populates all conditional placeholders with content', function () {
    $source = commandSource010();

    // Imports adicionales (PHP source usa "\\" para escapar — single-quoted en el test
    // necesita "\\\\" para matchear dos backslash chars reales en disco).
    expect($source)->toContain('Mk\\\\Director\\\\Auth\\\\Events\\\\AuthEvent');
    expect($source)->toContain('Mk\\\\Director\\\\Auth\\\\Services\\\\AbilityResolver');
    expect($source)->toContain('Illuminate\\\\Auth\\\\Access\\\\AuthorizationException');

    // Constructor con AbilityResolver
    expect($source)->toContain('public function __construct(?AbilityResolver $abilityResolver = null)');

    // Ability checks en /me y /logout (PHP source usa \$ dentro de double-quoted,
    // por eso single-quoted en el test preserva el backslash literal).
    expect($source)->toContain('\$this->authorizeAbility(\'me\', \$request->user())');
    expect($source)->toContain('\$this->authorizeAbility(\'logout\', \$user)');

    // Audit events
    expect($source)->toContain("AuthEvent::dispatch('auth.login.success'");
    expect($source)->toContain("AuthEvent::dispatch('auth.login.failed'");
    expect($source)->toContain("AuthEvent::dispatch('auth.logout'");
    expect($source)->toContain("AuthEvent::dispatch('auth.password_reset.requested'");

    // authorizeAbility() helper method
    expect($source)->toContain('protected function authorizeAbility(string $endpoint, mixed $user): void');
    expect($source)->toContain('config("mk_director.auth.abilities.{$endpoint}")');

    // Rate limit middleware
    expect($source)->toContain("config('mk_director.auth.rate_limits.login', '5,1')");
    expect($source)->toContain("config('mk_director.auth.rate_limits.forgot', '3,1')");
    expect($source)->toContain("config('mk_director.auth.rate_limits.reset', '3,1')");
});

test('buildRbacReplacements uses AbilityResolver with HasAbilities fallback', function () {
    $source = commandSource010();

    // El helper authorizeAbility usa AbilityResolver si está disponible,
    // fallback a canMk() del HasAbilities trait.
    expect($source)->toContain('$this->abilityResolver->can($user, $ability)');
    expect($source)->toContain('$user->canMk($ability)');
});

test('buildRbacReplacements never logs passwords in audit events', function () {
    $source = commandSource010();

    // Anti-pattern: NUNCA loggear passwords (ni hasheados) en audit events.
    // Verificación estática: el código generado NO contiene 'password' como
    // key del payload de AuthEvent.
    expect($source)->not->toMatch("/AuthEvent::dispatch\\([^)]*'password'/s");
    expect($source)->not->toMatch("/AuthEvent::dispatch\\([^)]*\\\$credentials\\['password'\\]/s");
});

// ── Stub structure (auth-user.auth-controller.stub) ───────────────────
// R-PKG-047 D1: el stub es thin wrapper que extends BaseAuthController (SSoT).
// Los placeholders RBAC legacy se removieron del stub porque la lógica RBAC
// (ability checks, rate limit, audit events, authorizeAbility helper) vive
// en BaseAuthController. El test pinea el nuevo contrato: thin wrapper.

test('R-PKG-047 D1: auth-user.auth-controller.stub es thin wrapper (extends BaseAuthController)', function () {
    $stub = stubSource010('auth-user.auth-controller.stub');

    // Thin wrapper extends BaseAuthController (SSoT canónico).
    expect($stub)->toContain('use Mk\\Director\\Auth\\Controllers\\BaseAuthController;');
    expect($stub)->toMatch('/class\s+AuthController\s+extends\s+BaseAuthController/');

    // Override los 4 abstracts requeridos (D1).
    expect($stub)->toContain('protected function authModelClass(): string');
    expect($stub)->toContain('protected function authScope(): string');
    expect($stub)->toContain('protected function loginField(): string');
    expect($stub)->toContain('protected function passwordResetTable(): string');

    // El class statement NO extiende BaseController genérico del package ni
    // Laravel stock (esos eran del stub pre-D1 de ~500 LOC). El docblock puede
    // mencionarlos en notas históricas, pero la class declaration es BaseAuthController.
    expect($stub)->not->toMatch('/class\s+AuthController\s+extends\s+BaseController\b/');
    expect($stub)->not->toMatch('/class\s+AuthController\s+extends\s+Controller\b/');

    // NO contiene los placeholders RBAC legacy (la lógica vive en BaseAuthController).
    expect($stub)->not->toContain('{{rbacImports}}');
    expect($stub)->not->toContain('{{rbacConstructor}}');
    expect($stub)->not->toContain('{{rbacAbilityCheckMe}}');
    expect($stub)->not->toContain('{{rbacAuditLoginSuccess}}');
});

// ── Stub structure (auth-user.routes.stub) ────────────────────────────

test('auth-user.routes.stub has inline throttle placeholders (preserves BC)', function () {
    $stub = stubSource010('auth-user.routes.stub');

    // Los placeholders están inline (después del `)`), no en líneas separadas.
    // Esto preserva la línea original `Route::post('login', [...]);` cuando
    // el placeholder es string vacío.
    expect($stub)->toContain("Route::post('login', [AuthController::class, 'login']){{rbacLoginThrottle}};");
    expect($stub)->toContain("Route::post('forgot', [AuthController::class, 'forgot']){{rbacForgotThrottle}};");
    expect($stub)->toContain("Route::post('reset', [AuthController::class, 'reset']){{rbacResetThrottle}};");
});

// ── Config block ──────────────────────────────────────────────────────

test('config/mk_director.php has auth.abilities block with default null (BC)', function () {
    $config = configSource010();

    expect($config)->toContain("'abilities' => [");
    expect($config)->toContain("'me' => env('MK_AUTH_ABILITY_ME')");
    expect($config)->toContain("'logout' => env('MK_AUTH_ABILITY_LOGOUT')");
});

test('config/mk_director.php has auth.rate_limits block with safe defaults', function () {
    $config = configSource010();

    expect($config)->toContain("'rate_limits' => [");
    expect($config)->toContain("'login' => env('MK_AUTH_RATE_LIMIT_LOGIN', '5,1')");
    expect($config)->toContain("'forgot' => env('MK_AUTH_RATE_LIMIT_FORGOT', '3,1')");
    expect($config)->toContain("'reset' => env('MK_AUTH_RATE_LIMIT_RESET', '3,1')");
});

// ── AuthEvent class ───────────────────────────────────────────────────

test('Mk\\Director\\Auth\\Events\\AuthEvent class exists with Dispatchable trait', function () {
    $path = packageRoot010().'/src/Auth/Events/AuthEvent.php';
    expect(file_exists($path))->toBeTrue("AuthEvent must exist at $path");

    $source = (string) file_get_contents($path);
    expect($source)->toContain('namespace Mk\\Director\\Auth\\Events');
    expect($source)->toContain('use Illuminate\\Foundation\\Events\\Dispatchable');
    expect($source)->toContain('use Dispatchable;');
    expect($source)->toContain('public readonly string $type');
    expect($source)->toContain('public readonly array $payload = []');
});

// ── Documentation hooks (R-G-032 sync) ───────────────────────────────

test('CHANGELOG.md mentions --with-auth-rbac flag (R-G-032 sync)', function () {
    $path = packageRoot010().'/CHANGELOG.md';
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);
    // Match the flag (case-insensitive) somewhere in CHANGELOG.
    expect(stripos($source, '--with-auth-rbac') !== false)->toBeTrue();
});

test('DEVELOPER_GUIDE.md mentions --with-auth-rbac flag (R-G-032 sync)', function () {
    $path = packageRoot010().'/DEVELOPER_GUIDE.md';
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);
    expect(stripos($source, '--with-auth-rbac') !== false)->toBeTrue();
});

test('README.md mentions --with-auth-rbac flag (R-G-032 sync)', function () {
    $path = packageRoot010().'/README.md';
    expect(file_exists($path))->toBeTrue();

    $source = (string) file_get_contents($path);
    expect(stripos($source, '--with-auth-rbac') !== false)->toBeTrue();
});