<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-046 F9-B08 — `CRUDSmart::store()/update()` aplican FormRequest.
 *
 * **Bug pineado**: el scaffolder `mk:make:auth-user X --with-crud` pineaba
 * `'store_request' => StoreXRequest::class` y `'update_request' => UpdateXRequest::class`
 * en `$mkConfig`. Pre-fix, `CRUDSmart::store()/update()` hacía `$request->all()`
 * directo sin validar. La validación scaffoldeada era dead code — un POST sin
 * email producía `Integrity constraint violation: NOT NULL constraint failed`
 * (500 SQL) en vez de `ValidationException` (422 con errores estructurados).
 *
 * **Fix**: nuevo helper `resolveFormRequest(configKey, request, routeParamName, routeParamValue)`
 * en `CRUDSmart` resuelve el FormRequest vía `app()`, setContainer, setRedirector,
 * setRouteResolver (con `{resource} = $id` para `Rule::unique(...)->ignore(...)`),
 * y corre `validateResolved()`. Si falla, ValidationException → 422.
 *
 * Per HALLAZGO-NEW-03, pinea INTENCIÓN (source-parsing) + EFECTIVIDAD (runtime
 * con FormRequest real). El package no bootea full Laravel app en unit tests
 * (ver MkLaravelTestCase docblock), así que el EFECTIVIDAD se valida en el
 * consumer piloto RETO post-merge (smoke test E2E confirma 422 vs 500).
 *
 * Source-parsing patterns:
 *   - `resolveFormRequest(` aparece en store() y update()
 *   - `validateResolved()` aparece en `resolveFormRequest()`
 *   - BC: si `$store_request` no pineado, retorna `$request` sin tocar
 */
uses(MkLaravelTestCase::class);

function crudSmartFormRequestSource(): string
{
    $path = __DIR__.'/../../src/Traits/CRUDSmart.php';
    expect(file_exists($path))->toBeTrue("CRUDSmart.php must exist at $path");

    return (string) file_get_contents($path);
}

test('R-PKG-046 F9-B08 — store() llama resolveFormRequest ANTES de $request->all()', function () {
    $src = crudSmartFormRequestSource();

    // store() debe llamar resolveFormRequest.
    expect($src)->toContain(
        "public function store(Request \$request)",
    );

    // Localizar el cuerpo de store() — desde "public function store" hasta
    // "public function update" (siguiente método público).
    $storePos = strpos($src, 'public function store(Request $request)');
    expect($storePos)->not->toBeFalse();

    $updatePos = strpos($src, 'public function update(Request $request, string|int $id)', (int) $storePos);
    expect($updatePos)->not->toBeFalse();

    $storeBody = substr($src, (int) $storePos, (int) $updatePos - (int) $storePos);

    expect($storeBody)->toContain('resolveFormRequest(');
    expect($storeBody)->toContain('store_request');

    // El resolveFormRequest debe correr ANTES de $request->all().
    $resolvePos = strpos($storeBody, 'resolveFormRequest(');
    $allPos = strpos($storeBody, '$request->all()');
    expect($resolvePos)->not->toBeFalse();
    expect($allPos)->not->toBeFalse();
    expect($resolvePos)->toBeLessThan($allPos);
});

test('R-PKG-046 F9-B08 — update() llama resolveFormRequest con route param para Rule::unique', function () {
    $src = crudSmartFormRequestSource();

    expect($src)->toContain(
        "public function update(Request \$request, string|int \$id)",
    );

    $updatePos = strpos($src, 'public function update(Request $request, string|int $id)');
    expect($updatePos)->not->toBeFalse();

    $updateBody = substr($src, (int) $updatePos);
    $updateBody = substr($updateBody, 0, (int) strpos($updateBody, "\n    /**\n     * DELETE"));

    expect($updateBody)->toContain('resolveFormRequest(');
    expect($updateBody)->toContain('update_request');

    // El resolveFormRequest debe correr ANTES de $request->all().
    $resolvePos = strpos($updateBody, 'resolveFormRequest(');
    $allPos = strpos($updateBody, '$request->all()');
    expect($resolvePos)->not->toBeFalse();
    expect($allPos)->not->toBeFalse();
    expect($resolvePos)->toBeLessThan($allPos);
});

test('R-PKG-046 F9-B08 — resolveFormRequest() corre validateResolved() (FormRequest validation)', function () {
    $src = crudSmartFormRequestSource();

    expect($src)->toContain(
        'protected function resolveFormRequest(',
    );

    $helperPos = strpos($src, 'protected function resolveFormRequest(');
    expect($helperPos)->not->toBeFalse();

    $helperBody = substr($src, (int) $helperPos);

    // Helper debe llamar validateResolved() para correr rules().
    expect($helperBody)->toContain('validateResolved()');

    // Y debe retornar el FormRequest (no el $request original).
    expect($helperBody)->toContain('return $formRequest');

    // Y debe pinear setContainer + setRedirector para que validateResolved() route OK.
    expect($helperBody)->toContain('setContainer(app())');
    expect($helperBody)->toContain('setRedirector(app(');
});

test('R-PKG-046 F9-B08 — resolveFormRequest() pinea route resolver con {resource} = $id para update', function () {
    $src = crudSmartFormRequestSource();

    $helperPos = strpos($src, 'protected function resolveFormRequest(');
    $helperBody = substr($src, (int) $helperPos);

    // El helper debe pinear setRouteResolver con un Route mock para que
    // `$this->route('admin')` en FormRequest retorne el $id del update.
    expect($helperBody)->toContain('setRouteResolver(');
    expect($helperBody)->toContain('setParameter($routeParamName, $routeParamValue)');
});

test('R-PKG-046 F9-B08 — BC: si store_request no pineado, retorna $request sin tocar', function () {
    $src = crudSmartFormRequestSource();

    $helperPos = strpos($src, 'protected function resolveFormRequest(');
    $helperBody = substr($src, (int) $helperPos);

    // Early return si no hay formRequestClass.
    expect($helperBody)->toContain(
        "\$formRequestClass = \$this->mkConfig[\$configKey] ?? null",
    );
    expect($helperBody)->toContain('return $request;');
});

test('R-PKG-046 F9-B08 — getResourceRouteParam() lee de $mkConfig (scaffolder pinea resource_route_param)', function () {
    $src = crudSmartFormRequestSource();

    expect($src)->toContain(
        'protected function getResourceRouteParam(): ?string',
    );

    $helperPos = strpos($src, 'protected function getResourceRouteParam(): ?string');
    $helperBody = substr($src, (int) $helperPos);

    expect($helperBody)->toContain(
        "return \$this->mkConfig['resource_route_param'] ?? null",
    );
});

test('R-PKG-046 F9-B08 — JSDoc de resolveFormRequest() documenta el flow completo', function () {
    $src = crudSmartFormRequestSource();

    $helperPos = strpos($src, 'protected function resolveFormRequest(');
    expect($helperPos)->not->toBeFalse();

    // El JSDoc debe estar justo arriba del método.
    $docblockPos = strrpos(substr($src, 0, (int) $helperPos), '/**');
    expect($docblockPos)->not->toBeFalse();

    $docblock = substr($src, (int) $docblockPos, (int) $helperPos - (int) $docblockPos);

    // JSDoc debe mencionar la integración con scaffolder y el bug que pineamos.
    expect($docblock)->toContain('R-PKG-046 F9-B08');
    expect($docblock)->toContain('ValidationException');
    expect($docblock)->toContain('validateResolved');
    expect($docblock)->toContain('Rule::unique');
});