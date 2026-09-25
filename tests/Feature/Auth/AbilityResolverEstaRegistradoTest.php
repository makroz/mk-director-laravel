<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Services\AbilityResolver;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * HALLAZGO 13: NADIE REGISTRA EL `AbilityResolver`, ASÍ QUE LA CACHÉ DE
 * PERMISOS NO CORRE NUNCA.
 *
 * `HasAbilities::abilityResolver()` empieza con `if (! $app->bound(...)) return
 * null;` — un guard puesto para el unit test sin container. Sin registro, ese
 * `null` es la respuesta de SIEMPRE, también en producción: `canMk()` cae
 * SIEMPRE a `canMkLegacy()`, que es el camino con N+1 que el resolver vino a
 * reemplazar (auditoría R4-001), y `invalidateAbilityCache()` es un no-op
 * porque no hay nada cacheado.
 *
 * ── 🔴 POR QUÉ ESTE TEST MIDE EL CONTAINER Y NO LA CLASE ─────────────────
 *
 * `AbilityResolverTest` ya existe y pasa en verde con el bug vivo: construye el
 * resolver a mano y le mide la caché. La caché funciona perfecto; lo que no
 * existe es quien la conecte. Es la misma trampa del `TenantResolver`
 * (hallazgo 0) y de las Policies (hallazgo 31): se blindó la pieza y nadie
 * comprobó que el camino llegara hasta ahí.
 *
 * Lo único que discrimina es la app booteada con los providers del paquete.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

final class ResolverAdmin extends AuthUser
{
    protected $table = 'resolver_admins';

    protected $guarded = [];

    public function getAuthScope(): string
    {
        return 'admin';
    }
}

afterEach(function () {
    $this->tearDownHttpApp();
});

function armarMundoDelResolver(object $test): ResolverAdmin
{
    $test->bootHttpApp(ResolverAdmin::class, [
        'tenant' => ['enabled' => false],
    ]);

    Schema::create('resolver_admins', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email');
        $t->string('password');
        $t->string('auth_scope')->default('admin');
        $t->timestamps();
    });

    foreach (['roles', 'abilities'] as $name) {
        Schema::create($name, function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });
    }

    foreach (['role_user' => 'role_id', 'ability_user' => 'ability_id'] as $pivot => $fk) {
        Schema::create($pivot, function ($t) use ($fk) {
            $t->unsignedInteger($fk);
            $t->uuid('user_id')->nullable();
            $t->uuid('resolver_admin_id')->nullable();
            $t->string('user_type')->nullable();
            $t->timestamps();
        });
    }

    Schema::create('ability_role', function ($t) {
        $t->unsignedInteger('ability_id');
        $t->unsignedInteger('role_id');
    });

    return ResolverAdmin::create([
        'name' => 'Admin',
        'email' => 'admin@resolver.local',
        'password' => 'irrelevante',
        'auth_scope' => 'admin',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────

test('🔴 EL BUG: el AbilityResolver está bindeado en una app booteada', function () {
    armarMundoDelResolver($this);

    expect(app()->bound(AbilityResolver::class))->toBeTrue();
});

/**
 * La contraprueba de que el binding SIRVE, no sólo que existe: se le da una
 * ability al usuario, se la pregunta, y después se le BORRA la fila del pivot
 * por debajo. Si el resolver está conectado, la segunda respuesta sale de la
 * caché y sigue siendo `true`. Con el bug vivo cada llamada consulta la base y
 * la segunda da `false`.
 *
 * Es al revés de lo que uno escribiría —afirmar un permiso que ya no está en la
 * base parece un bug— y es justo eso lo que prueba que la caché corrió.
 */
test('canMk() sirve la segunda respuesta de la caché, sin volver a la base', function () {
    $admin = armarMundoDelResolver($this);

    $admin->giveAbilityTo('widgets.view');

    expect($admin->canMk('widgets.view'))->toBeTrue();

    DB::table('ability_user')->delete();

    expect($admin->canMk('widgets.view'))->toBeTrue();
});

/**
 * Y el gemelo obligatorio: si la caché no se invalidara, el paquete cambiaría
 * un permiso y el usuario seguiría con el viejo hasta el TTL. Los mutadores
 * llaman a `invalidateAbilityCache()`; acá se mide que esa llamada haga algo.
 */
test('un mutador invalida la caché: el permiso revocado deja de valer en el acto', function () {
    $admin = armarMundoDelResolver($this);

    $admin->giveAbilityTo('widgets.view');
    expect($admin->canMk('widgets.view'))->toBeTrue();

    $admin->revokeAbilityTo('widgets.view');
    expect($admin->canMk('widgets.view'))->toBeFalse();
});
