<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Console\Commands\MakeAuthUserCommand;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for the `mk:make:auth-user` command added in
 * sprint 2026-06-24.
 *
 * The package does not boot a full Laravel app in unit tests (see
 * MkLaravelTestCase docblock), so we assert the contract of the command
 * by reading the source files directly. This is the same strategy used
 * by MkServiceProviderCacheListenerTest for the cache-listener hardening.
 *
 * Contract pinned here:
 *   - Command file exists with the expected signature.
 *   - Five stubs exist under src/Stubs/ with the auth-user.* prefix.
 *   - The model stub extends AuthUser and pins auth_scope in the constructor.
 *   - The migration stub declares an indexed `auth_scope` column.
 *   - The auth-controller stub ships the 6 endpoints
 *     (login / refresh / logout / me / forgot / reset) and tells the
 *     consumer it is a skeleton (TODO markers, not a finished implementation).
 *   - The routes stub uses the `mk.auth:{scope}` middleware from the package.
 *   - The service-provider stub loads routes + migrations for the scope.
 *   - The command explicitly does NOT modify config/auth.php — it prints
 *     snippets so the consumer can review. This is the "least surprise"
 *     commitment made in the command's docblock.
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

// ── Paths ────────────────────────────────────────────────────────────────

function packageRoot(): string
{
    return dirname(__DIR__, 3);
}

function commandSource(): string
{
    $path = packageRoot().'/src/Console/Commands/MakeAuthUserCommand.php';
    expect(file_exists($path))->toBeTrue("MakeAuthUserCommand must exist at $path");

    return (string) file_get_contents($path);
}

function stubSource(string $name): string
{
    $path = packageRoot()."/src/Stubs/{$name}";
    expect(file_exists($path))->toBeTrue("Stub $name must exist at $path");

    return (string) file_get_contents($path);
}

// ── Command file ────────────────────────────────────────────────────────

test('mk:make:auth-user command exists with expected signature', function () {
    $source = commandSource();
    expect($source)->toContain('class MakeAuthUserCommand extends Command');
    expect($source)->toContain("'mk:make:auth-user {scope");
    expect($source)->toContain('Str::studly($this->argument(\'scope\'))');
});

test('mk:make:auth-user command generates exactly five stubs', function () {
    $source = commandSource();

    $stubs = [
        'auth-user.model.stub',
        'auth-user.migration.stub',
        'auth-user.auth-controller.stub',
        'auth-user.routes.stub',
        'auth-user.service-provider.stub',
    ];
    foreach ($stubs as $stub) {
        expect($source)->toContain("'{$stub}'");
    }
});

test('FEEDBACK-A4: mk:make:auth-user auto-wires config/auth.php (idempotent + backup), with --skip-auth-wire escape', function () {
    // FEEDBACK A4 revierte la decisión previa de "solo imprimir snippets":
    // el dev igual tenía que pegar el guard+provider a mano en cada scope.
    // Ahora el command cablea config/auth.php automáticamente (idempotente,
    // con backup .bak) y deja `--skip-auth-wire` para el comportamiento viejo.
    $source = commandSource();

    // El helper de wiring existe y edita config/auth.php.
    expect($source)->toContain('function wireAuthConfig');
    expect($source)->toContain("config_path('auth.php')");
    // Backup antes de escribir.
    expect($source)->toContain('.bak');
    // Idempotencia: si el guard/provider ya existen, no re-escribe.
    expect($source)->toContain('sin cambios');
    // Escape hatch al comportamiento pre-A4.
    expect($source)->toContain('skip-auth-wire');
    // El fallback a print sigue existiendo (config/auth.php ausente / formato raro).
    expect($source)->toContain('printAuthConfigSnippets');
});

test('mk:make:auth-user command auto-registers the ServiceProvider in Laravel 11+ bootstrap/providers.php', function () {
    $source = commandSource();
    expect($source)->toContain('registerServiceProvider');
    expect($source)->toContain("base_path('bootstrap/providers.php')");
});

