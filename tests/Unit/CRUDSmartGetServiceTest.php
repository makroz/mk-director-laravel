<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Tests\MkLaravelTestCase;
use Mk\Director\Traits\CRUDSmart;

/**
 * FEEDBACK10 F10-B03 — `CRUDSmart::getService()` nunca resolvía el Service
 * scaffoldeado.
 *
 * **Root cause**: `getService()` gateaba en `app()->bound($serviceClass)`.
 * El scaffolder pinea `'service' => {Scope}Service::class` en `$mkConfig`,
 * pero NUNCA bindea esa clase en el ServiceProvider — es una clase concreta
 * auto-resolvible (constructor con dependencias resolvibles), no necesita
 * bind explícito para que Laravel la instancie. `app()->bound()` es SIEMPRE
 * false para este caso → `getService()` retornaba `null` SIEMPRE → todos los
 * hooks (`beforeSearch`, `beforeShow`, `beforeCreate`, `setExtraData`, etc.)
 * quedaban muertos out-of-the-box, sin ningún error visible.
 *
 * **Fix**: resolver también cuando `class_exists($serviceClass)` es true,
 * vía `app()->make()` (Laravel ya sabe instanciar concrete classes con
 * dependencias resolvibles sin bind explícito).
 *
 * Este test prueba runtime real (no solo source-parsing): un controller de
 * prueba con `'service' => Foo::class` (NO bindeado en el container) debe
 * resolver el Service y el hook debe efectivamente dispararse.
 */
uses(MkLaravelTestCase::class);

class F10B03FakeService implements MkModuleServiceInterface
{
    public bool $beforeCreateWasCalled = false;

    public function beforeCreate(Request $request, array $input): array
    {
        $this->beforeCreateWasCalled = true;
        $input['touched_by_service'] = true;

        return $input;
    }

    public function afterCreate(Request $request, Model $model, array $input): mixed
    {
        return null;
    }

    public function beforeUpdate(Request $request, string|int $id, array $input): array
    {
        return $input;
    }

    public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed
    {
        return null;
    }

    public function beforeDelete(Request $request, Model $model, string|int $id): bool
    {
        return true;
    }

    public function afterDelete(Request $request, Model $model, string|int $id): mixed
    {
        return null;
    }

    public function beforeList(Request $request, $query)
    {
        return $query;
    }

    public function afterList(Request $request, $data, int $total)
    {
        return $data;
    }

    public function setExtraData(Request $request, $data): array
    {
        return [];
    }

    public function beforeSearch(Request $request, $query)
    {
        return $query;
    }

    public function beforeShow(Request $request, Model $model): Model
    {
        return $model;
    }
}

class F10B03FakeController
{
    use CRUDSmart;

    public function __construct(array $mkConfig)
    {
        $this->mkConfig = $mkConfig;
    }

    public function exposeGetService(): ?MkModuleServiceInterface
    {
        return $this->getService();
    }
}

test('F10-B03: getService() resuelve una clase concreta pineada en $mkConfig[service] SIN bind explícito', function () {
    // Contract check: la clase NO está bindeada en el container (simula el
    // scaffolder, que nunca genera un ->bind() en el ServiceProvider).
    expect(app()->bound(F10B03FakeService::class))->toBeFalse();

    $controller = new F10B03FakeController(['service' => F10B03FakeService::class]);

    $service = $controller->exposeGetService();

    expect($service)->toBeInstanceOf(MkModuleServiceInterface::class);
    expect($service)->toBeInstanceOf(F10B03FakeService::class);
});

test('F10-B03: getService() retorna null si no hay "service" configurado (BC)', function () {
    $controller = new F10B03FakeController([]);

    expect($controller->exposeGetService())->toBeNull();
});

test('F10-B03: el hook resuelto por getService() efectivamente se dispara (beforeCreate)', function () {
    $controller = new F10B03FakeController(['service' => F10B03FakeService::class]);

    $service = $controller->exposeGetService();
    expect($service)->not->toBeNull();

    $request = Request::create('/fake', 'POST');
    $result = $service->beforeCreate($request, ['name' => 'Ada']);

    expect($service->beforeCreateWasCalled)->toBeTrue();
    expect($result)->toHaveKey('touched_by_service');
});

/*
|--------------------------------------------------------------------------
| HALLAZGO 26: un `service` declarado y NO resoluble devolvía `null`.
|
| El `return null` de abajo es correcto para `$mkConfig['service']` AUSENTE,
| que sí es opcional. Para un string que nombra una clase que no existe es
| una trampa de depuración cara: un `Foo::class` sin su `use` lo resuelve PHP
| contra el namespace del archivo actual, así que el FQCN sale mal formado sin
| ningún error. `getService()` devolvía `null`, y como cada hook está guardado
| con `if ($service && method_exists(...))`, TODOS los hooks del módulo dejaban
| de correr sin una sola línea de log.
|
| 🔴 Y el síntoma es indistinguible de "el fix no funciona": así se encontró,
| cableando el filtro por scope del hallazgo 19 — el endpoint siguió
| respondiendo 200 con los datos sin filtrar, exactamente igual que antes.
|--------------------------------------------------------------------------
*/

test('🔴 HALLAZGO 26: un `service` que nombra una clase inexistente explota, no devuelve null', function () {
    $controller = new F10B03FakeController([
        'service' => 'App\\Modules\\Admin\\Http\\Controllers\\RbacCatalogService',
    ]);

    expect(fn () => $controller->exposeGetService())
        ->toThrow(\LogicException::class);
});

test('HALLAZGO 26: el mensaje nombra la clase, que es el único dato que hace falta', function () {
    $controller = new F10B03FakeController(['service' => 'Namespace\\Mal\\Puesto\\FooService']);

    expect(fn () => $controller->exposeGetService())
        ->toThrow(\LogicException::class, 'Namespace\Mal\Puesto\FooService');
});

/**
 * El control: sin esto el rojo de arriba podría salir de cualquier string y el
 * `service` legítimo quedaría roto igual.
 */
test('CONTROL: un `service` que SÍ existe sigue resolviendo, y el ausente sigue dando null', function () {
    expect((new F10B03FakeController(['service' => F10B03FakeService::class]))->exposeGetService())
        ->toBeInstanceOf(F10B03FakeService::class);

    expect((new F10B03FakeController([]))->exposeGetService())->toBeNull();
});
