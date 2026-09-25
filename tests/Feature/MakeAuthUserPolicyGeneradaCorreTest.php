<?php

declare(strict_types=1);

use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Pivots\MkPivot;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\RunsAuthUserScaffolder;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 3: LA POLICY GENERADA LLAMABA A UN MÉTODO QUE NO EXISTE.
 *
 * El pack `auth-user` reusaba `module-rbac/policy-user.stub`, que llama a
 * `hasAbility()` —método del modelo de ESE pack, que extiende `Authenticatable`—.
 * Los modelos de `auth-user` extienden `AuthUser`, que expone `canMk()` y NO tiene
 * `hasAbility()`: toda policy generada por `--with-crud` quedaba con un
 * `BadMethodCallException` latente. No explotaba porque el gate efectivo lo hacía el
 * middleware `mk.ability:` y las policies eran código muerto — hasta el primer
 * `Gate::authorize()`.
 *
 * ── 🔴 POR QUÉ ESTE ARCHIVO EXISTE SI YA HAY ONCE TESTS DEL TEMA ────────────
 *
 * `tests/Unit/Console/PolicyStubAbilityMethodTest.php` cubre el contrato de los dos
 * packs, y bien: los once casos leen los stubs y afirman que cada pack use el método
 * que su propio modelo base expone. Pero los once leen el ARCHIVO como texto.
 *
 * Y la lección de este hallazgo —textual en el registro— es que «los tests del
 * paquete afirmaban el comportamiento incorrecto y pasaban en verde. Un test escrito
 * con la misma lente que el bug no lo ve nunca». Un grep prueba que alguien escribió
 * `canMk`, no que el método exista en el modelo que la policy va a recibir. El día
 * que `AuthUser` renombre `canMk()`, los once siguen verdes.
 *
 * Esto INSTANCIA la policy generada y le pasa el modelo generado.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class, RunsAuthUserScaffolder::class);

afterEach(function () {
    $this->tearDownHttpApp();
    MorphPivot::flushSchemaCache();
    MkPivot::clearUserTypeCache();
    $this->cleanScaffolderTempDirs();
});

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 la Policy GENERADA corre contra el modelo generado, sin BadMethodCallException', function () {
    [$exit, $output, $base] = $this->runScaffolderInTempDir(['scope' => 'Crew']);
    expect($exit)->toBe(0, $output);

    $module = "{$base}/app/Modules/Crew";
    foreach (array_merge(
        glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [],
        glob("{$module}/Database/Migrations/*.php") ?: [],
    ) as $migration) {
        (require $migration)->up();
    }

    foreach (['Enums/CrewStatus.php', 'Models/Crew.php', 'Policies/CrewPolicy.php'] as $file) {
        $fqcn = 'App\\Modules\\Crew\\'.str_replace(['/', '.php'], ['\\', ''], $file);
        if (! class_exists($fqcn, false) && ! enum_exists($fqcn, false)) {
            require "{$module}/{$file}";
        }
    }

    Ability::query()->firstOrCreate(['name' => 'crew.crews.viewAny']);

    $modelo = 'App\\Modules\\Crew\\Models\\Crew';
    $usuario = $modelo::create(['name' => 'mozo', 'email' => 'mozo@crew.test', 'password' => 'secreto123']);

    $policy = new ('App\\Modules\\Crew\\Policies\\CrewPolicy');

    // 🔴 EL ORDEN DE LAS DOS ASERCIONES IMPORTA, Y LA PRIMERA ES LA QUE MIDE.
    //
    // Sin la ability, `viewAny()` tiene que devolver `false` — y para devolver
    // `false` tuvo que EJECUTAR el chequeo. Con `hasAbility()` en el stub, acá salía
    // un `BadMethodCallException` en vez de un booleano.
    //
    // Un test que sólo pidiera el `true` de abajo pasa en verde con un `before()`
    // que devuelve `true` para todos y el método del chequeo nunca llamado.
    expect($policy->viewAny($usuario))->toBeFalse();

    $usuario->giveAbilityTo('crew.crews.viewAny');

    expect($policy->viewAny($usuario->fresh(['roles.abilities', 'directAbilities'])))->toBeTrue();
});
