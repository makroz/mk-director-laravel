<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Console;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Source-parsing tests for R-PKG-053 — `api_contract.md` stub generation
 * en `mk:make:auth-user` (scaffolder).
 *
 * **Driver del change**: drift histórico entre `api_contract.md` y el runtime real
 * del paquete. RETO fase 14 (2026-06-29) pineó el contrato con `access_token` en
 * root y paginación con `"meta": {...}` (legacy v1.7.x), pero el runtime emite
 * tokens en `body.data` (R-PKG-024 v1.7.0 GA single-level) y paginación agrupada
 * bajo `__extraData.pagination` (R-PKG-032 v1.8.0 MAJOR). El drift causó que
 * Postman scripts del consumer leyeran `undefined` y generaran horas de debug.
 *
 * **Fix (R-PKG-053)**: scaffolder ahora genera automáticamente
 * `app/Modules/{Scope}/Docs/api_contract.md` con el envelope canónico pre-pineado,
 * eliminando el drift de origen.
 *
 * Patrón HALLAZGO-NEW-03: source-parsing pinea INTENCIÓN (estructura OK, placeholders
 * correctos, lógica condicional pineada). EFECTIVIDAD runtime (que el scaffolder
 * corra de verdad y escriba el archivo) se valida en e2e de RETO.
 */
uses(MkLaravelTestCase::class);

function packageRoot052(): string
{
    return dirname(__DIR__, 3);
}

function apiContractStubPath052(): string
{
    return packageRoot052().'/src/Stubs/auth-user.api-contract.md.stub';
}

function apiContractStubContent052(): string
{
    $path = apiContractStubPath052();

    expect(file_exists($path))->toBeTrue(
        "Stub auth-user.api-contract.md.stub must exist at $path (R-PKG-053)",
    );

    return (string) file_get_contents($path);
}

function commandSource052(): string
{
    return (string) file_get_contents(
        packageRoot052().'/src/Console/Commands/MakeAuthUserCommand.php',
    );
}

