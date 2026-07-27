<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Feature\Auth;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Mk\Director\Auth\Concerns\AssignsBaseRole;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Tests\TestCase;

/**
 * `AssignsBaseRole`: un usuario nuevo recibe solo el rol base de su scope.
 *
 * Reemplaza el patrón que cada consumidor terminaba inventando — en RETO,
 * `Member::grantWallAccess()` colgado de un hook `created`, que le pega
 * abilities DIRECTAS a cada member. El mismo permiso repetido en `ability_user`
 * una vez por usuario, y sin forma de revocarlo salvo recorrer la tabla.
 *
 * ## Las dos propiedades que este archivo defiende
 *
 * 1. **Nunca crea el rol.** `assignRole(string)` de `HasRoles` resuelve con
 *    `firstOrCreate`, o sea que pasarle el nombre CREARÍA un rol base vacío —
 *    y lo haría el primer usuario que se registre, en producción, sin que nadie
 *    mire. Rompería de frente la invariante del discovery. Por eso el trait
 *    busca primero y sólo asigna si encontró.
 * 2. **Que no haya rol base es normal.** Un scope sin baselines declaradas no
 *    tiene rol base y sus usuarios no deberían tener línea de base. No-op.
 *
 * Contra sqlite real, con eventos de Eloquent de verdad: un hook `created` que
 * se testea sin dispatcher no prueba nada.
 */
uses(TestCase::class);

/** Esquema mínimo de RBAC + una tabla de usuarios, con eventos activos. */
function baseRoleCapsule(): Capsule
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $capsule->setEventDispatcher(new Dispatcher(new Container));
    $capsule->setAsGlobal();
    $capsule->bootEloquent();

    Container::setInstance(new Container);
    Container::getInstance()->instance('db', $capsule->getDatabaseManager());
    Container::getInstance()->instance('config', new Repository([
        'mk_director' => ['auth' => ['base_role' => 'base']],
    ]));
    Facade::setFacadeApplication(Container::getInstance());
    EloquentModel::setEventDispatcher(new Dispatcher(Container::getInstance()));

    // 🔴 SIN ESTO LA MITAD DE ESTE ARCHIVO SERÍA UNA MENTIRA.
    // Eloquent bootea cada modelo UNA sola vez por proceso, y ahí es cuando
    // `bootAssignsBaseRole()` registra el listener de `created` — contra el
    // dispatcher que existía en ese momento. Cada test monta un dispatcher
    // nuevo, así que del segundo en adelante el hook YA NO ESTÁ REGISTRADO y
    // no dispara nunca.
    //
    // Lo peligroso no es que fallen los tests positivos: es que los NEGATIVOS
    // pasan igual. "No se asignó ningún rol" es exactamente lo que produce un
    // hook muerto, así que tres tests daban verde midiendo la nada. Pasó, acá,
    // y se descubrió porque los positivos sí fallaron.
    //
    // `clearBootedModels()` obliga a re-bootear contra el dispatcher de este
    // test. Y aun así, cada test negativo cierra con un caso positivo que
    // prueba que el hook está VIVO — no alcanza con confiar en esta línea.
    EloquentModel::clearBootedModels();

    $schema = $capsule->getConnection()->getSchemaBuilder();

    $schema->create('roles', function ($t) {
        $t->id();
        $t->string('name');
        $t->string('guard')->nullable();
        $t->unsignedTinyInteger('is_fixed')->default(0);
        $t->timestamps();
    });

    $schema->create('role_user', function ($t) {
        $t->id();
        $t->unsignedBigInteger('role_id');
        $t->unsignedBigInteger('user_id');
        $t->timestamps();
    });

    $schema->create('miembros', function ($t) {
        $t->id();
        $t->string('nombre')->nullable();
        $t->string('auth_scope')->nullable();
        $t->timestamps();
    });

    return $capsule;
}

/** Modelo de usuario mínimo con los dos traits. */
class MiembroConRolBase extends EloquentModel
{
    use AssignsBaseRole;
    use HasRoles;

    protected $table = 'miembros';

    protected $guarded = [];

    public function getAuthScope(): ?string
    {
        $s = $this->getAttribute('auth_scope');

        return is_string($s) && $s !== '' ? $s : null;
    }

