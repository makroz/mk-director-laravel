<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Scaffolders;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * FEEDBACK7 — Regression guards for the FEEDBACK7 (2026-07-09) batch.
 *
 * Pinea INTENCIÓN de los 4 fixes que apilan al scaffolder `mk:make:auth-user`:
 *   - F7-B01: --with-crud/--with-auth-rbac auto-invoca --setup-sanctum (sin
 *     requerir el flag explícito). Antes el primer /login reventaba con 500
 *     silencioso porque `personal_access_tokens` no existía.
 *   - F7-B02: `me()` y `login()` pinean `abilities` flat en el response para
 *     parity de contrato con `useMkAuth().hasAbility()`. Sin esto, scopes
 *     sin `--with-crud` (Member en RETO feedback 7) no exponían `abilities`.
 *   - F7-B03: warning loud + sugerencia `migrate:fresh` cuando la tabla del
 *     scope ya existe en la DB (corrida previa). Defense-in-depth no-fatal.
 *   - F7-W03: `--profile-fields` se pinean en `{Scope}Resource::toArray()`.
 *     Antes el Resource scaffoldeado NO los incluía → write-only.
 *
 * Pattern pineado per HALLAZGO-NEW-03: source-parsing tests pinean INTENCIÓN
 * (estructura OK). Runtime EFECTIVIDAD (¿el scaffolder genera un Resource que
 * realmente devuelve los fields en runtime?) se valida e2e en sandbox-laravel.
 *
 * @see /Users/marioguzman/Desktop/Makromania/.makromania/projects/reto/feedbacks/FEEDBACK7.md
 */
uses(MkLaravelTestCase::class);

$commandPath = dirname(__DIR__, 3).'/src/Console/Commands/MakeAuthUserCommand.php';
$authControllerStubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user.auth-controller.stub';
$adminResourceStubPath = dirname(__DIR__, 3).'/src/Stubs/auth-user/admin-resource.stub';

test('F7-B01 — MakeAuthUserCommand auto-invokes --setup-sanctum for --with-crud/--with-auth-rbac', function () use ($commandPath) {
    $command = (string) file_get_contents($commandPath);
    expect($command)->toBeString();

    // The fix: `$setupSanctum = $setupSanctum || $emitsTokens;` with
    // `$emitsTokens = $withAuthRbac || $withCrud`. Without this, the dev
    // had to pass `--setup-sanctum` explicitly (o correr el fix manual)
    // and the first /login crashed with 500 (personal_access_tokens not found).
    expect($command)
        ->toContain('$emitsTokens = $withAuthRbac || $withCrud;')
        ->toContain('$setupSanctum = $setupSanctum || $emitsTokens;');

    // Defense-in-depth: el comando sigue pineando el output loud del warning
    // cuando se auto-activa (no-fatal, sugiere `php artisan migrate`).
    expect($command)
        ->toContain('F7-B01: Sanctum PAT table setup automático')
        ->toContain('--setup-sanctum implícito');
})->group('feedback7', 'scaffolder');

test('F7-B01 — BC: --setup-sanctum flag still works as opt-in for plain scopes (no --with-crud/--with-auth-rbac)', function () use ($commandPath) {
    $command = (string) file_get_contents($commandPath);
    expect($command)->toBeString();

    // El flag `--setup-sanctum` sigue pineado en $signature (BC con v2.0.0).
    expect($command)
        ->toContain('{--setup-sanctum :');
})->group('feedback7', 'scaffolder');

test('R-PKG-047 D1: BaseAuthController::me() pinea `abilities` flat en el response (SSoT F7-B02)', function () {
    $basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';
    $base = (string) file_get_contents($basePath);
    expect($base)->toBeString();

    // F7-B02 + R-PKG-047 D1: el método `me()` y la lógica de abilities
    // viven en BaseAuthController (SSoT). Pinean que el SSoT pinea
    // `abilities` flat via `getEffectiveAbilities()`.
    if (! preg_match('/public function me\([^)]*\)[^{]*\{(.*?)\n    \}/s', $base, $matches)) {
        test()->fail('Could not locate me() method in BaseAuthController.');
    }
    $meBody = $matches[1];

    expect($meBody)
        ->toContain("\$payload['abilities']")
        ->toContain('getEffectiveAbilities');
})->group('feedback7', 'scaffolder');