describe('R-PKG-053 — api_contract.md stub generation', function () {
    it('stub file exists at the canonical path', function () {
        expect(file_exists(apiContractStubPath052()))->toBeTrue();
    });

    it('stub contains the R-PKG-024 single-level envelope example', function () {
        $content = apiContractStubContent052();

        // Envelope canónico pineado en el header
        expect($content)->toContain('"success": true');
        expect($content)->toContain('"message": ""');
        expect($content)->toContain('"debugMsg": []');
    });

    it('stub contains the R-PKG-032 grouped pagination example', function () {
        $content = apiContractStubContent052();

        // Pagination agrupada bajo __extraData.pagination
        expect($content)->toContain('"__extraData"');
        expect($content)->toContain('"pagination": {');
        expect($content)->toContain('"current_page": 1');
        expect($content)->toContain('"last_page"');
        expect($content)->toContain('"per_page":');
        expect($content)->toContain('"total":');
        expect($content)->toContain('"has_more_pages"');
    });

    it('stub PROHIBE el legacy flat pagination shape', function () {
        $content = apiContractStubContent052();

        // Pinear que NO existe el shape legacy `__extraData.current_page` flat
        // (es lo que el package v1.7.x emitía y v1.8.0 prohibió).
        // Buscamos "__extraData": {"current_page" como patrón plano.
        expect($content)
            ->not->toMatch('/__extraData":\s*{\s*"current_page"/');
    });

    it('stub PROHIBE el legacy `data.data` nesting (R-PKG-024)', function () {
        $content = apiContractStubContent052();

        // El envelope single-level prohíbe `data.data` anidamiento.
        // Buscamos `"data": [` o `"data": { ... }, "data":` (anidamiento).
        // El test pin es que NO hay `"data": {` seguido de `"data":` adentro.
        expect($content)->not->toMatch('/"data":\s*\{[^}]*"data":\s*\{/s');
    });

    it('stub uses placeholders for scope name and login field', function () {
        $content = apiContractStubContent052();

        // Placeholders que el helper `generateStub()` reemplaza
        expect($content)->toContain('{{ModuleName}}');
        expect($content)->toContain('{{moduleNameLower}}');
        expect($content)->toContain('{{moduleNamePluralLower}}');
        expect($content)->toContain('{{loginField}}');
    });

    it('stub has a conditional {{includeRbac}} placeholder for sections 3+4', function () {
        $content = apiContractStubContent052();

        // El helper `buildRbacSectionContent()` pinea el contenido de
        // secciones 3 (Roles CRUD) y 4 (Abilities) en este placeholder.
        // Si el consumer corre con --no-rbac, el placeholder queda vacío.
        expect($content)->toContain('{{includeRbac}}');
    });

    it('stub documents the auth envelope (R-PKG-024 + R-PKG-032 references)', function () {
        $content = apiContractStubContent052();

        // Drift history documentado para que futuros devs entiendan el por qué
        expect($content)->toContain('R-PKG-024');
        expect($content)->toContain('R-PKG-032');
    });

    it('stub warns about tokens being in body.data, NOT in HTTP headers', function () {
        $content = apiContractStubContent052();

        // HALLAZGO-NEW-052-01: el comment en MakeAuthUserCommand.php:3130-3152
        // dice "headers access_token" lo cual es engañoso. El runtime real los
        // emite en el body envueltos en `data`. El stub pinea esta aclaración
        // explícitamente para que futuros devs/lectores no se confundan.
        expect($content)->toMatch('/tokens.*body|data.*body/si');
    });

    it('command invokes generateApiContractStub() during scaffold', function () {
        $source = commandSource052();

        // El command llama al nuevo helper en algún punto del handle()
        expect($source)->toContain('generateApiContractStub(');
    });

    it('command calls the helper BEFORE the final "Scope generated" info', function () {
        $source = commandSource052();

        // Verifica orden: generateApiContractStub() viene antes del "Scope ... generado"
        $helperPos = strpos($source, 'generateApiContractStub(');
        $infoPos = strpos($source, 'Scope {$scope} generado');

        expect($helperPos)->not->toBeFalse('generateApiContractStub() no encontrado en el command');
        expect($infoPos)->not->toBeFalse('info final no encontrado');

        // Helper debe estar ANTES del info final (orden de generación)
        expect($helperPos)->toBeLessThan(
            $infoPos,
            'generateApiContractStub() debe invocarse ANTES del info "Scope generado"',
        );
    });

    it('helper pinea el archivo en app/Modules/{Scope}/Docs/api_contract.md', function () {
        $source = commandSource052();

        // Buscamos el path target pineado en el helper. F10-B08: refactor a
        // $this->modulesPath($scope) (punto único de app_path(), testeable
        // via subclase — ver modulesPath() docblock) en vez de app_path()
        // directo. Comportamiento idéntico (modulesPath() delega a app_path()).
        expect($source)->toContain('"{$this->modulesPath($scope)}/Docs/api_contract.md"');
    });

    it('helper has BC guard: skip if file already exists (no pisar trabajo del dev)', function () {
        $source = commandSource052();

        // El helper debe chequear File::exists($targetPath) y hacer return early
        // con un mensaje informativo, para respetar customización del dev.
        expect($source)->toMatch('/if\s*\(\s*File::exists\s*\(\s*\$targetPath\s*\)\s*\)/');
    });

    it('helper passes the {{includeRbac}} replacement conditionally based on --with-auth-rbac', function () {
        $source = commandSource052();

        // La sección RBAC (Roles + Abilities) se pinea solo si $withAuthRbac = true.
        // Si --no-rbac, queda string vacío. Verificamos que el helper pinea
        // el placeholder en el array de replacements.
        expect($source)->toContain("'{{includeRbac}}' => \$rbacSection");
    });
});