test('mk:make:auth-user command builds the ServiceProvider FQCN with the Providers subnamespace (bug 1.3.0-001)', function () {
    // The provider is generated at app/Modules/{Scope}/Providers/{Scope}ServiceProvider.php
    // (see auth-user.service-provider.stub) — so the FQCN written into
    // bootstrap/providers.php MUST include the `Providers\` subnamespace.
    // The previous version omitted it, producing
    // `App\Modules\{Scope}\{Scope}ServiceProvider::class` which Laravel
    // could not resolve and the module loaded zero routes.
    $source = commandSource();

    // The file source contains literal `\\` (two backslash characters) inside
    // a PHP double-quoted string. In a single-quoted PHP literal, `\\` is
    // an escape for one `\`, so we need FOUR backslashes in source to
    // produce TWO backslashes in the runtime string.
    $correctFqn = 'App\\\\Modules\\\\{$scope}\\\\Providers\\\\{$scope}ServiceProvider::class';
    $brokenFqn = 'App\\\\Modules\\\\{$scope}\\\\{$scope}ServiceProvider::class';

    expect($source)->toContain($correctFqn);
    expect($source)->not->toContain($brokenFqn);
});

test('mk:make:auth-user package does NOT ship a hardcoded create_admins_table migration (bug 1.3.0-002)', function () {
    // The package used to ship `2026_06_10_000006_create_admins_table.php`
    // as a leftover from the original Admin scope. Combined with the
    // scaffolder's own migration (which also creates the `admins` table
    // for any scope called `admin`), this caused `php artisan migrate`
    // to fail with "Table 'admins' already exists".
    //
    // The scaffolder is the canonical source for the scope's table —
    // the hardcoded migration was deleted in 1.3.1.
    $migrationsDir = packageRoot().'/src/Auth/Database/Migrations';
    $hardcoded = $migrationsDir.'/2026_06_10_000006_create_admins_table.php';
    expect(file_exists($hardcoded))->toBeFalse(
        'Hardcoded admins migration must be removed from the package. '.
        'The scaffolder (auth-user.migration.stub) is the canonical source.'
    );
});

// ── Model stub ──────────────────────────────────────────────────────────

test('auth-user model stub extends AuthUser and pins auth_scope in the constructor', function () {
    $source = stubSource('auth-user.model.stub');

    expect($source)->toContain('extends AuthUser');
    expect($source)->toContain("setAuthScope('{{moduleNameLower}}')");
    expect($source)->toContain("protected \$table = '{{moduleNamePluralLower}}'");
    expect($source)->toContain('declare(strict_types=1)');
});

// ── Migration stub ──────────────────────────────────────────────────────

test('auth-user migration stub creates the scope table with indexed auth_scope', function () {
    $source = stubSource('auth-user.migration.stub');

    expect($source)->toContain("Schema::create('{{moduleNamePluralLower}}'");
    expect($source)->toContain("string('auth_scope')->default('{{moduleNameLower}}')");
    expect($source)->toContain('->index()');
    expect($source)->toContain('{{moduleNameLower}}_password_reset_tokens');
});

// ── AuthController stub ─────────────────────────────────────────────────

test('auth-user auth-controller stub exposes all six endpoints', function () {
    $source = stubSource('auth-user.auth-controller.stub');

    // 6 endpoints
    foreach (['login', 'refresh', 'logout', 'me', 'forgot', 'reset'] as $endpoint) {
        expect($source)->toContain("public function {$endpoint}(");
    }

    // R-PKG-014 BUG-07 fix: refresh() y reset() ya NO son skeletons. Implementación
    // completa con TokenIssuer::rotateRefreshToken() y password_reset_tokens lookup.
    // Las validamos explícitamente.
    expect($source)->toContain('rotateRefreshToken');
    expect($source)->toContain('password_reset_tokens');
});

test('auth-user auth-controller stub mentions TokenIssuer for the dev to wire up', function () {
    $source = stubSource('auth-user.auth-controller.stub');
    expect($source)->toContain('TokenIssuer');
});

test('auth-user auth-controller stub extends BaseController (bug 1.4.0-001)', function () {
    // Bug 1.4.0-001: the previous stub extended
    // `Illuminate\Routing\Controller` (Laravel stock) instead of the
    // package's `BaseController`. As a result, the generated
    // AuthController did NOT get:
    //   - the standard `{success, message, data, debugMsg}` envelope
    //   - `autoTransform()` (Model → API Resource transparent)
    //   - `getDebugData()` (EXPLAIN gated by role)
    //   - plugin instrumentation (audit log, multi-tenancy)
    // The fix: extend `Mk\Director\Controllers\BaseController`.
    $source = stubSource('auth-user.auth-controller.stub');

    expect($source)->toContain('use Mk\\Director\\Controllers\\BaseController;');
    expect($source)->toContain('class AuthController extends BaseController');
    // And explicitly must NOT extend Laravel's stock Controller.
    expect($source)->not->toContain('use Illuminate\\Routing\\Controller;');
});

