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
//
// R-PKG-047 D1 (2026-07-09): el stub `auth-user.auth-controller.stub` pasó
// de ~500 LOC a un thin wrapper de 148 LOC. Los métodos `login()`,
// `refresh()`, `logout()`, `me()`, `forgot()`, `reset()` viven en
// `BaseAuthController` (SSoT canónico). El thin wrapper solo override
// los 4 abstracts (authModelClass, authScope, loginField, passwordResetTable).
//
// Tests ELIMINADOS post-D1 (pineaban features del stub VIEJO):
//   - "exposes all six endpoints" — el thin wrapper no expone los 6
//     métodos, los hereda de BaseAuthController.
//   - "mentions TokenIssuer" — TokenIssuer está en BaseAuthController.
//   - "extends BaseController (bug 1.4.0-001)" — ahora extends BaseAuthController.
//   - "uses sendResponse / sendError envelope" — el thin wrapper no usa
//     esos helpers (los métodos están en BaseAuthController).
//   - "uses TokenIssuer::issueAccessToken in login" — el thin wrapper no
//     tiene login() inline.
//
// Ver test al final del bloque BUG-NEW-27: `R-PKG-047 D1: BaseAuthController
// es SSoT — pinean los 6 métodos canónicos + imports de TokenIssuer e
// InvalidRefreshTokenException`.
//
//
// ── Routes stub ─────────────────────────────────────────────────────────

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
    // F10-B06: paths bajo password/* + método real forgotPassword/resetPassword
    // (forgot()/reset() no existen en BaseAuthController).
    expect($source)->toContain('Route::post(\'password/forgot\'');
    expect($source)->toContain('Route::post(\'password/reset\'');
    expect($source)->toContain('forgotPassword');
    expect($source)->toContain('resetPassword');
});

// 2026-07-15-profile-edit-password-otp Phase 2.4/2.6 — the two OTP
// password-change routes are UNCONDITIONAL additions to the protected
// group (like `logout`/`me`), each with its own throttle middleware
// reading `mk_director.auth.rate_limits.password_code_*`. `password/change`
// must still be present (additive proof, no regression).
test('auth-user routes stub pins password/code/request + password/code/confirm inside the protected group, each with its own throttle', function () {
    $source = stubSource('auth-user.routes.stub');

    expect($source)->toContain('Route::post(\'password/code/request\'');
    expect($source)->toContain('requestPasswordCode');
    expect($source)->toContain('Route::post(\'password/code/confirm\'');
    expect($source)->toContain('confirmPasswordCode');

    expect($source)->toContain("config('mk_director.auth.rate_limits.password_code_request', '3,10')");
    expect($source)->toContain("config('mk_director.auth.rate_limits.password_code_confirm', '5,10')");

    // Still present — additive, no regression on the existing endpoint.
    expect($source)->toContain('Route::post(\'password/change\'');
    expect($source)->toContain('changePassword');

    // Both new routes live INSIDE the protected `mk.auth:{{moduleNameLower}}` group.
    $protectedGroupStart = strpos($source, "Route::middleware('mk.auth:{{moduleNameLower}}')");
    expect($protectedGroupStart)->not->toBeFalse();
    $protectedGroupBody = substr($source, $protectedGroupStart);
    expect($protectedGroupBody)->toContain('password/code/request');
    expect($protectedGroupBody)->toContain('password/code/confirm');
});

// ── ServiceProvider stub ────────────────────────────────────────────────

test('auth-user service-provider stub loads routes and migrations for the scope', function () {
    $source = stubSource('auth-user.service-provider.stub');

    expect($source)->toContain('extends ServiceProvider');
    expect($source)->toContain('loadRoutesFrom(__DIR__ . \'/../Http/Routes/api.php\')');
    expect($source)->toContain('loadMigrationsFrom(__DIR__ . \'/../Database/Migrations\')');
});

