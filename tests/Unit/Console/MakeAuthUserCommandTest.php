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

// ── F10-B05 regression tests (R-PKG-050) ─────────────────────────────────
//
// Bug: `buildProfileFieldsFillable()`, `buildProfileFieldsFromRequest()`
// y `buildProfileFieldsFromArray()` (DTO) pineaban TODOS los profile fields
// (defaults + user-provided). Los defaults incluyen `name`, `$loginField`
// (e.g. `email`), `phone`, `status` — pero el stub `admin-data-dto.stub`
// YA pinea hardcoded `name`, `{{loginField}}`, `password` en su constructor
// (y `{{statusDtoParam}}` pinea `status`). Resultado: 2x `public string $name`,
// 2x `public string $email`, 2x `public ?string $status = null,` → PHP fatal
// `Redefinition of parameter $name`.
//
// Fix: dedup contra los core fields pineados en el stub. Los 3 helpers DTO
// ahora aceptan `$loginField` y skipean las keys `['name', $loginField,
// 'password', 'status']`. El caller en `$crudReplacements` pasa `$loginField`.

test('F10-B05: buildProfileFieldsFillable() acepta $loginField y dedup core fields (regression guard)', function () {
    $source = commandSource();

    // Pin 1: la firma acepta $loginField.
    expect($source)->toMatch('/function buildProfileFieldsFillable\(\s*array\s+\$profileFields\s*,\s*string\s+\$loginField\s*=/');

    // Pin 2: el helper pine $coreFields con las 4 keys pineadas en el stub.
    expect($source)->toMatch(
        '/function buildProfileFieldsFillable\([\s\S]*?\$coreFields\s*=\s*\[\s*[\'"]name[\'"]\s*,\s*\$loginField\s*,\s*[\'"]password[\'"]\s*,\s*[\'"]status[\'"]\s*\]/',
    );
});

test('F10-B05: buildProfileFieldsFromRequest() acepta $loginField y dedup core fields (regression guard)', function () {
    $source = commandSource();

    expect($source)->toMatch('/function buildProfileFieldsFromRequest\(\s*array\s+\$profileFields\s*,\s*string\s+\$loginField\s*=/');
    expect($source)->toMatch(
        '/function buildProfileFieldsFromRequest\([\s\S]*?\$coreFields\s*=\s*\[\s*[\'"]name[\'"]\s*,\s*\$loginField\s*,\s*[\'"]password[\'"]\s*,\s*[\'"]status[\'"]\s*\]/',
    );
});

test('F10-B05: buildProfileFieldsFromArray() acepta $loginField y dedup core fields (regression guard)', function () {
    $source = commandSource();

    expect($source)->toMatch('/function buildProfileFieldsFromArray\(\s*array\s+\$profileFields\s*,\s*string\s+\$loginField\s*=/');
    expect($source)->toMatch(
        '/function buildProfileFieldsFromArray\([\s\S]*?\$coreFields\s*=\s*\[\s*[\'"]name[\'"]\s*,\s*\$loginField\s*,\s*[\'"]password[\'"]\s*,\s*[\'"]status[\'"]\s*\]/',
    );
});

test('F10-B05: $crudReplacements pasa $loginField a los 3 helpers DTO (regression guard)', function () {
    $source = commandSource();

    // El caller en $crudReplacements debe pasar $loginField a los 3 helpers.
    // Si alguien borra el segundo arg, el dedup skipea 'email' siempre aunque
    // el scope use 'ci' → bug silencioso.
    expect($source)->toContain("buildProfileFieldsFillable(\$profileFields, \$loginField)");
    expect($source)->toContain("buildProfileFieldsFromRequest(\$profileFields, \$loginField)");
    expect($source)->toContain("buildProfileFieldsFromArray(\$profileFields, \$loginField)");
});
// ── F10-B07 regression test (R-PKG-050) ────────────────────────────────────
//
// Bug: el match de tipos en `buildProfileFieldsFillable()` mapaba `file` al
// `default => '?mixed'`. Pero `?mixed` es **inválido en PHP 8** (`Type
// mixed cannot be marked as nullable since mixed already includes null`).
// Resultado: el DTO scaffoldeado no se podía cargar (ParseError).
//
// Fix: agregar `'file' => '?string'` al match. El path del archivo
// (`uploads/avatars/abc.jpg`) se guarda como string en la columna
// `{field}_path` (ver `PROFILE_FIELD_TYPES['file']['column_method']`).