test('auth-user auth-controller stub uses sendResponse / sendError envelope (bug 1.4.0-002)', function () {
    // Bug 1.4.0-002: the previous stub used `response()->json([...])`
    // for all 6 endpoints, producing 6 different ad-hoc shapes.
    // The fix: use `BaseController::sendResponse()` and
    // `BaseController::sendError()` for the standard envelope.
    $source = stubSource('auth-user.auth-controller.stub');

    // Must use the package envelope helpers
    expect($source)->toContain('$this->sendResponse(');
    expect($source)->toContain('$this->sendError(');

    // The login / refresh / logout / me / forgot / reset methods must
    // route through sendResponse or sendError — NOT raw response()->json
    // inside the AuthController's own methods.
    expect($source)->not->toContain('response()->json(');
});

test('auth-user auth-controller stub uses TokenIssuer::issueAccessToken in login (bug 1.4.0-003)', function () {
    // Bug 1.4.0-003: the previous stub used `$user->createToken(...)`
    // directly, bypassing the package's `TokenIssuer` service. The
    // package's TokenIssuer handles:
    //   - the `auth_scope` ability baking
    //   - the configurable TTLs (`mk_director.auth.ttl.*`)
    //   - the token naming convention
    // The fix: route token issuance through `TokenIssuer::issueAccessToken`.
    $source = stubSource('auth-user.auth-controller.stub');

    expect($source)->toContain('use Mk\\Director\\Auth\\Services\\TokenIssuer;');
    expect($source)->toContain('new TokenIssuer()');
    expect($source)->toContain('->issueAccessToken(');
    // And the previous raw Sanctum call must be gone.
    expect($source)->not->toContain('$user->createToken(');
});

// ── Routes stub ─────────────────────────────────────────────────────────

test('auth-user routes stub uses mk.auth:{scope} middleware for protected endpoints', function () {
    $source = stubSource('auth-user.routes.stub');

    // Bug 1.3.0-003 fix: routes must be prefixed with `api/` because
    // Laravel 11+ `loadRoutesFrom` from a ServiceProvider does NOT
    // inherit the `apiPrefix` from `bootstrap/app.php` (that only
    // applies to the central `routes/api.php`). The AuthController
    // docblock, the command's success output, and CHANGELOG 1.3.0
    // all advertised `/api/{scope}/auth/*`; the stub now matches.
    expect($source)->toContain("prefix('api/{{moduleNameLower}}/auth')");
    expect($source)->toContain("'mk.auth:{{moduleNameLower}}'");
    expect($source)->toContain('Route::post(\'login\'');
    expect($source)->toContain('Route::post(\'refresh\'');
    expect($source)->toContain('Route::post(\'logout\'');
    expect($source)->toContain('Route::get(\'me\'');
    expect($source)->toContain('Route::post(\'forgot\'');
    expect($source)->toContain('Route::post(\'reset\'');
});

// ── ServiceProvider stub ────────────────────────────────────────────────

test('auth-user service-provider stub loads routes and migrations for the scope', function () {
    $source = stubSource('auth-user.service-provider.stub');

    expect($source)->toContain('extends ServiceProvider');
    expect($source)->toContain('loadRoutesFrom(__DIR__ . \'/../Http/Routes/api.php\')');
    expect($source)->toContain('loadMigrationsFrom(__DIR__ . \'/../Database/Migrations\')');
});

// ── R-PKG-018 BUG-NEW-27 regression tests ────────────────────────────────
//
// BUG: el catch del método `refresh()` solo capturaba
// `\Illuminate\Auth\Access\AuthorizationException`. La excepción específica
// del paquete `InvalidRefreshTokenException` SÍ extiende AuthorizationException,
// así que el catch la capturaba — pero con un mensaje genérico
// ("Refresh token inválido.") en vez del específico (e.g. "Refresh token
// expired.", "Refresh token scope mismatch: ...").
//
// FIX: agregar un catch específico para `InvalidRefreshTokenException` ANTES
// del catch genérico. El catch específico expone el mensaje detallado vía
// `sendError($e->getMessage(), [], 401)` para mejor DX y testabilidad.