test('R-PKG-047 D1: BaseAuthController::login() pinea `abilities` en el response (SSoT F7-B02)', function () {
    $basePath = dirname(__DIR__, 3).'/src/Auth/Controllers/BaseAuthController.php';
    $base = (string) file_get_contents($basePath);
    expect($base)->toBeString();

    // F7-B02 + D1: el método `login()` vive en BaseAuthController y pinea
    // `abilities` en el response. Pinean que el SSoT incluye la ability
    // mapping.
    if (! preg_match('/public function login\([^)]*\)[^{]*\{(.*?)\n    \}/s', $base, $matches)) {
        test()->fail('Could not locate login() method in BaseAuthController.');
    }
    $loginBody = $matches[1];

    expect($loginBody)
        ->toContain('getEffectiveAbilities');
})->group('feedback7', 'scaffolder');

test('R-PKG-047 D1: stub AuthController es thin wrapper — NO contiene me() ni login() inline', function () use ($authControllerStubPath) {
    $stub = (string) file_get_contents($authControllerStubPath);
    expect($stub)->toBeString();

    // El thin wrapper NO override `me()` ni `login()` — los hereda del SSoT.
    expect($stub)->not->toMatch('/public function me\(/');
    expect($stub)->not->toMatch('/public function login\(/');
    expect($stub)->not->toContain('getEffectiveAbilities');
})->group('feedback7', 'scaffolder');

test('F7-B03 — MakeAuthUserCommand checkea tabla preexistente del scope antes de scaffoldear', function () use ($commandPath) {
    $command = (string) file_get_contents($commandPath);
    expect($command)->toBeString();

    // The fix: nuevo método `checkScopeTableExists()` que se invoca desde
    // handle() antes de generar la migration. Es no-fatal pero warn loud.
    expect($command)
        ->toContain('checkScopeTableExists')
        ->toContain('protected function checkScopeTableExists')
        ->toContain('F7-B03: la tabla `{$tableName}` YA EXISTE en la DB');
})->group('feedback7', 'scaffolder');

test('F7-W03 — {Scope}Resource stub pinea placeholder {{profileFieldsResourceEntry}}', function () use ($adminResourceStubPath) {
    $stub = (string) file_get_contents($adminResourceStubPath);
    expect($stub)->toBeString();

    // The fix: el stub tiene `{{profileFieldsResourceEntry}}` entre
    // `{{statusResourceEntry}}` y `'abilities' => ...`. Sin esto, el
    // Resource scaffoldeado NO incluye los --profile-fields → write-only.
    expect($stub)
        ->toContain('{{statusResourceEntry}}')
        ->toContain('{{profileFieldsResourceEntry}}');

    // Sanity: el placeholder está en el bloque de toArray(), no perdido en
    // otro lugar. Buscamos el contexto.
    expect($stub)
        ->toMatch('/return\s*\[[\s\S]*?\{\{profileFieldsResourceEntry\}\}[\s\S]*?\];/');
})->group('feedback7', 'scaffolder');

test('F7-W03 — MakeAuthUserCommand popula {{profileFieldsResourceEntry}} con buildProfileFieldsToArray()', function () use ($commandPath) {
    $command = (string) file_get_contents($commandPath);
    expect($command)->toBeString();

    // The fix: el array `$crudReplacements` ahora incluye
    // `'{{profileFieldsResourceEntry}}' => $this->buildProfileFieldsToArray($profileFields, $loginField)`.
    // El método `buildProfileFieldsToArray()` ya existía (lo usa AdminData DTO);
    // solo lo reusamos acá. FEEDBACK10 (R-PKG-050) agregó `$loginField` como
    // segundo argumento para pinear correctamente el dedup contra `'{{loginField}}'`
    // en el resource stub (post-F10-B02 fix).
    expect($command)
        ->toContain("'{{profileFieldsResourceEntry}}' => \$this->buildProfileFieldsToArray(\$profileFields, \$loginField)");
})->group('feedback7', 'scaffolder');