test('F10-B07: buildProfileFieldsFillable() mapea type=file a ?string (PHP válido)', function () {
    $source = commandSource();

    // Pin: el match en buildProfileFieldsFillable() tiene el case `file => '?string'`.
    // Sin este case, `file` cae en el `default => '?mixed'` que PHP rechaza.
    expect($source)->toMatch(
        "/'file'\s*=>\s*'\\?string'/",
    );
});
// ── F10-B10 + F10-B13 regression tests (R-PKG-050) ───────────────────────
//
// Bug B10: la migration pineaba columnas duplicadas (name, email, status)
// porque `buildProfileFieldsReplacements()` emitía todas las profile fields
// (defaults + user) sin dedup contra las columnas hardcoded en el stub
// (`name`, `{{loginField}}`, `photo_path`, `email_verified_at`, `password`).
// Resultado: `migrate:fresh` fallaba con `column "X" specified more than once`.
//
// Bug B13: el Model $fillable tenía el mismo bug — pineaba 'name', 'email',
// 'status' duplicados (hardcoded + helper).
//
// Fix: ambos bugs viven en `buildProfileFieldsReplacements()`. El helper
// ahora acepta `$loginField` y dedup contra los core fields pineados
// hardcoded en los stubs (`name`, `$loginField`, `photo_path`,
// `email_verified_at`, `password`, `auth_scope`, `client_id`, `status`).
//
// Side fix: el enum en `{{statusColumn}}` ahora pinea 'blocked' (R-PKG-047
// D4 BC break) en vez del legacy 'suspended'. Idem el enum-status.stub
// pinea `case Blocked = 'blocked';` (covered separately in F10-B12).

test('F10-B10: buildProfileFieldsReplacements() acepta $loginField y dedup contra core fields', function () {
    $source = commandSource();

    // Pin 1: la firma acepta $loginField.
    expect($source)->toMatch('/function buildProfileFieldsReplacements\(\s*array\s+\$fields\s*,\s*array\s+\$requiredFields\s*=\s*\[\]\s*,\s*string\s+\$loginField\s*=/');

    // Pin 2: el helper pine $coreFields con las keys pineadas en los stubs.
    // Solo chequeo las 3 keys más críticas (name, $loginField, status) que
    // son los conflictos reales (los otros 5 son defense-in-depth).
    expect($source)->toMatch(
        '/function buildProfileFieldsReplacements\([\s\S]*?\$coreFields\s*=\s*\[[^\\]]*[\'"]name[\'"][^\\]]*\$loginField[^\\]]*[\'"]status[\'"]\s*\]/',
    );
});

test('F10-B10: caller en handle() pasa $loginField a buildProfileFieldsReplacements()', function () {
    $source = commandSource();

    // El caller en handle() (línea ~389) debe pasar $loginField para que
    // el dedup matchee el valor real (e.g. 'email' default, 'ci' para RETO).
    expect($source)->toContain('buildProfileFieldsReplacements($profileFieldsRaw, $requiredFields, $loginField)');
});

test('F10-B10: $fillable emission SKIP si key en core fields (no duplicate)', function () {
    $source = commandSource();

    // Verificar que el skip está pineado en la sección de $fillable
    // (no en $columns). El pattern es `if (! $isCore) { $fillable .= ... }`.
    // Pin: dentro del foreach, antes del $fillable, hay un check $isCore.
    expect($source)->toMatch('/if\s*\(\s*!\s*\$isCore\s*\)\s*\{\s*\$\s*fillable\s*\.\=/');
});

test('F10-B10: $columns emission SKIP si key en core fields (no duplicate)', function () {
    $source = commandSource();

    // Idem para $columns — el helper skip columnas pineadas hardcoded
    // en el migration stub (name, {{loginField}}, photo_path, etc.).
    expect($source)->toMatch('/if\s*\(\s*!\s*\$isCore\s*\)\s*\{\s*\$\s*columns\s*\.\=/');
});

test('F10-B10: {{statusColumn}} enum pinea blocked (no suspended) — R-PKG-047 D4', function () {
    $source = commandSource();

    // R-PKG-047 D4 BC break: el enum canónico post-D4 es `Blocked` (no
    // `Suspended`). El helper `{{statusColumn}}` en el array de replacements
    // pinea el enum con los 4 estados canónicos.
    //
    // Pin: la string `'blocked'` aparece en el source (pinea `'active','inactive','blocked','pending'`).
    expect($source)->toContain("'blocked'");

    // Pin: la string legacy `'suspended'` NO aparece en el array de status
    // (puede aparecer en otros comentarios / BC break notes — verificar solo
    // que NO está en el `\$table->enum(...)` line).
    expect($source)->not->toContain("['active','inactive','suspended','pending']");
});
// ── F10-B01 + F10-B02 regression tests (R-PKG-050) ────────────────────────
//
// Bug B01: resolveProfileFields() retorna `null` cuando detecta colisión
// (campo en `reserved` o duplicado en CSV). Pero el caller en handle()
// continuaba y llamaba resolveRequiredProfileFields($raw, $userFieldsRaw)
// con `$userFieldsRaw = null` → TypeError ugly. El check de
// `$profileFieldsRaw === null` existía más abajo pero llegaba TARDE.
//
// Bug B02: resolveRequiredProfileFields() signature era `array $profileFields`
// (rígida). No aceptaba `null` — TypeError inmediato.
//
// Fix combinado:
// - F10-B01: FAILURE temprano en handle() después de resolveProfileFields()
//   si retorna null. Mensaje ya impreso por resolveProfileFields().
// - F10-B02: signature cambia a `?array $profileFields` + early return `[]`.
//   Defense-in-depth por si el helper se invoca desde otros lugares.

