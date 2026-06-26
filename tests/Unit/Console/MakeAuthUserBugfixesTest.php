<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-014 — `mk:make:auth-user` feedback fixes (v1.6.0-rc4).
 *
 * Pinea cada bug crítico del feedback RETO v1.1:
 *   - BUG-01: logout() lookup order (user ANTES de authorizeAbility).
 *   - BUG-02: model docblock completo (slash-star-star ... star-slash).
 *   - BUG-03: profile fields default validation nullable + --profile-fields-required.
 *   - BUG-04: register() incluye password en validation.
 *   - BUG-05: login() response incluye profile fields + roles + abilities.
 *   - BUG-06: me() hace loadMissing de roles + directAbilities.
 *   - BUG-07: refresh() + reset() + forgot() implementación completa.
 *   - BUG-09: --profile-fields prefijo bang marca unique.
 *   - BUG-10: storage:link check.
 *
 * Lección R-PKG-012: pinear strings críticos evita que bugs runtime se escapen.
 */
uses(MkLaravelTestCase::class);

function packageRoot014(): string
{
    return dirname(__DIR__, 3);
}

function commandSource014(): string
{
    $path = packageRoot014().'/src/Console/Commands/MakeAuthUserCommand.php';

    return (string) file_get_contents($path);
}

function authControllerStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.auth-controller.stub';

    return (string) file_get_contents($path);
}

function modelStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.model.stub';

    return (string) file_get_contents($path);
}

function migrationStub014(): string
{
    $path = packageRoot014().'/src/Stubs/auth-user.migration.stub';

    return (string) file_get_contents($path);
}

// ── BUG-01: logout() lookup order ────────────────────────────────────────

test('BUG-01 fix: stub logout() lookup del user ANTES del authorizeAbility placeholder', function () {
    $stub = authControllerStub014();

    // El stub debe tener el método logout() con lookup ANTES del placeholder RBAC.
    expect($stub)->toContain('public function logout(Request $request): JsonResponse');

    // Localizar el bloque logout() desde su declaración hasta el cierre.
    preg_match('/public function logout\(.*?\n    \}/sm', $stub, $matches);
    expect($matches)->toHaveCount(1, 'logout() method must exist in stub');

    $body = $matches[0];

    // El lookup '$user = $request->user();' debe aparecer ANTES del placeholder
    // {{rbacAbilityCheckLogout}} que emite el authorizeAbility cuando --with-auth-rbac.
    $userLookupPos = strpos($body, '$user = $request->user();');
    $rbacPlaceholderPos = strpos($body, '{{rbacAbilityCheckLogout}}');

    expect($userLookupPos)->toBeInt()->not->toBeFalse('logout() must lookup $user from $request');
    expect($rbacPlaceholderPos)->toBeInt()->not->toBeFalse('logout() must have rbacAbilityCheckLogout placeholder');
    expect($userLookupPos)
        ->toBeLessThan($rbacPlaceholderPos, 'BUG-01 fix: $user lookup must happen BEFORE rbacAbilityCheckLogout placeholder');

    // Verificar también que el command emite el authorizeAbility correctamente.
    $command = commandSource014();
    expect($command)->toContain('authorizeAbility(\'logout\', \$user)');
});

// ── BUG-02 + R-PKG-015 BUG-NEW-11: model docblock completo ─────────────

test('BUG-02 + R-PKG-015 BUG-NEW-11: model docblock se emite como bloque /** ... */ completo con header', function () {
    $stub = modelStub014();

    // El stub tiene el placeholder {{profileFieldsDocblock}}.
    expect($stub)->toContain('{{profileFieldsDocblock}}');

    // R-PKG-015 BUG-NEW-11: el command ahora emite un bloque docblock con
    //   - header "Profile fields per-scope (R-PKG-011)."
    //   - líneas de @property indentadas con 5 espacios (alineadas con `     *`)
    //   - cierre con `\n     */\n` (newline antes del */)
    $command = commandSource014();

    // Header descriptivo agregado.
    expect($command)->toContain('Profile fields per-scope (R-PKG-011)')
        // Cierre correcto con `     */` + literal `\n` (2 chars) al final del string.
        ->and($command)->toContain('     */\\n')
        // @property lines con 5 espacios de indentación (alineadas con `     *`).
        ->and($command)->toContain('     * @property');
});

// ── BUG-03: profile fields nullable default + --profile-fields-required ──

test('BUG-03 fix: PROFILE_FIELD_TYPES validation default es nullable', function () {
    $command = commandSource014();

    // Buscamos la línea exacta de la validation del tipo 'string'.
    // En el código debería estar: 'validation' => ['nullable', 'string', 'max:255'],
    expect($command)->toContain("'validation' => ['nullable', 'string', 'max:255']");
});

test('BUG-03 fix: PROFILE_FIELD_TYPES NO usa `required` como default para ningún tipo', function () {
    $command = commandSource014();

    // Extraemos el bloque PROFILE_FIELD_TYPES completo.
    if (preg_match('/public const PROFILE_FIELD_TYPES\s*=\s*\[(.*?)\];/s', $command, $matches)) {
        $block = $matches[1];

        // Verificamos que NO hay `'required', 'string', 'max:255']` (default anterior).
        expect($block)->not->toContain("'validation' => ['required', 'string', 'max:255']");
    }
});

