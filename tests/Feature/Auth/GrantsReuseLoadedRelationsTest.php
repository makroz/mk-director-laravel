<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Reuso de relaciones ya cargadas al resolver abilities
|--------------------------------------------------------------------------
| `MkAuthenticate` hace `loadMissing(['roles.abilities', 'directAbilities'])`
| en cada request autenticado para que los chequeos de authz no vuelvan a la
| base. `collectAllAbilityNames()` ignoraba eso y consultaba de nuevo: se
| pagaban las dos cosas y el eager load no lo leía nadie.
|
| Estos tests fijan las DOS mitades del contrato, y la segunda importa tanto
| como la primera: reusar una relación cargada sólo es correcto si una
| mutación la descarta. Un test que midiera únicamente el ahorro de queries
| daría verde sobre una implementación que se olvidó de invalidar — y ese
| olvido es un permiso revocado que sigue funcionando.
*/

uses(TestCase::class, UsesDatabase::class);

/** Usuario mínimo con las dos traits, sobre tablas creadas a mano. */
class UsuarioDeGrants extends \Illuminate\Database\Eloquent\Model
{
    use HasAbilities;
    use HasRoles;

    protected $table = 'auth_users';

    protected $guarded = [];

    public $timestamps = false;

    public function getAuthScope(): ?string
    {
        return 'admin';
    }

    /**
     * Las pivots del paquete usan `user_id`, no `{modelo}_id`. Los modelos
     * que scaffoldea `mk:make:auth-user` overridean esto por la misma razón
     * (ver MakeAuthUserCommand: "role_user usa user_id → no such column:
     * role_user.admin_id"). Sin el override, el test mediría un esquema que
     * en producción no existe.
     */
    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

/** @return int Cantidad de queries que emite el callback. */
function contarQueries(callable $fn): int
{
    $conexion = Capsule::connection('testing');
    $conexion->flushQueryLog();
    $conexion->enableQueryLog();

    $fn();

    $cantidad = count($conexion->getQueryLog());
    $conexion->disableQueryLog();

    return $cantidad;
}

beforeEach(function () {
    $this->setUpDatabase();

    $schema = Capsule::schema('testing');

    $schema->create('auth_users', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name')->nullable();
    });
    $schema->create('roles', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('guard')->nullable();
        $t->timestamps();
    });
    $schema->create('abilities', function (Blueprint $t) {
        $t->increments('id');
        $t->string('name');
        $t->string('description')->nullable();
        $t->timestamps();
    });
    $schema->create('role_user', function (Blueprint $t) {
        $t->unsignedInteger('role_id');
        $t->unsignedInteger('user_id');
        $t->timestamps();
    });
    $schema->create('ability_role', function (Blueprint $t) {
        $t->unsignedInteger('ability_id');
        $t->unsignedInteger('role_id');
        $t->timestamps();
    });
    $schema->create('ability_user', function (Blueprint $t) {
        $t->unsignedInteger('ability_id');
        $t->unsignedInteger('user_id');
        $t->timestamps();
    });

    $this->usuario = UsuarioDeGrants::query()->create(['name' => 'Vecina']);
});

it('no vuelve a la base cuando las relaciones ya están cargadas', function () {
    $this->usuario->giveAbilityTo('wall.viewAny');

    // El estado en que `MkAuthenticate` deja al usuario en cada request.
    $usuario = UsuarioDeGrants::query()
        ->with(['roles.abilities', 'directAbilities'])
        ->find($this->usuario->id);

    $queries = contarQueries(fn () => $usuario->getEffectiveAbilities());

    expect($queries)->toBe(0)
        ->and($usuario->getEffectiveAbilities())->toContain('wall.viewAny');
});

it('consulta cuando las relaciones NO están cargadas', function () {
    // La contracara: el ahorro tiene que venir de reusar lo cargado, no de
    // haber dejado de mirar la base. Si esto diera 0 el test de arriba no
    // probaría nada.
    $this->usuario->giveAbilityTo('wall.viewAny');
    $usuario = UsuarioDeGrants::query()->find($this->usuario->id);

    $queries = contarQueries(fn () => $usuario->getEffectiveAbilities());

    expect($queries)->toBeGreaterThan(0)
        ->and($usuario->getEffectiveAbilities())->toContain('wall.viewAny');
});

it('ve una ability otorgada DESPUÉS de haber cargado las relaciones', function () {
    // 🔴 El riesgo que introduce reusar la copia en memoria. Sin
    // `forgetGrantRelations()` en los mutadores, la colección vieja
    // sobrevive a su propia mutación y el permiso recién dado no existe
    // hasta el final del request.
    $usuario = UsuarioDeGrants::query()
        ->with(['roles.abilities', 'directAbilities'])
        ->find($this->usuario->id);

    expect($usuario->getEffectiveAbilities())->not->toContain('wall.publish');

    $usuario->giveAbilityTo('wall.publish');

    expect($usuario->getEffectiveAbilities())->toContain('wall.publish');
});

it('deja de ver una ability revocada DESPUÉS de haber cargado las relaciones', function () {
    // La dirección que importa para seguridad: un permiso que se quita tiene
    // que desaparecer en el acto. Que aparezca tarde es molesto; que se
    // quede es un agujero.
    $this->usuario->giveAbilityTo('wall.publish');

    $usuario = UsuarioDeGrants::query()
        ->with(['roles.abilities', 'directAbilities'])
        ->find($this->usuario->id);

    expect($usuario->getEffectiveAbilities())->toContain('wall.publish');

    $usuario->revokeAbilityTo('wall.publish');

    expect($usuario->getEffectiveAbilities())->not->toContain('wall.publish');
});

it('refleja un rol asignado después de la carga, con sus abilities', function () {
    $usuario = UsuarioDeGrants::query()
        ->with(['roles.abilities', 'directAbilities'])
        ->find($this->usuario->id);

    $rol = \Mk\Director\Auth\Models\Role::query()->create(['name' => 'editor', 'guard' => 'admin']);
    $ability = \Mk\Director\Auth\Models\Ability::query()->create(['name' => 'wall.moderate']);
    Capsule::table('ability_role')->insert(['ability_id' => $ability->id, 'role_id' => $rol->id]);

    expect($usuario->getEffectiveAbilities())->not->toContain('wall.moderate');

    $usuario->assignRole('editor');

    expect($usuario->getEffectiveAbilities())->toContain('wall.moderate');
});

it('lee las abilities de los roles ya cargados sin una query extra', function () {
    $rol = \Mk\Director\Auth\Models\Role::query()->create(['name' => 'editor', 'guard' => 'admin']);
    $ability = \Mk\Director\Auth\Models\Ability::query()->create(['name' => 'wall.moderate']);
    Capsule::table('ability_role')->insert(['ability_id' => $ability->id, 'role_id' => $rol->id]);
    $this->usuario->assignRole('editor');

    $usuario = UsuarioDeGrants::query()
        ->with(['roles.abilities', 'directAbilities'])
        ->find($this->usuario->id);

    $queries = contarQueries(fn () => $usuario->getEffectiveAbilities());

    expect($queries)->toBe(0)
        ->and($usuario->getEffectiveAbilities())->toContain('wall.moderate');
});