test('R-PKG-018 BUG-NEW-27: auth-controller stub imports InvalidRefreshTokenException', function () {
    $source = stubSource('auth-user.auth-controller.stub');

    expect($source)->toContain('use Mk\\Director\\Auth\\Services\\InvalidRefreshTokenException;');
});

test('R-PKG-018 BUG-NEW-27: auth-controller stub refresh() catches InvalidRefreshTokenException before AuthorizationException', function () {
    $source = stubSource('auth-user.auth-controller.stub');

    // El catch específico debe estar ANTES del catch genérico (orden importa
    // porque PHP evalúa los catch en secuencia).
    $invalidPosition = strpos($source, 'catch (InvalidRefreshTokenException');
    $authzPosition = strpos($source, 'catch (\\Illuminate\\Auth\\Access\\AuthorizationException');

    expect($invalidPosition)->toBeGreaterThan(0)
        ->and($authzPosition)->toBeGreaterThan(0)
        ->and($invalidPosition)->toBeLessThan($authzPosition);

    // El catch específico debe usar $e->getMessage() para mensajes detallados.
    expect($source)->toContain('catch (InvalidRefreshTokenException $e)');
    expect($source)->toContain('$e->getMessage()');
});

test('R-PKG-018 BUG-NEW-27: auth-controller stub refresh() sends 401 status', function () {
    $source = stubSource('auth-user.auth-controller.stub');

    // Ambos catches (específico y genérico) deben retornar 401.
    expect($source)->toMatch('/sendError\s*\([^,]+,\s*\[\s*\]\s*,\s*401\s*\)/');
});

// ── F10-B03 regression tests (R-PKG-050) ─────────────────────────────────
//
// Bug: la fase base del scaffolder pineaba Http/Requests/LoginRequest.php
// y Http/Requests/MeRequest.php sin crear la carpeta `Http/Requests/`
// (el array $directories solo tenía 5 entradas: Models, Http/Controllers,
// Http/Routes, Database/Migrations, Providers). File::put reventaba con
// "Failed to open stream: No such file or directory" y el scaffolder
// abortaba a mitad de camino (7-8 archivos pineados de los 30+ prometidos).
//
// Fix: (1) agregar 'Http/Requests' al array $directories, y (2) defense-in-
// depth en generateStub() con File::ensureDirectoryExists(dirname($targetPath))
// antes de File::put.

test('F10-B03: $directories array en fase base incluye Http/Requests (regression guard)', function () {
    $source = commandSource();

    // El array $directories de fase base debe incluir 'Http/Requests'
    // para que la creación de carpetas sea completa y consistente con el
    // output "📁 Creando estructura de directorios:".
    expect($source)->toMatch(
        '/\$directories\s*=\s*\[[^\]]*\'Http\/Requests\'[^\]]*\]/s',
    );
});

test('F10-B03: generateStub() llama File::ensureDirectoryExists ANTES de File::put (defense-in-depth)', function () {
    $source = commandSource();

    // El helper generateStub() debe asegurar que el directorio destino
    // existe antes de pinear el archivo. Esto cubre:
    //   - Stubs nuevos pineados sin actualizar $directories
    //   - Fases donde se pinea un stub en una carpeta no pre-creada
    //   - Tests de sandbox que llaman generateStub() directamente
    expect($source)->toContain('File::ensureDirectoryExists(dirname($targetPath))');

    // Verificar el ORDEN: ensureDirectoryExists debe estar ANTES de File::put
    // en la función generateStub(). Si alguien los invierte, el bug regresa.
    $ensurePos = strpos($source, 'File::ensureDirectoryExists(dirname($targetPath))');
    $putPos = strpos($source, 'File::put($targetPath, $content)');
    expect($ensurePos)->toBeGreaterThan(0)
        ->and($putPos)->toBeGreaterThan(0)
        ->and($ensurePos)->toBeLessThan($putPos);
});

test('F10-B03: fase base pinea Http/Requests/LoginRequest.php y Http/Requests/MeRequest.php (no se pierde)', function () {
    $source = commandSource();

    // Sanity check: la fase base debe seguir pineando LoginRequest y MeRequest
    // en Http/Requests/. Si alguien refactorea y mueve estos stubs, debe
    // actualizar este test (no el array $directories).
    expect($source)->toContain("'auth-user.login-request.stub', 'Http/Requests', 'LoginRequest.php'");
    expect($source)->toContain("'auth-user.me-request.stub', 'Http/Requests', 'MeRequest.php'");
});