// ── R-PKG-018 BUG-NEW-27 ELIMINADO post-R-PKG-047 D1 ─────────────────────
//
// BUG-NEW-27 (original): el catch del método `refresh()` solo capturaba
// `\Illuminate\Auth\Access\AuthorizationException` (mensaje genérico). El
// fix pineado en su momento fue agregar un catch específico para
// `InvalidRefreshTokenException` ANTES del genérico, con
// `sendError($e->getMessage(), [], 401)` para mejor DX.
//
// Post-R-PKG-047 D1, el método `refresh()` ya NO vive en el stub scaffoldeado
// — vive en `BaseAuthController::refresh()` (SSoT canónico). El fix BUG-NEW-27
// se aplicó directamente en `BaseAuthController` (que SÍ importa
// `InvalidRefreshTokenException` y tiene el catch específico).
//
// Ver test al final: `R-PKG-047 D1: BaseAuthController es SSoT — pinean
// los 6 métodos canónicos + imports de TokenIssuer e InvalidRefreshTokenException`.

test('R-PKG-047 D1: BaseAuthController es SSoT — pinean los 6 métodos canónicos + imports', function () {
    $basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';
    expect(file_exists($basePath))->toBeTrue('BaseAuthController must exist (R-PKG-047 D1 SSoT)');

    $base = (string) file_get_contents($basePath);

    // 6 métodos canónicos que el thin wrapper AuthController scaffoldeado
    // hereda sin override (D1).
    expect($base)->toContain('public function login(');
    expect($base)->toContain('public function refresh(');
    expect($base)->toContain('public function logout(');
    expect($base)->toContain('public function me(');
    expect($base)->toContain('public function forgotPassword(');
    expect($base)->toContain('public function resetPassword(');

    // BUG-NEW-27: BaseAuthController importa la excepción específica y
    // TokenIssuer. Esto pinean que el SSoT tiene la lógica que el stub
    // VIEJO pineaba inline.
    expect($base)->toContain('use Mk\\Director\\Auth\\Services\\InvalidRefreshTokenException;');
    expect($base)->toContain('use Mk\\Director\\Auth\\Services\\TokenIssuer;');
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
    expect($source)->toContain('buildProfileFieldsFillable($profileFields, $loginField)');
    expect($source)->toContain('buildProfileFieldsFromRequest($profileFields, $loginField)');
    expect($source)->toContain('buildProfileFieldsFromArray($profileFields, $loginField)');
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
// (`name`, `{{loginField}}`, `email_verified_at`, `password`). Resultado:
// `migrate:fresh` fallaba con `column "X" specified more than once`.
//
// Bug B13: el Model $fillable tenía el mismo bug — pineaba 'name', 'email',
// 'status' duplicados (hardcoded + helper).
//
// Fix: ambos bugs viven en `buildProfileFieldsReplacements()`. El helper
// ahora acepta `$loginField` y dedup contra los core fields pineados
// hardcoded en los stubs (`name`, `$loginField`, `email_verified_at`,
// `password`, `auth_scope`, `client_id`, `status`).
//
// FEEDBACK10 (R-PKG-050, Mario 2026-07-10): `photo_path` ya NO está en la
// dedup list — los file fields se pinean dinámicamente desde `:file` suffix
// via {{fileFields*}} placeholders. Si el consumer pinea
// `--profile-fields="photo_path:file"`, el scaffolder lo trata como un file
// field normal (columna `photo_path`, accessor `getPhotoPathUrlAttribute`).
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
// ── F10-B11 regression test (R-PKG-050) ───────────────────────────────────
//
// Bug: `discoverAbilitiesFromAttributesAndDocblocks()` leía
// `#[Ability('{scope}.auth.{action}')]` attributes y pineaba el LITERAL
// `'{scope}.auth.login'` (string con corchetes) como nombre de ability en
// la DB. Esto requería un workaround tinker post-scaffold para replace
// `{scope}` con el scope real.
//
// Fix: el helper ahora acepta `$scope` y hace `str_replace('{scope}', $scope, ...)`
// en el nombre y la description antes de pinear. El caller `processModule()`
// pasa `$scope = Str::snake(Str::plural($moduleName))` (e.g. `admins` para
// `Admin` module — matchea el `mk.ability:{scope}.{resource}.{action}`
// route middleware).

test('F10-B11: discoverAbilitiesFromAttributesAndDocblocks() reemplaza {scope} placeholder con scope real', function () {
    $source = file_get_contents(packageRoot().'/src/Console/Commands/DiscoverAbilitiesCommand.php');

    // Pin 1: el helper acepta \$scope como parámetro.
    expect($source)->toMatch(
        '/function discoverAbilitiesFromAttributesAndDocblocks\([\s\S]*?array\s+\$moduleInfo\s*,\s*string\s+\$scope\s*=/',
    );

    // Pin 2: dentro del loop de attributes, el name/description se pinea
    // con str_replace('{scope}', \$scope, ...) en lugar del raw.
    expect($source)->toMatch(
        '/str_replace\([\'"]\{scope\}[\'"]\s*,\s*\$scope\s*,\s*\$instance->name\)/',
    );
    expect($source)->toMatch(
        '/str_replace\([\'"]\{scope\}[\'"]\s*,\s*\$scope\s*,\s*\$instance->description\)/',
    );
});
// ── F10-B06 regression test (R-PKG-050) ───────────────────────────────────
//
// Bug: `--setup-sanctum` solo emitia un WARN si `laravel/sanctum` no
// estaba instalado. El consumer tenía que correr `composer require`
// ANTES del scaffolder (workflow contraintuitivo), o el scaffolder
// fallaba al publicar la migration sin Sanctum.
//
// Fix: setupSanctum() auto-corre `composer require laravel/sanctum --no-interaction`
// cuando Sanctum no está. Si la instalación falla, sugiera el comando
// manual en vez de abortar.

test('F10-B06: setupSanctum() auto-corre composer require si Sanctum no está instalado', function () {
    $source = commandSource();

    // Pin 1: setupSanctum() tiene un path de auto-install via composer require.
    // Verificamos que la cadena 'composer require laravel/sanctum' aparece
    // dentro del cuerpo de la función setupSanctum (no solo en otros lugares).
    $setupSanctumStart = strpos($source, 'function setupSanctum()');
    expect($setupSanctumStart)->toBeGreaterThan(0);

    // Encontrar el cierre de la función (primer `}` al mismo nivel de indent).
    // Simplificado: verificamos que 'composer require laravel/sanctum' aparece
    // en los siguientes ~3000 chars (la función tiene ~80 líneas, < 3K chars).
    $slice = substr($source, $setupSanctumStart, 5000);
    expect($slice)->toContain('composer require laravel/sanctum');

    // Pin 2: el comando usa --no-interaction y --no-progress (para no
    // requerir input del dev y no contaminar output con progress bars).
    expect($source)->toMatch('/composer\s+require\s+laravel\/sanctum[^\n]*--no-interaction/');
});
// ── F10-B18 regression test (R-PKG-050) ───────────────────────────────────
//
// Bug: `buildProfileFieldRules()` dedup era `['name', 'email', 'password',
// 'photo']` (F9-B01). NO incluía `status`. Cuando --with-status pineaba
// `status` en los defaults, el helper emitía `'status' => ['nullable',
// 'string']` (rule genérica) Y el helper `buildStatusRequestRuleStore/Update`
// pineaba `'status' => ['sometimes', 'nullable', 'string', Rule::enum(...)]`
// (rule con enum check). PHP array merge con key duplicada descartaba
// el primero — el enum check se perdía, aceptando cualquier string.
//
// Fix: agregar `status` al dedup list de `buildProfileFieldRules()`.

test('F10-B18: buildProfileFieldRules() dedup incluye status (no pine rule genérica + enum)', function () {
    $source = commandSource();

    // Pin: la dedup list de buildProfileFieldRules() incluye 'status'
    // además de los core fields (name, email, password, photo).
    expect($source)->toMatch(
        "/function buildProfileFieldRules\\([\\s\\S]*?\\\$coreFields\\s*=\\s*\\[[^\\]]*'status'[^\\]]*\\]/",
    );
});
// ── F10-B16 regression test (R-PKG-050) ───────────────────────────────────
//
// Bug: `PluginManager::registerPlugins()` iteraba el array sin checkear
// tipo. Si un caller pasaba configs (arrays) en vez de class names
// (strings), `registerPlugin($array)` reventaba con
// `TypeError: Argument #1 ($class) must be of type string, array given`.
//
// Fix: skip non-string values con `continue`. Defense-in-depth.

test('F10-B16: PluginManager::registerPlugins() skip non-string values (defense-in-depth)', function () {
    $source = file_get_contents(packageRoot().'/src/Managers/PluginManager.php');

    // Pin: el foreach de registerPlugins() tiene un `if (! is_string($class)) continue;`
    expect($source)->toMatch(
        '/function registerPlugins\([\s\S]*?foreach\s*\(\s*\$classes\s+as\s+\$class\s*\)\s*\{\s*if\s*\(\s*!\s*is_string\(\s*\$class\s*\)\s*\)\s*\{\s*continue\s*;/',
    );
});
// ── F10-B15 regression test (R-PKG-050) ───────────────────────────────────
//
// F10-B15 es un fix de DOC ONLY (R-G-032 sync). El bug (RefreshDatabase
// trait + SQLite in-memory) está documentado en SKILL.md con el workaround
// `DatabaseMigrations` trait. Pre-fix, los consumers encontraban el
// gotcha silencioso en sus tests e2e.
//
// Test: verifica que el SKILL.md canónico tiene la sección del gotcha
// con el workaround explícito. Si alguien borra la sección, este test
// falla (regression guard contra la knowledge regresión).

/**
 * Busca el SKILL.md canónico subiendo desde el paquete hasta encontrar el
 * workspace `.makromania/`. Devuelve null si no está.
 *
 * El SKILL.md NO vive en este repo: vive en el workspace Makromania. Antes acá
 * había la ruta ABSOLUTA de la máquina de Mario hardcodeada
 * (`/Users/marioguzman/Desktop/...`), así que este test solo podía pasar en su
 * notebook. En cualquier otro lado `file_get_contents` devolvía '' y el test
 * fallaba. Nunca se notó porque el paquete no tenía CI.
 */
function findMakromaniaSkillMd(): ?string
{
    $dir = dirname(__DIR__, 3);

    for ($i = 0; $i < 8; $i++) {
        $candidate = $dir.'/.makromania/agency/skills/mk-director-laravel/SKILL.md';

        if (file_exists($candidate)) {
            return $candidate;
        }

        $parent = dirname($dir);

        if ($parent === $dir) {
            break;
        }

        $dir = $parent;
    }

    return null;
}

test('F10-B15: SKILL.md documenta gotcha de RefreshDatabase + SQLite in-memory', function () {
    $skillPath = findMakromaniaSkillMd();

    if ($skillPath === null) {
        // En CI (y en cualquier clon suelto del paquete) el workspace no está.
        // Skip explícito en vez de un rojo que no dice nada: el guard tiene
        // valor donde el archivo existe, y donde no, no hay nada que verificar.
        test()->markTestSkipped(
            'SKILL.md no encontrado: requiere el workspace .makromania/, que no forma parte de este repo.',
        );
    }

    $source = (string) file_get_contents($skillPath);

    // Pin 1: la sección F10-B15 existe.
    expect($source)->toContain('F10-B15');

    // Pin 2: el workaround `DatabaseMigrations` está pineado.
    expect($source)->toContain('DatabaseMigrations');

    // Pin 3: el antipatrón `RefreshDatabase` está marcado como NO usar.
    expect($source)->toContain('NO HACER ESTO');
});

// ── FEEDBACK10 (R-PKG-050, Mario 2026-07-10) — file fields: dynamic scaffolding ─
//
// Post-FEEDBACK10, todos los artifacts que el scaffolder emitía con
// `photo_path` hardcoded se generan dinámicamente desde `:file` suffix.
// Estos tests pinean la INTENCIÓN (source-parsing) per HALLAZGO-NEW-03.
// EFECTIVIDAD se valida en RETO via smoke test E2E post-merge.
//
// Reflection sobre métodos protected (patrón feedback4Invoke).
function feedback10Invoke(string $method, array $args): mixed
{
    $command = new MakeAuthUserCommand;
    $ref = new \ReflectionMethod($command, $method);
    $ref->setAccessible(true);

    return $ref->invoke($command, ...$args);
}

test('FEEDBACK10: buildFileFieldsFillableEntries() emite fillable entries identity', function () {
    $out = feedback10Invoke('buildFileFieldsFillableEntries', [['avatar', 'cover_photo']]);

    expect($out)->toContain("'avatar',");
    expect($out)->toContain("'cover_photo',");
    // No sufijo _path.
    expect($out)->not->toContain("'avatar_path',");
});

test('FEEDBACK10: buildFileFieldsFillableEntries() retorna vacío si no hay file fields', function () {
    $out = feedback10Invoke('buildFileFieldsFillableEntries', [[]]);

    expect($out)->toBe('');
});

test('FEEDBACK10: buildFileFieldsAccessors() emite get{Name}UrlAttribute dinámico', function () {
    $out = feedback10Invoke('buildFileFieldsAccessors', [['avatar', 'cover_photo']]);

    // accessor para `avatar` → `getAvatarUrlAttribute`.
    expect($out)->toMatch('/function\s+getAvatarUrlAttribute\s*\(\s*\)\s*:\s*\?string/');
    expect($out)->toContain('Storage::url($this->avatar)');
    expect($out)->toContain('`avatar_url`');   // backticks en el docblock

    // accessor para `cover_photo` → `getCoverPhotoUrlAttribute`.
    expect($out)->toMatch('/function\s+getCoverPhotoUrlAttribute\s*\(\s*\)\s*:\s*\?string/');
    expect($out)->toContain('Storage::url($this->cover_photo)');
    expect($out)->toContain('`cover_photo_url`');

    // NO `getPhotoUrlAttribute` hardcoded.
    expect($out)->not->toMatch('/function\s+getPhotoUrlAttribute/');
});

test('FEEDBACK10: buildFileFieldsAccessors() retorna vacío si no hay file fields', function () {
    $out = feedback10Invoke('buildFileFieldsAccessors', [[]]);

    expect($out)->toBe('');
});

test('FEEDBACK10: buildFileFieldsResourceEntry() emite resource keys identity + _url', function () {
    $out = feedback10Invoke('buildFileFieldsResourceEntry', [['avatar']]);

    // `'avatar' => $this->avatar` + `'avatar_url' => $this->avatar_url`.
    expect($out)->toContain("'avatar' => \$this->avatar,");
    expect($out)->toContain("'avatar_url' => \$this->avatar_url,");
    // NO `photo_path`/`photo_url` hardcoded.
    expect($out)->not->toContain("'photo_path'");
    expect($out)->not->toContain("'photo_url'");
});

test('F10-B10: buildFileFieldsDeleteCleanup() limpia el/los file field(s) reales (no photo_path hardcoded)', function () {
    $out = feedback10Invoke('buildFileFieldsDeleteCleanup', [['avatar'], 'admin']);

    expect($out)->toContain('! empty($admin->avatar)');
    expect($out)->toContain('->delete($admin->avatar);');
    expect($out)->not->toContain('photo_path');
});

test('F10-B10: buildFileFieldsDeleteCleanup() emite un bloque por cada file field (multi-field)', function () {
    $out = feedback10Invoke('buildFileFieldsDeleteCleanup', [['avatar', 'cover_photo'], 'member']);

    expect($out)->toContain('! empty($member->avatar)');
    expect($out)->toContain('! empty($member->cover_photo)');
});

test('F10-B10: buildFileFieldsDeleteCleanup() retorna vacío si no hay file fields', function () {
    $out = feedback10Invoke('buildFileFieldsDeleteCleanup', [[], 'admin']);

    expect($out)->toBe('');
});

test('F10-B10: buildFileFieldsDeleteCleanup() usa interpolación PHP {$scopeLower}, NO el placeholder literal {{moduleNameLower}} (gotcha R-PKG-031 PKG-NEW-17)', function () {
    // Root cause class: generateStub() reemplaza {{moduleNameLower}} ANTES
    // de aplicar $extraReplacements — un placeholder literal DENTRO de un
    // valor de replacement nunca se resuelve y queda leakeado tal cual.
    $out = feedback10Invoke('buildFileFieldsDeleteCleanup', [['avatar'], 'admin']);

    expect($out)->not->toContain('{{moduleNameLower}}');
});

test('FEEDBACK10: buildFileFieldsValidationStore() emite rules de upload (nullable + file + image + mimes)', function () {
    $out = feedback10Invoke('buildFileFieldsValidationStore', [['avatar']]);

    expect($out)->toContain("'avatar' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],");
});

test('FEEDBACK10: buildFileFieldsValidationUpdate() emite rules de upload con `sometimes` prefix', function () {
    $out = feedback10Invoke('buildFileFieldsValidationUpdate', [['avatar']]);

    expect($out)->toContain("'avatar' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],");
});

test('R-PKG-052 T9 v2: buildFileFieldsUploadPipeline() / buildFileFieldsDeleteOldPipeline() fueron ELIMINADOS del scaffolder (pipeline de upload vive en FileStoragePlugin, no en el Service)', function () {
    // Mario 2026-07-11 explícito: "el modulo deberia usar los create y update
    // que tiene el crudsmart, y sus hoiks, asi tambien se dispara el managed
    // pluguins y llama a los pluguins que se hayan configurado coo ek de
    // subir los files, ese pluguins se encarga de proesar las imagenes
    // adecuadamente, no e snecesario que se haga en el service ni en el
    // controller, por algo esta el plugins".
    //
    // Defense-in-depth: los helpers NO existen en el scaffolder. Si alguien
    // los revive, este test FALLA y avisa que la lógica se está duplicando.
    $source = commandSource();

    expect($source)->not->toContain('function buildFileFieldsUploadPipeline(');
    expect($source)->not->toContain('function buildFileFieldsDeleteOldPipeline(');
});

test('FEEDBACK10: buildPluginsConfigLiteral() pine IDENTITY map en fields (no sufijo _path)', function () {
    $out = feedback10Invoke('buildPluginsConfigLiteral', [['avatar']]);

    // El map fields es `['avatar' => 'avatar']` (identity), no
    // `['avatar' => 'avatar_path']` (rename legacy).
    expect($out)->toContain("'avatar' => 'avatar'");
    expect($out)->not->toContain("'avatar_path'");
    expect($out)->not->toContain("'photo' => 'photo_path'");
});

test('FEEDBACK10: crudReplacements pine los 3 file fields placeholders (R-PKG-051: 2 del modelo se pinean en $extraReplacements; R-PKG-052 T9 v2: 2 de upload/delete-old se ELIMINARON del scaffolder)', function () {
    $source = commandSource();

    // R-PKG-051 hotfix: 2 de los 7 placeholders nuevos (`{{fileFieldsFillableEntries}}`
    // y `{{fileFieldsAccessors}}`) se pinean en `$extraReplacements` (fase base)
    // porque el stub del modelo los referencia, no en `$crudReplacements` (fase
    // --with-crud). 3 se quedan en `$crudReplacements` (resource, requests).
    //
    // R-PKG-052 T9 v2 (Mario 2026-07-11): `{{fileFieldsUploadPipeline}}` y
    // `{{fileFieldsDeleteOldPipeline}}` se ELIMINARON del scaffolder. El
    // FileStoragePlugin se invoca automáticamente desde CRUDSmart vía
    // PluginManager::fireBeforeSave() / fireAfterSave().
    //
    // Pre-R-PKG-051: los 7 vivían en `$crudReplacements` → modelo con
    // placeholders literales → `ParseError: syntax error, unexpected token "{"`
    // al primer `php artisan migrate` con scope scaffoldeado.
    foreach ([
        '{{fileFieldsResourceEntry}}',
        '{{fileFieldsValidationStore}}',
        '{{fileFieldsValidationUpdate}}',
    ] as $placeholder) {
        expect($source)->toContain($placeholder);
    }

    // R-PKG-052 T9 v2: defense-in-depth — los placeholders de pipeline NO
    // están pineados en ningún lado del scaffolder (helpers eliminados).
    foreach ([
        '{{fileFieldsUploadPipeline}}',
        '{{fileFieldsDeleteOldPipeline}}',
    ] as $placeholder) {
        expect($source)->not->toContain("'{$placeholder}'");
    }
});

test('R-PKG-051: $extraReplacements pine los 2 placeholders del modelo (fileFieldsFillableEntries + fileFieldsAccessors)', function () {
    $source = commandSource();

    // El bloque `$fileFieldsBaseReplacements` (mergeado en `$extraReplacements`)
    // contiene los 2 placeholders del modelo. Sin esto, `auth-user.model.stub`
    // queda con placeholders literales en líneas 78 + 115 → ParseError.
    expect($source)->toContain('$fileFieldsBaseReplacements = [');
    expect($source)->toContain("'{{fileFieldsFillableEntries}}' => \$this->buildFileFieldsFillableEntries(\$fileFieldNames)");
    expect($source)->toContain("'{{fileFieldsAccessors}}' => \$this->buildFileFieldsAccessors(\$fileFieldNames)");

    // Defense-in-depth: los 2 placeholders NO deben estar pineados directamente
    // en `$crudReplacements` (deben estar solo en `$fileFieldsBaseReplacements`).
    // Source-parsing: verificar que el array_merge final los incluye via el
    // sub-array, no como key top-level del CRUD pack.
    expect($source)->toMatch('/\$fileFieldsBaseReplacements\s*=\s*\[[\s\S]*?fileFieldsFillableEntries[\s\S]*?fileFieldsAccessors[\s\S]*?\];/');

    // Y `$crudReplacements` ya NO contiene las 2 keys (verificación por NO match).
    // El comentario del bloque debe mencionar explícitamente que se pinean en
    // `$extraReplacements` para que un dev futuro no las vuelva a mover.
    expect($source)->not->toMatch("/'\{\{fileFieldsFillableEntries\}\}'\s*=>\s*\\\$this->buildFileFieldsFillableEntries\(\s*\\\$this->detectFileFields\(\\\$profileFields\)/");
    expect($source)->not->toMatch("/'\{\{fileFieldsAccessors\}\}'\s*=>\s*\\\$this->buildFileFieldsAccessors\(\s*\\\$this->detectFileFields\(\\\$profileFields\)/");
});

test('R-PKG-051: model stub post-generateStub NO contiene placeholders fileFields* literales (regression guard del bug)', function () {
    // HALLAZGO-NEW-03: source-parsing pinea INTENCIÓN (estructura OK), no
    // EFECTIVIDAD (runtime funciona). Este test emula el str_replace de
    // `generateStub()` con `$extraReplacements` que incluye los 2 placeholders
    // del modelo (post-R-PKG-051), y pinea que el output NO contiene
    // placeholders literales del bug pre-R-PKG-051.
    //
    // NOTA: pinear TODOS los placeholders del stub para hacer `php -l` es
    // brittle (cualquier stub nuevo rompe el test). Este test se limita a
    // pinear los 2 que nos importan + los estructurales mínimos para que
    // `str_replace` no quede con placeholders adyacentes raros. El PHP
    // syntax check se hace en un e2e separado (RETO pilot, no en CI unitaria).
    $stubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user.model.stub';
    expect(file_exists($stubPath))->toBeTrue();

    $stub = (string) file_get_contents($stubPath);

    // Emular los 4 str_replace fijos de `generateStub()` + los 2 nuevos
    // placeholders del modelo pineados en `$extraReplacements` (R-PKG-051).
    $stub = str_replace('{{ModuleName}}', 'Admin', $stub);
    $stub = str_replace('{{moduleNameLower}}', 'admin', $stub);
    $stub = str_replace('{{moduleNamePluralLower}}', 'admins', $stub);
    $stub = str_replace('{{loginField}}', 'ci', $stub);
    $stub = str_replace('{{fileFieldsFillableEntries}}', "        'avatar',\n", $stub);
    $stub = str_replace('{{fileFieldsAccessors}}', <<<'PHP'

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar ? \Illuminate\Support\Facades\Storage::url($this->avatar) : null;
    }

PHP, $stub);

    // Regression guard R-PKG-051: el output NO contiene los 2 placeholders
    // literales del bug. Antes del fix, ambos quedaban en el modelo generado
    // → ParseError al `php artisan migrate`.
    expect($stub)->not->toContain('{{fileFieldsFillableEntries}}');
    expect($stub)->not->toContain('{{fileFieldsAccessors}}');

    // Y SÍ contiene el contenido pineado (defense in depth).
    expect($stub)->toContain("'avatar',");
    expect($stub)->toContain('getAvatarUrlAttribute');
});

test('FEEDBACK10: stubs pinean placeholders `{{fileFields*}}` (no photo/photo_path hardcoded)', function () {
    $stubsBase = dirname(__DIR__, 3).'/src/Stubs/';

    // Map: stub relativo → placeholders esperados. Los stubs `--with-crud`
    // viven en `auth-user/` subfolder, el model stub vive un nivel arriba
    // (heredado del auth-user base, no del CRUD pack).
    $expectedPlaceholders = [
        'auth-user.model.stub' => ['{{fileFieldsFillableEntries}}', '{{fileFieldsAccessors}}'],
        'auth-user/admin-resource.stub' => ['{{fileFieldsResourceEntry}}'],
        // R-PKG-052 T9 v2: admin-service.stub ya NO pinea file fields
        // pipeline — el FileStoragePlugin se invoca desde CRUDSmart.
        'auth-user/admin-service.stub' => [],
        'auth-user/store-admin-request.stub' => ['{{fileFieldsValidationStore}}'],
        'auth-user/update-admin-request.stub' => ['{{fileFieldsValidationUpdate}}'],
        // F10-B10: delete() limpia el/los file field(s) reales, no photo_path hardcoded.
        'auth-user/admin-repository.stub' => ['{{fileFieldsDeleteCleanup}}'],
    ];

    foreach ($expectedPlaceholders as $stubRelPath => $placeholders) {
        $stubPath = $stubsBase.$stubRelPath;
        expect(file_exists($stubPath))->toBeTrue("Stub $stubRelPath must exist at $stubPath");
        $stub = (string) file_get_contents($stubPath);

        foreach ($placeholders as $placeholder) {
            expect($stub)->toContain($placeholder);
        }

        // Verificación cruzada por stub individual: NO pine CÓDIGO pineado
        // con `photo_path` (string con comillas, variable, o method).
        // Docblocks que mencionan el refactor (e.g. admin-service.stub
        // línea 24) son válidos — pinean el contexto histórico, no el código.
        expect($stub)->not->toMatch("/'photo_path'/");          // string 'photo_path'
        expect($stub)->not->toMatch('/\$photo_path/');          // variable $photo_path
        expect($stub)->not->toMatch('/function\s+getPhotoUrlAttribute/');
        expect($stub)->not->toMatch("/'photo'\s*=>\s*\['nullable',\s*'file'/");
    }
});
