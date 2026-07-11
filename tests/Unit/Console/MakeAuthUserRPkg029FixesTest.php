<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-029 scaffolder hardening — post-RETO fase 10b feedback.
 *
 * Source: Feedback RETO fase 10b 2026-06-28 (`FEEDBACK-TO-MK-DIRECTOR.md`).
 * 3 hallazgos pineables → 3 fixes en este sprint:
 *   - PKG-NEW-12: logout() scaffoldeado referencia `$token?->id` undefined.
 *   - PKG-NEW-14: cache driver default rompe SmartController sin warning.
 *   - PKG-NEW-15: drift de shape entre login() (top-level) y me() (anidado).
 *
 * Patrón: source-parsing pinea INTENCIÓN del fix (estructura del stub o del command).
 * Para pinear EFECTIVIDAD (que el scaffolder emite código que efectivamente
 * funciona runtime), ver audit e2e en sandbox consumer — ver `apps/sandbox-laravel/`
 * y RETO fase 11+ (clean rebuild que valida los 3 fixes end-to-end).
 *
 * Spec: R-PKG-029.
 *
 * @see MakeAuthUserCommand
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg029(): string
{
    return dirname(__DIR__, 3);
}

function readStubRPkg029(string $path): string
{
    $fullPath = packageRootRPkg029().'/'.$path;
    expect(file_exists($fullPath))->toBeTrue("Stub must exist at $fullPath");

    return file_get_contents($fullPath);
}

function readCommandRPkg029(): string
{
    return readStubRPkg029('src/Console/Commands/MakeAuthUserCommand.php');
}

describe('PKG-NEW-12 — logout() scaffoldeado: $user->currentAccessToken()?->id (no $token?->id)', function (): void {
    $command = readCommandRPkg029();

    test('MakeAuthUserCommand genera logout event con $user->currentAccessToken()?->id', function () use ($command): void {
        // El evento `auth.logout` debe usar el patrón null-safe sobre `$user`,
        // NO una variable `$token` que no existe en el scope del método logout()
        // scaffoldeado (el stub usa `$user->safeLogoutCurrentToken()` que no
        // expone el token al consumer).

        expect($command)->toContain("'token_id' => \$user->currentAccessToken()?->id");
    });

    test('MakeAuthUserCommand NO genera logout event con $token?->id (variable indefinida)', function () use ($command): void {
        // El bug original era:
        //   'token_id' => $token?->id,
        // donde $token nunca estaba definido. El grep debe encontrar CERO
        // ocurrencias de este patrón roto.

        expect($command)->not->toMatch("/'token_id'\s*=>\s*\\\$token\?->id/");
    });
});

describe('PKG-NEW-14 — check cache driver support en scaffolder (warning + sugerencia)', function (): void {
    $command = readCommandRPkg029();

    test('MakeAuthUserCommand tiene método checkCacheDriver()', function () use ($command): void {
        expect($command)->toContain('protected function checkCacheDriver(): void');
    });

    test('MakeAuthUserCommand llama checkCacheDriver() en el flujo principal', function () use ($command): void {
        // El check debe correr post-scaffold (después de checkSanctumInstalled).
        expect($command)->toContain('$this->checkCacheDriver();');
    });

    test('MakeAuthUserCommand tiene helper resolveCacheStore() que lee CACHE_STORE', function () use ($command): void {
        expect($command)->toContain('protected function resolveCacheStore(): ?string');
        expect($command)->toContain('CACHE_STORE');
    });

    test('MakeAuthUserCommand tiene helper cacheStoreSupportsTags() con lista correcta', function () use ($command): void {
        expect($command)->toContain('protected function cacheStoreSupportsTags(string $store): bool');

        // Drivers que SÍ soportan tags (Laravel 11+ docs).
        expect($command)->toContain("'redis'");
        expect($command)->toContain("'memcached'");
        expect($command)->toContain("'dynamodb'");
    });

    test('PKG-NEW-14 warning menciona RuntimeException específico del problema', function () use ($command): void {
        // El warning debe mencionar el error específico que el consumer va a ver.
        expect($command)->toContain('does not support tags');
    });

    test('PKG-NEW-14 warning sugiere CACHE_STORE=array para dev/local', function () use ($command): void {
        // Fix por ambiente (dev vs prod) — el warning debe mencionar array como opción dev.
        expect($command)->toContain('CACHE_STORE=array');
        expect($command)->toContain('MK_CACHE_ALLOW_FULL_CLEAR');
    });
});

describe('PKG-NEW-15 — login() y me() retornan el mismo shape canónico ($user)', function (): void {
    // **R-PKG-047 D1**: el shape canónico `$user` se pinea en
    // `BaseAuthController::login()` y `BaseAuthController::me()`. El thin
    // wrapper scaffoldeado NO tiene `login()` ni `me()` inline — los hereda
    // del SSoT. El helper interno `buildLoginResponseArray()` se mantiene
    // por compat (pinea el array de profile fields que `BaseAuthController`
    // acepta como `customizeMePayload()` override).

    test('R-PKG-047 D1: auth-controller stub NO contiene login() ni $user assignment (viven en BaseAuthController)', function () {
        $stub = readStubRPkg029('src/Stubs/auth-user.auth-controller.stub');

        // El thin wrapper NO override `login()` — lo hereda de BaseAuthController.
        expect($stub)->not->toMatch('/public function login\(/');
        // Y por lo tanto no contiene el return literal `$user` (eso vive en BaseAuthController).
        expect($stub)->not->toMatch("/'\{\{moduleNameLower\}\}'\s*=>\s*\\\$user,/");
    });

    test('R-PKG-047 D1: auth-controller stub NO contiene array_merge ad-hoc (legacy)', function () {
        $stub = readStubRPkg029('src/Stubs/auth-user.auth-controller.stub');

        // El thin wrapper NO contiene el patrón legacy de array_merge ad-hoc
        // con abilities top-level (eso vivía en el stub VIEJO pre-D1).
        expect($stub)->not->toMatch("/\\\$user->only\\(\\['id', 'name'/");
        expect($stub)->not->toMatch("/'abilities'\s*=>\s*\\\$user->abilities->pluck/");
    });

    test('R-PKG-047 D1: BaseAuthController::login() y me() pinean shape canónico $user (SSoT)', function () {
        $base = readStubRPkg029('src/Auth/Controllers/BaseAuthController.php');

        // El SSoT canónico de la shape vive en BaseAuthController.
        // `login()` y `me()` son los métodos que pinean `autoTransform()`.
        expect($base)->toContain('public function login(');
        expect($base)->toContain('public function me(');
    });

    test('buildLoginResponseArray() helper existe (BC compat con stubs que pinean {{loginResponseArray}})', function () {
        $command = readCommandRPkg029();

        // El helper se mantiene por BC (lo llama `$this->buildLoginResponseArray()`
        // en el command). Pinea el array de profile fields que se inyecta via
        // `customizeMePayload()` override en BaseAuthController.
        expect($command)->toContain('protected function buildLoginResponseArray(');
    });
});
