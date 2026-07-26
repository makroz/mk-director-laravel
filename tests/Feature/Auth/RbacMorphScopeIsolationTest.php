<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Mk\Director\Auth\Concerns\HasAbilities;
use Mk\Director\Auth\Concerns\HasRoles;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Aislamiento entre scopes en las pivots del RBAC
|--------------------------------------------------------------------------
| `role_user` y `ability_user` tienen columna `user_type`, y hasta ahora se
| escribía en dos vocabularios distintos según el camino —`assignRole()` ponía
| el FQCN, un `attach()` pelado ponía el alias del morph map— y no la leía
| NADIE: `belongsToMany` empareja sólo por `user_id`.
|
| Con dos scopes que comparten el espacio de ids, eso es escalación de
| privilegios: el member #1 se llevaba los roles y las abilities del admin #1.
| En RETO no se disparó porque los ids son UUID, que es suerte del esquema y no
| una defensa — el default de Laravel, y lo que scaffoldea este mismo paquete,
| son ids autoincrementales.
|
| Estos tests usan enteros A PROPÓSITO. Con UUIDs pasarían aun con el bug
| puesto, que es exactamente por lo que nadie lo vio.
*/

uses(TestCase::class, UsesDatabase::class);

class ScopeAdmin extends Model
{
    use HasAbilities;
    use HasRoles;

    protected $table = 'scope_admins';

    protected $guarded = [];

    public $timestamps = false;

    public function getAuthScope(): ?string
    {
        return 'admin';
    }

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

class ScopeMember extends Model
{
    use HasAbilities;
    use HasRoles;

    protected $table = 'scope_members';

    protected $guarded = [];

    public $timestamps = false;

    public function getAuthScope(): ?string
    {
        return 'member';
    }

    public function getForeignKey(): string
    {
        return 'user_id';
    }
}

beforeEach(function () {
    $this->setUpDatabase();
    // La detección de columnas se cachea por tabla y estos tests crean el
    // esquema en cada caso: sin el flush, el segundo test lee la respuesta
    // del primero sobre tablas que ya no son las mismas.
    MorphPivot::flushSchemaCache();

    // 🔴 EL MORPH MAP ES ESTADO GLOBAL Y HAY QUE DEVOLVERLO. `enforceMorphMap`
    // además prende el flag de "todo modelo polimórfico DEBE estar en el
    // mapa", así que dejarlo puesto hacía explotar 59 tests de OTROS archivos
    // con `ClassMorphViolationException`. Se guarda el mapa previo y se
    // restaura en el `afterEach`; acá alcanza con `morphMap()`, sin exigir.
    $this->morphMapPrevio = Relation::morphMap() ?? [];
    Relation::morphMap(['admin' => ScopeAdmin::class, 'member' => ScopeMember::class], false);

    $schema = Capsule::schema('testing');
    foreach (['scope_admins', 'scope_members'] as $tabla) {
        $schema->create($tabla, fn (Blueprint $t) => $t->increments('id'));
    }
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
        $t->string('user_type')->nullable();
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
        $t->string('user_type')->nullable();
        $t->timestamps();
    });

    // Mismo id en los dos scopes: el escenario que el bug necesitaba.
    $this->admin = ScopeAdmin::query()->create([]);
    $this->member = ScopeMember::query()->create([]);
    expect($this->admin->id)->toBe($this->member->id);
});

afterEach(function () {
    Relation::requireMorphMap(false);
    Relation::morphMap($this->morphMapPrevio ?? [], false);
    MorphPivot::flushSchemaCache();
});

it('un member NO ve los roles de un admin con su mismo id', function () {
    $this->admin->assignRole('editor');

    expect($this->admin->roles()->pluck('name')->all())->toBe(['editor'])
        ->and($this->member->roles()->pluck('name')->all())->toBe([]);
});

it('un member NO ve las abilities directas de un admin con su mismo id', function () {
    $this->admin->giveAbilityTo('wall.publish');

    expect($this->admin->getEffectiveAbilities())->toContain('wall.publish')
        ->and($this->member->getEffectiveAbilities())->not->toContain('wall.publish');
});

it('un member NO hereda las abilities que le llegan al admin por un rol', function () {
    $rol = Role::query()->create(['name' => 'editor', 'guard' => 'admin']);
    $ability = Ability::query()->create(['name' => 'wall.moderate']);
    Capsule::table('ability_role')->insert(['ability_id' => $ability->id, 'role_id' => $rol->id]);
    $this->admin->assignRole('editor');

    expect($this->admin->getEffectiveAbilities())->toContain('wall.moderate')
        ->and($this->member->getEffectiveAbilities())->not->toContain('wall.moderate');
});