// ── F10-B04 regression tests (R-PKG-050) ─────────────────────────────────
//
// Bug: ensureCorsConfig() leía `$this->option('with-auth-rbac')` para
// decidir qué paths CORS pinear en config/cors.php. El flag `--with-auth-rbac`
// fue ELIMINADO en R-PKG-047 D2 (ahora default ON, opt-out via `--no-rbac`).
// El read del option eliminado lanzaba
// `InvalidArgumentException: The option 'with-auth-rbac' does not exist`
// y abortaba el scaffolder justo después de pinear el ServiceProvider.
//
// Audit completa (R-PKG-050): es el ÚNICO zombie runtime. El flag
// `--with-rbac` en MakeModuleCommand (otro comando) sigue vigente.
// Las otras menciones de `--with-auth-rbac` son comentarios históricos
// y BC break notes que deben quedarse como guía de migración.

test('F10-B04: source NO contiene $this->option(\'with-auth-rbac\') (regression guard)', function () {
    $source = commandSource();

    // El read directo del flag eliminado debe estar pineado. Si alguien
    // re-introduce `$this->option('with-auth-rbac')` en runtime code, este
    // test falla. (Los comentarios históricos / BC break notes pueden
    // mencionar el flag — eso es OK y se queda como guía de migración.)
    expect($source)->not->toMatch("/\\\$this->option\\(\\s*['\"]with-auth-rbac['\"]\\s*\\)/");
});

test('F10-B04: ensureCorsConfig() usa la semántica --no-rbac (post-D2)', function () {
    $source = commandSource();

    // F10-B04 fix: el read del option es `! (bool) $this->option('no-rbac')`
    // (semántica post-D2: opt-out del RBAC default ON). La lógica es:
    // si NO se pine --no-rbac → RBAC activo → incluir 'sanctum/csrf-cookie'
    // en CORS. Si se pine --no-rbac → RBAC off → no incluirlo.
    //
    // Pin: el pattern debe aparecer al menos una vez en el source.
    expect($source)->toContain("! (bool) \$this->option('no-rbac')");
});

// ── F10-B17 regression tests (R-PKG-050) ─────────────────────────────────
//
// Bug: los stubs `store-admin-request.stub` y `update-admin-request.stub`
// pinean los placeholders `{{loginFieldValidationRuleStore}}` y
// `{{loginFieldValidationRuleUpdate}}` respectivamente. El command
// source los pineaba como keys en `$loginFieldReplacements` (fase base)
// pero NO en `$crudReplacements` (CRUD pack). Resultado: el CRUD pack
// pineaba los placeholders literales en StoreAdminRequest.php:38 y
// UpdateAdminRequest.php:38, causando `ParseError: syntax error,
// unexpected token "{"` al primer POST / PATCH.
//
// Fix: agregar ambas keys a `$crudReplacements` con la misma lógica
// que `{{loginFieldValidationRule}}` (línea ~1515). El array CRUD pack
// ahora pinea los 3 variants: base, Store, Update.

test('F10-B17: $crudReplacements array incluye {{loginFieldValidationRuleStore}} y {{loginFieldValidationRuleUpdate}} (regression guard)', function () {
    $source = commandSource();

    // F10-B17 fix: las keys `{{loginFieldValidationRuleStore}}` y
    // `{{loginFieldValidationRuleUpdate}}` deben existir como keys
    // de algún array de replacements en el command source (probablemente
    // $crudReplacements). Si se borran, los stubs pinean el placeholder
    // literal y los endpoints POST/PATCH revientan con ParseError.
    expect($source)->toContain("'{{loginFieldValidationRuleStore}}' =>");
    expect($source)->toContain("'{{loginFieldValidationRuleUpdate}}' =>");
});

test('F10-B17: stubs de CRUD pinean los placeholders Store/Update (sanity check del diseño)', function () {
    $storeStub = stubSource('auth-user/store-admin-request.stub');
    $updateStub = stubSource('auth-user/update-admin-request.stub');

    // Sanity: los stubs deben pinear exactamente los placeholders que el
    // command reemplaza. Si se refactorea el stub para usar otro nombre,
    // hay que actualizar tanto el stub como el command (y este test).
    expect($storeStub)->toContain('{{loginFieldValidationRuleStore}}');
    expect($updateStub)->toContain('{{loginFieldValidationRuleUpdate}}');
});