    /**
     * Mismo override que emite el scaffolder en todo modelo de scope
     * (R-PKG-015 BUG-NEW-06): sin él, Eloquent infiere la FK del nombre del
     * modelo (`miembro_con_rol_base_id`) y la pivot usa `user_id`.
     *
     * Va acá para que el test corra sobre la MISMA forma que un modelo real, y
     * no sobre una simplificación que no existe en ningún consumidor.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_user',
            'user_id',
            'role_id',
        );
    }
}

afterEach(function () {
    EloquentModel::unsetEventDispatcher();
    Container::setInstance(null);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

it('un usuario nuevo recibe el rol base de su scope, solo', function () {
    $capsule = baseRoleCapsule();
    $rolId = $capsule->getConnection()->table('roles')->insertGetId([
        'name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $u = MiembroConRolBase::create(['nombre' => 'Ana', 'auth_scope' => 'member']);

    expect($capsule->getConnection()->table('role_user')
        ->where('user_id', $u->id)->pluck('role_id')->all())->toBe([$rolId]);
});

it('🔴 NO crea el rol base cuando no existe: no-op, no un rol vacío', function () {
    // `assignRole(string)` usa `firstOrCreate`. Si el trait le pasara el nombre,
    // el primer registro de producción crearía un rol base vacío y rompería la
    // invariante del discovery. Por eso busca primero.
    $capsule = baseRoleCapsule();

    $u = MiembroConRolBase::create(['nombre' => 'Ana', 'auth_scope' => 'member']);

    expect($capsule->getConnection()->table('roles')->count())->toBe(0)
        ->and($capsule->getConnection()->table('role_user')->where('user_id', $u->id)->count())->toBe(0);

    // PRUEBA DE VIDA. Sin esto la aserción de arriba la cumple igual un hook
    // que nunca corrió. Ahora existe el rol: si el hook está vivo, el próximo
    // usuario lo recibe.
    $rolId = $capsule->getConnection()->table('roles')->insertGetId([
        'name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $u2 = MiembroConRolBase::create(['nombre' => 'Bea', 'auth_scope' => 'member']);

    expect($capsule->getConnection()->table('role_user')
        ->where('user_id', $u2->id)->pluck('role_id')->all())->toBe([$rolId]);
});

it('agarra el rol de SU scope, no el de otro con el mismo nombre', function () {
    $capsule = baseRoleCapsule();
    $db = $capsule->getConnection();

    $ajeno = $db->table('roles')->insertGetId([
        'name' => 'base', 'guard' => 'admin', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $propio = $db->table('roles')->insertGetId([
        'name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $u = MiembroConRolBase::create(['nombre' => 'Ana', 'auth_scope' => 'member']);

    expect($db->table('role_user')->where('user_id', $u->id)->pluck('role_id')->all())
        ->toBe([$propio])
        ->and($ajeno)->not->toBe($propio);
});

it('sin auth_scope no asigna nada', function () {
    // Un usuario sin scope no pertenece a ninguna línea de base. Adivinarle una
    // sería concederle permisos por descarte.
    $capsule = baseRoleCapsule();
    $capsule->getConnection()->table('roles')->insert([
        'name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $u = MiembroConRolBase::create(['nombre' => 'Ana']);

    expect($capsule->getConnection()->table('role_user')->where('user_id', $u->id)->count())->toBe(0);

    // PRUEBA DE VIDA: el mismo hook, con scope, sí asigna.
    $u2 = MiembroConRolBase::create(['nombre' => 'Bea', 'auth_scope' => 'member']);

    expect($capsule->getConnection()->table('role_user')->where('user_id', $u2->id)->count())->toBe(1);
});

it('respeta un nombre de rol base custom del config', function () {
    $capsule = baseRoleCapsule();
    config()->set('mk_director.auth.base_role', 'piso');

    $capsule->getConnection()->table('roles')->insert([
        ['name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'piso', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $piso = $capsule->getConnection()->table('roles')->where('name', 'piso')->value('id');

    $u = MiembroConRolBase::create(['nombre' => 'Ana', 'auth_scope' => 'member']);

    expect($capsule->getConnection()->table('role_user')
        ->where('user_id', $u->id)->pluck('role_id')->all())->toBe([$piso]);
});

it('actualizar un usuario NO le vuelve a enganchar el rol', function () {
    // El hook es `created`, no `saved`. Con `saved` cada update reasignaría —
    // idempotente por `syncWithoutDetaching`, pero una consulta al pedo en cada
    // escritura, y volvería a poner un rol que un admin acababa de sacar.
    $capsule = baseRoleCapsule();
    $capsule->getConnection()->table('roles')->insert([
        'name' => 'base', 'guard' => 'member', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $u = MiembroConRolBase::create(['nombre' => 'Ana', 'auth_scope' => 'member']);

    // PRUEBA DE VIDA: el alta SÍ lo asignó (si esto no valiera 1, el resto del
    // test estaría midiendo un hook muerto y no la diferencia created/saved).
    expect($capsule->getConnection()->table('role_user')->where('user_id', $u->id)->count())->toBe(1);

    $capsule->getConnection()->table('role_user')->where('user_id', $u->id)->delete();

    $u->nombre = 'Ana María';
    $u->save();

    expect($capsule->getConnection()->table('role_user')->where('user_id', $u->id)->count())->toBe(0);
});