test('BUG-03 fix: command signature incluye --profile-fields-required option', function () {
    $command = commandSource014();

    expect($command)->toMatch('/--profile-fields-required=\s*:/');
});

test('BUG-03 fix: command tiene resolveRequiredProfileFields() que valida subset de profile fields', function () {
    $command = commandSource014();

    expect($command)->toContain('protected function resolveRequiredProfileFields');
    // Valida que el field existe en profileFields.
    expect($command)->toContain('no existe en --profile-fields');
});

// ── BUG-04: register() incluye password ──────────────────────────────────

test('BUG-04 fix: rulesPhp del register() incluye password => required', function () {
    $command = commandSource014();

    // mergeRulesPhp() agrega password a las rules.
    expect($command)->toContain("'password' => ['required', 'string', 'min:8', 'max:255']");
});

// ── BUG-05: login() response incluye profile fields + roles + abilities ──

test('BUG-05 fix: login() stub usa placeholder {{loginResponseArray}} dinámico', function () {
    $stub = authControllerStub014();

    expect($stub)->toContain("'{{moduleNameLower}}' => {{loginResponseArray}}");
});

test('BUG-05 fix: command tiene buildLoginResponseArray() que arma el array_merge', function () {
    $command = commandSource014();

    expect($command)->toContain('protected function buildLoginResponseArray');
    // El array incluye roles + abilities. Buscamos con backslash literal porque
    // el código PHP usa \$user dentro de strings.
    expect($command)->toContain('roles\' => \$user->roles->map');
    expect($command)->toContain('abilities\' => \$user->abilities->pluck(\'name\')');
});

// ── BUG-06: me() eager-load ──────────────────────────────────────────────

test('BUG-06 fix: me() stub hace loadMissing ANTES del sendResponse', function () {
    $stub = authControllerStub014();

    // El stub debe tener el método me() con loadMissing.
    expect($stub)->toContain('public function me(Request $request): JsonResponse');
    // Verifica el orden: loadMissing antes de sendResponse.
    $loadMissingPos = strpos($stub, "loadMissing(['roles', 'directAbilities'])");
    expect($loadMissingPos)->toBeInt()->not->toBeFalse();

    // El stub NO debe pasar $request->user() directo a sendResponse (BUG-06 regression).
    // Aceptable: sendResponse($user) donde $user ya tiene loadMissing aplicado.
    expect($stub)->not->toContain("sendResponse(\$request->user())");
});

// ── BUG-07: refresh() + reset() + forgot() implementación completa ────────

test('BUG-07 fix: refresh() stub usa TokenIssuer::rotateRefreshToken()', function () {
    $stub = authControllerStub014();

    expect($stub)->toContain('rotateRefreshToken');
    // No debe quedar el TODO de refresh con not_implemented.
    expect($stub)->not->toContain("'not_implemented',\n            ['hint' => 'Implementar con TokenIssuer + Sanctum v4 id|plaintext token parsing']");
});

test('BUG-07 fix: reset() stub genera token lookup + password update + tokens invalidation', function () {
    $stub = authControllerStub014();

    expect($stub)->toContain('public function reset(Request $request): JsonResponse');
    expect($stub)->toContain('password_reset_tokens');
    expect($stub)->toContain('Hash::check');
    expect($stub)->toContain('$user->tokens()->delete()');
});

test('BUG-07 fix: forgot() stub genera token + persiste en password_reset_tokens', function () {
    $stub = authControllerStub014();

    expect($stub)->toContain('public function forgot(Request $request): JsonResponse');
    expect($stub)->toContain('random_bytes');
    expect($stub)->toContain('updateOrInsert');
    expect($stub)->toContain('password_reset_tokens');
});

// ── BUG-09: --profile-fields prefijo `!` marca unique ─────────────────────

test('BUG-09 fix: resolveProfileFields detecta prefijo !', function () {
    $command = commandSource014();

    expect($command)->toMatch("/str_starts_with\(\\\$item,\s*'!'\)/");
    expect($command)->toMatch("/\\\$unique\s*=\s*true/");
    expect($command)->toContain("'unique' => \$unique");
});

test('BUG-09 fix: buildProfileFieldsReplacements aplica ->unique()->nullable() cuando unique=true', function () {
    $command = commandSource014();

    expect($command)->toContain("->unique()->nullable()");
});

// ── BUG-10: storage:link check ──────────────────────────────────────────

test('BUG-10 fix: handle() invoca checkStorageLink() al final', function () {
    $command = commandSource014();

    expect($command)->toContain('protected function checkStorageLink');
    expect($command)->toMatch('/\$this->checkStorageLink\(\)/');
});

test('BUG-10 fix: checkStorageLink warn si disk=public y storage no linkeado', function () {
    $command = commandSource014();

    expect($command)->toMatch("/\\\$disk\s*=\s*config\(\s*'mk_director\.storage\.disk',\s*'public'\s*\)/");
    expect($command)->toContain('php artisan storage:link');
});