it('escribe SIEMPRE el alias del morph map, entre por donde entre', function () {
    // Los dos caminos de escritura tenían vocabularios distintos: `assignRole`
    // ponía el FQCN y `attach` el alias. La columna quedaba mezclada y
    // cualquier filtro sobre ella tenía que adivinar cuál.
    $this->admin->assignRole('editor');
    $otro = Role::query()->create(['name' => 'otro', 'guard' => 'admin']);
    $this->admin->roles()->attach($otro->id);

    $tipos = Capsule::table('role_user')->pluck('user_type')->unique()->values()->all();

    expect($tipos)->toBe(['admin']);
});

it('sigue reconociendo las filas viejas escritas con el FQCN', function () {
    // 🔴 LA MITAD QUE HACE QUE EL DEPLOY NO SEA UNA CAÍDA.
    // Si el filtro exigiera sólo el alias, en el instante del deploy toda fila
    // escrita antes —con el FQCN— dejaría de matchear y todo el mundo perdería
    // sus roles hasta que corriera la migración. Un arreglo de seguridad que
    // provoca una caída total es peor que el agujero.
    $rol = Role::query()->create(['name' => 'legado', 'guard' => 'admin']);
    Capsule::table('role_user')->insert([
        'role_id' => $rol->id,
        'user_id' => $this->admin->id,
        'user_type' => ScopeAdmin::class, // como lo escribía la versión anterior
    ]);

    expect($this->admin->roles()->pluck('name')->all())->toBe(['legado'])
        // Y aceptar los dos valores NO reabre el agujero: dos modelos
        // distintos tienen alias distinto Y FQCN distinto.
        ->and($this->member->roles()->pluck('name')->all())->toBe([]);
});

it('no rompe cuando la pivot no tiene columna user_type', function () {
    // BC con consumidores que nunca publicaron la migración polimórfica: sin
    // la columna no hay nada que filtrar ni que escribir, y todo sigue
    // funcionando como antes.
    Capsule::schema('testing')->drop('role_user');
    Capsule::schema('testing')->create('role_user', function (Blueprint $t) {
        $t->unsignedInteger('role_id');
        $t->unsignedInteger('user_id');
        $t->timestamps();
    });
    MorphPivot::flushSchemaCache();

    $this->admin->assignRole('editor');

    expect($this->admin->roles()->pluck('name')->all())->toBe(['editor']);
});

it('la migración pasa las filas viejas del FQCN al alias', function () {
    $rol = Role::query()->create(['name' => 'legado', 'guard' => 'admin']);
    $ability = Ability::query()->create(['name' => 'wall.legado']);

    Capsule::table('role_user')->insert([
        'role_id' => $rol->id, 'user_id' => $this->admin->id, 'user_type' => ScopeAdmin::class,
    ]);
    Capsule::table('ability_user')->insert([
        'ability_id' => $ability->id, 'user_id' => $this->admin->id, 'user_type' => ScopeAdmin::class,
    ]);
    // Un valor que el morph map NO conoce: tiene que quedar intacto. El costo
    // de que la migración se pase de lista es que alguien pierda permisos.
    Capsule::table('role_user')->insert([
        'role_id' => $rol->id, 'user_id' => 999, 'user_type' => 'Otro\\Paquete\\Modelo',
    ]);

    $this->runPackageMigration('2026_07_26_000001_normalize_rbac_pivot_user_type.php');

    expect(Capsule::table('role_user')->where('user_id', $this->admin->id)->value('user_type'))->toBe('admin')
        ->and(Capsule::table('ability_user')->value('user_type'))->toBe('admin')
        ->and(Capsule::table('role_user')->where('user_id', 999)->value('user_type'))->toBe('Otro\\Paquete\\Modelo');
});

it('la migración es idempotente', function () {
    $rol = Role::query()->create(['name' => 'legado', 'guard' => 'admin']);
    Capsule::table('role_user')->insert([
        'role_id' => $rol->id, 'user_id' => $this->admin->id, 'user_type' => ScopeAdmin::class,
    ]);

    $this->runPackageMigration('2026_07_26_000001_normalize_rbac_pivot_user_type.php');
    $this->runPackageMigration('2026_07_26_000001_normalize_rbac_pivot_user_type.php');

    expect(Capsule::table('role_user')->value('user_type'))->toBe('admin');
});
