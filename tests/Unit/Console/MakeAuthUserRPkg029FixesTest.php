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
    $stub = readStubRPkg029('src/Stubs/auth-user.auth-controller.stub');

    test('auth-controller stub: login() retorna $user (no array_merge)', function () use ($stub): void {
        // El fix es que login() retorna el modelo completo, dejando que
        // autoTransform() en BaseController::sendResponse() aplique el apiResource
        // del modelo. Mismo patrón que me().

        expect($stub)
            ->toContain("'{{moduleNameLower}}' => \$user,");
    });

    test('auth-controller stub: login() NO contiene array_merge con roles/abilities top-level', function () use ($stub): void {
        // El bug original era un array_merge ad-hoc con abilities top-level combinadas.
        // Después del fix, ese patrón no debe existir más en el stub.

        expect($stub)->not->toMatch("/\\\$user->only\\(\\['id', 'name'/");
        expect($stub)->not->toMatch("/'abilities'\s*=>\s*\\\$user->abilities->pluck/");
    });

    $command = readCommandRPkg029();

    test('buildLoginResponseArray retorna literal $user (no array_merge)', function () use ($command): void {
        // La función helper ahora retorna '$user' directamente, simplificando
        // el stub del AuthController.

        // El método sigue existiendo por signature BC (lo llama $this->buildLoginResponseArray).
        expect($command)->toContain('protected function buildLoginResponseArray(');

        // Pero su cuerpo ahora retorna '$user' como string literal.
        // Buscamos el return statement característico.
        expect($command)->toMatch("/function buildLoginResponseArray[^{]+\\{\\s*\\/\\/ PKG-NEW-15.*?return '\\\$user';\\s*\\}/s");
    });
});