test('F10-B01: handle() hace FAILURE temprano si resolveProfileFields() retorna null (colisión)', function () {
    $source = commandSource();

    // Pin: el check `if ($userFieldsRaw === null) return self::FAILURE;`
    // debe existir en handle() y estar ANTES de la llamada REAL a
    // resolveRequiredProfileFields() (no la mención en el comment).
    //
    // Usamos '$this->resolveRequiredProfileFields(' (con $this->) para
    // skipear las menciones en docstrings/comentarios.
    $nullCheckPos = strpos($source, 'if ($userFieldsRaw === null)');
    $resolveReqPos = strpos($source, '$this->resolveRequiredProfileFields(');

    expect($nullCheckPos)->toBeGreaterThan(0)
        ->and($resolveReqPos)->toBeGreaterThan(0)
        ->and($nullCheckPos)->toBeLessThan($resolveReqPos);
});

test('F10-B02: resolveRequiredProfileFields() acepta ?array y retorna [] si null (defense-in-depth)', function () {
    $source = commandSource();

    // Pin 1: la firma acepta `?array $profileFields` (no `array` rígido).
    expect($source)->toMatch('/function resolveRequiredProfileFields\(\s*string\s+\$raw\s*,\s*\?array\s+\$profileFields\s*\)/');

    // Pin 2: la función pine early return `[]` si `$profileFields === null`.
    expect($source)->toMatch('/function resolveRequiredProfileFields\([\s\S]*?if\s*\(\s*\$profileFields\s*===\s*null\s*\)\s*\{\s*return\s*\[\s*\]\s*;\s*\}/');
});
// ── F10-B12 regression tests (R-PKG-050) ──────────────────────────────────
//
// Bug: `enum-status.stub` pineaba `case Suspended = 'suspended'` (pre-D4
// legacy). R-PKG-047 D4 BC break cambió el canon a `Blocked`. Además, el
// wrapper extiende `ScopeStatus` (base enum del paquete) que también tenía
// `case Suspended` — el scaffolder pineaba stubs que NO podían extender
// el case (mismatch entre base + wrapper).
//
// Fix: cambiar `Suspended` → `Blocked` en 3 lugares sincronizados:
// - `ScopeStatus` (base enum) — case + label match.
// - `enum-status.stub` (thin wrapper) — case + label match.
// - `$statusStates` array en handle() — para que el factory pinea
//   `blocked()` method (no `suspended()`).

test('F10-B12: ScopeStatus base enum pinea Blocked (no Suspended) — R-PKG-047 D4', function () {
    $source = file_get_contents(packageRoot().'/src/Auth/Enums/ScopeStatus.php');

    // Pin: la case `Blocked` existe con value 'blocked'.
    expect($source)->toMatch('/case\s+Blocked\s*=\s*[\'"]blocked[\'"]/');

    // Pin: la case `Suspended` NO existe (pre-D4 removida).
    expect($source)->not->toMatch('/case\s+Suspended\s*=/');
});

test('F10-B12: enum-status.stub pinea Blocked (no Suspended)', function () {
    $source = stubSource('auth-user/enum-status.stub');

    // Pin: la case `Blocked` existe.
    expect($source)->toMatch('/case\s+Blocked\s*=\s*[\'"]blocked[\'"]/');

    // Pin: la case `Suspended` NO existe.
    expect($source)->not->toMatch('/case\s+Suspended\s*=/');

    // Pin: el match del label() tiene `self::Blocked => 'Bloqueado'`.
    expect($source)->toMatch("/self::Blocked\\s*=>\\s*'Bloqueado'/");
});

