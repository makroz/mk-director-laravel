<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkAbilityUserPivot;
use Mk\Director\Auth\Pivots\MkRoleUserPivot;
use Mk\Director\Auth\Support\MorphPivot;
use Mk\Director\Console\Commands\AuthGrantCommand;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;
use Mk\Director\Tenancy\TenantScope;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:auth:grant` — darle roles y abilities a un usuario que YA existe.
 *
 * El agujero que cierra: `mk:auth:create-super-admin` es idempotente, así que
 * re-correrlo sobre un login ya tomado sale en verde SIN tocar permisos. Un
 * usuario creado con `--no-roles` quedaba sin capacidades y sin ninguna forma
 * de dárselas salvo `tinker` — y es justo el caso del PRIMER usuario de un
 * scope nuevo, que no tiene todavía pantalla de roles donde apretar el botón.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    MorphPivot::flushSchemaCache();
    $this->tearDownHttpApp();
});

/** El modelo de scope, con las pivots como las emite el scaffolder. */
final class OperadorAGraduar extends AuthUser
{
    protected $table = 'operadores_a_graduar';

    protected static function booted(): void
    {
        self::addGlobalScope('tenant', new TenantScope);
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('operator');
    }

    public function roles(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
                ->using(MkRoleUserPivot::class)
                ->wherePivot('user_type', self::class)
                ->withTimestamps()
        );
    }

    public function directAbilities(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Ability::class, 'ability_user', 'user_id', 'ability_id')
                ->using(MkAbilityUserPivot::class)
                ->wherePivot('user_type', self::class)
                ->withTimestamps()
        );
    }
}

function prepararScopeParaGrant(object $test): OperadorAGraduar
{
    $test->bootHttpApp(OperadorAGraduar::class, ['tenant' => ['enabled' => false]]);
    config(['hashing' => ['driver' => 'bcrypt', 'bcrypt' => ['rounds' => 4]]]);
    config([
        'auth.guards.operator' => ['driver' => 'sanctum', 'provider' => 'operadores'],
        'auth.providers.operadores' => ['driver' => 'eloquent', 'model' => OperadorAGraduar::class],
    ]);

    Schema::create('operadores_a_graduar', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password');
        $t->string('auth_scope')->nullable();
        $t->timestamps();
    });

    foreach (glob(dirname(__DIR__, 3).'/src/Auth/Database/Migrations/*.php') ?: [] as $migracion) {
        (require $migracion)->up();
    }

    return OperadorAGraduar::create([
        'name' => 'Mario',
        'email' => 'mario@plataforma.test',
        'password' => Hash::make('secreto123'),
        'auth_scope' => 'operator',
    ]);
}

/**
 * @param  array<string, mixed>  $args
 * @return array{0: int, 1: string}
 */
function correrGrant(array $args): array
{
    $command = new AuthGrantCommand;
    $command->setLaravel(app());

    $input = new ArrayInput($args);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    try {
        $exit = $command->run($input, $output);
    } catch (Throwable $e) {
        return [99, $output->fetch().get_class($e).': '.$e->getMessage()];
    }

    return [$exit, $output->fetch()];
}

test('🔴 le da el comodín al primer usuario del scope, que se había creado sin permisos', function () {
    $operador = prepararScopeParaGrant($this);

    // El punto de partida: existe, entra, y no puede nada.
    expect($operador->canMk('*'))->toBeFalse();

    [$exit, $salida] = correrGrant([
        'scope' => 'operator',
        'login' => 'mario@plataforma.test',
        '--abilities' => '*',
    ]);

    expect($exit)->toBe(0, $salida);
    expect($operador->fresh()->canMk('*'))->toBeTrue();
    expect($operador->fresh()->canMk('platform.tenants.create'))->toBeTrue();
});

test('asigna roles creándolos con el guard del scope', function () {
    $operador = prepararScopeParaGrant($this);

    [$exit, $salida] = correrGrant([
        'scope' => 'operator',
        'login' => 'mario@plataforma.test',
        '--roles' => 'soporte',
    ]);

    expect($exit)->toBe(0, $salida);

    $rol = Role::query()->where('name', 'soporte')->first();
    expect($rol)->not->toBeNull();

    // 🔴 El guard es el scope, no el default: `soporte` de `operator` y
    // `soporte` de `admin` son dos roles distintos, y el unique de la tabla es
    // (name, guard). Un rol creado con el guard equivocado no aparece nunca en
    // el picker de roles de su propio scope.
    expect($rol->guard)->toBe('operator');
    expect($operador->fresh()->roles()->pluck('roles.name')->all())->toContain('soporte');
});

test('🔴 ENCUENTRA AL USUARIO CON EL AISLAMIENTO POR TENANT PRENDIDO', function () {
    // En consola no hay contexto de tenant. Con `fail_closed` el scope global
    // agrega `where 1 = 0`: el usuario existe y la consulta no lo ve. Es el
    // mismo hallazgo #47 que ya había mordido al comando de alta, y acá
    // significaría «no existe un operator con ese email» sobre alguien que sí
    // está — el peor error posible en el comando que reparte permisos.
    $operador = prepararScopeParaGrant($this);
    config(['mk_director.tenant.enabled' => true, 'mk_director.tenant.fail_closed' => true]);

    [$exit, $salida] = correrGrant([
        'scope' => 'operator',
        'login' => 'mario@plataforma.test',
        '--abilities' => '*',
    ]);

    expect($exit)->toBe(0, $salida);
    expect($salida)->not->toContain('No existe');

    config(['mk_director.tenant.fail_closed' => false]);
    expect($operador->fresh()->canMk('*'))->toBeTrue();
});

test('es acumulativo: no le saca lo que ya tenía', function () {
    $operador = prepararScopeParaGrant($this);

    correrGrant(['scope' => 'operator', 'login' => 'mario@plataforma.test', '--abilities' => 'platform.tenants.viewAny']);
    correrGrant(['scope' => 'operator', 'login' => 'mario@plataforma.test', '--abilities' => 'platform.tenants.create']);

    $fresco = $operador->fresh();
    expect($fresco->canMk('platform.tenants.viewAny'))->toBeTrue();
    expect($fresco->canMk('platform.tenants.create'))->toBeTrue();
});

test('falla si el usuario no existe, en vez de crear uno', function () {
    prepararScopeParaGrant($this);

    [$exit, $salida] = correrGrant([
        'scope' => 'operator',
        'login' => 'nadie@plataforma.test',
        '--abilities' => '*',
    ]);

    expect($exit)->toBe(1);
    expect($salida)->toContain('No existe');
    expect(OperadorAGraduar::query()->count())->toBe(1);
});

test('falla si no le pedís nada, en vez de salir en verde sin hacer nada', function () {
    prepararScopeParaGrant($this);

    [$exit, $salida] = correrGrant(['scope' => 'operator', 'login' => 'mario@plataforma.test']);

    expect($exit)->toBe(1);
    expect($salida)->toContain('No pediste nada');
});