test('F10-B12: \$statusStates array pinea Blocked (no Suspended) para factory state methods', function () {
    $source = commandSource();

    // Pin: \$statusStates incluye 'Blocked', no 'Suspended'.
    expect($source)->toMatch("/\\\$statusStates\\s*=\\s*\\\$withStatus\\s*\\?\\s*\\[[^\\]]*'Blocked'[^\\]]*\\]\\s*:\\s*\\[\\s*\\]/");
    expect($source)->not->toMatch("/\\\$statusStates\\s*=\\s*\\\$withStatus\\s*\\?\\s*\\[[^\\]]*'Suspended'[^\\]]*\\]/");
});
// ── F10-B14 regression tests (R-PKG-050) ──────────────────────────────────
//
// Bug: --managed-by=<Manager> pineaba el endpoint /api/{manager}/{scopePlural}
// (cross-scope CRUD) pero NO pineaba la FK `{manager}_id` en la tabla del
// scope ni la relation BelongsTo en el modelo. El consumer tenía que
// agregar ambos a mano en la migration + el modelo (workaround documentado
// en feedback-api § 4.2 W2-W4).
//
// Fix: agregar 2 placeholders nuevos sincronizados:
// - `{{managedByColumn}}` en auth-user.migration.stub → FK nullable + onDelete set null.
// - `{{managedByRelation}}` en auth-user.model.stub → `belongsTo({Manager}::class)`.
//
// Emissions en handle() condicionales a `$managedBy !== null`. Si el flag
// no se pasa, los placeholders son string vacío (no se pinea nada).

test('F10-B14: migration stub pinea {{managedByColumn}} placeholder (cross-scope FK)', function () {
    $source = stubSource('auth-user.migration.stub');

    // Pin: el placeholder {{managedByColumn}} está en el stub (será
    // reemplazado por la FK o string vacío según --managed-by).
    expect($source)->toContain('{{managedByColumn}}');
});

test('F10-B14: model stub pinea {{managedByRelation}} placeholder (cross-scope BelongsTo)', function () {
    $source = stubSource('auth-user.model.stub');

    expect($source)->toContain('{{managedByRelation}}');
});

test('F10-B14: handle() pinea managedByReplacements con FK + BelongsTo cuando $managedBy != null', function () {
    $source = commandSource();

    // Pin 1: el command source construye un array \$managedByReplacements
    // con la FK y la relation cuando --managed-by se pasa.
    expect($source)->toContain('foreignUuid(');
    expect($source)->toContain('->constrained(');

    // Pin 2: la relation BelongsTo está pineada (el heredoc usa
    // `{$managedByLower}` como nombre del método + return type BelongsTo).
    expect($source)->toMatch('/public function \{\$managedByLower\}\(\)[^;]+BelongsTo/');

    // Pin 3: los placeholders {{managedByColumn}} y {{managedByRelation}} están
    // en el array_merge final (no como strings sueltos, sino como keys).
    expect($source)->toContain("'{{managedByColumn}}' =>");
    expect($source)->toContain("'{{managedByRelation}}' =>");
});
// ── F10-B08 + F10-B09 regression tests (R-PKG-050) ───────────────────────
//
// Bug B08: DiscoverAbilitiesCommand.php:74 pineaba
// `array_intersect_key($allModules, array_flip($moduleArgs))` donde
// `$moduleArgs` venía de `$this->option('module')`. Si se llamaba
// programáticamente con `'--module' => $scope` (string, no array),
// `array_flip('Admin')` reventaba con TypeError.
//
// Bug B09: caller-side en MakeAuthUserCommand pineaba
// `'--module' => $scope` (string). El mismo bug desde la otra superficie.
//
// Fix combinado (defense-in-depth):
// - B08: type-coerce `$moduleArgs` a array (receiver-side). Idempotente
//   para callers que ya pasan array.
// - B09: caller pine `[$scope]` (array de 1) en vez de `$scope` (string).

test('F10-B08: DiscoverAbilitiesCommand type-coerce $moduleArgs a array (defense-in-depth)', function () {
    $source = file_get_contents(packageRoot().'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    // Pin: el read del option está seguido de un type-coerce a array.
    // El check: `if (! is_array($moduleArgs)) { ... }` debe existir
    // inmediatamente después del read del option.
    expect($source)->toMatch(
        "/\\\$moduleArgs\\s*=\\s*\\\$this->option\\(['\"]module['\"]\\)\\s*;\\s*if\\s*\\(\\s*!\\s*is_array\\(\\s*\\\$moduleArgs\\s*\\)/",
    );

    // Pin: la rama del if pinea el array. Aceptamos cualquier shape
    // (ternary simple o asignación directa).
    expect($source)->toMatch(
        '/if\s*\(\s*!\s*is_array\(\s*\$moduleArgs\s*\)\s*\)\s*\{[^}]*\$moduleArgs\s*=\s*[^;]*\[\s*\$moduleArgs\s*\][^;]*;/s',
    );
});

test('F10-B09: MakeAuthUserCommand caller pine [$scope] (array) en vez de $scope (string)', function () {
    $source = commandSource();

    // El caller en runPostScaffoldSteps (línea ~1192) debe pinear
    // `'--module' => [$scope]` (array de 1) en vez de `$scope` (string).
    expect($source)->toContain("'--module' => [\$scope]");
});
