<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Auth\Models\Ability;
use Mk\Director\Auth\Models\AuthUser;
use Mk\Director\Auth\Models\Role;
use Mk\Director\Auth\Pivots\MkAbilityUserPivot;
use Mk\Director\Auth\Pivots\MkRoleUserPivot;
use Mk\Director\Console\Commands\AuthCreateSuperAdminCommand;
use Mk\Director\Database\Eloquent\Relations\MkBelongsToMany;
use Mk\Director\Tenancy\TenantScope;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk:auth:create-super-admin` para CUALQUIER scope, e idempotente con el scope
 * de tenant fail-closed prendido (hallazgo #47 del piloto NetPizza).
 *
 * Dos defectos:
 *
 *  1. Estaba clavado a `App\Modules\Admin\Models\Admin` y `AdminRolesSeeder`.
 *     Un tercer scope (los operadores de plataforma del piloto) no tenía cómo
 *     crear su primer usuario salvo tinker. `--scope=` resuelve el modelo por
 *     el mismo camino que `mk.auth:{scope}` resuelve al usuario:
 *     `auth.guards.{scope}.provider` → `auth.providers.{provider}.model`.
 *
 *  2. El chequeo de "ya existe" era `Modelo::where(login, valor)->exists()`. En
 *     consola no hay tenant, y con `tenant.fail_closed` el scope agrega
 *     `where 1 = 0`: el usuario existe y el comando NO LO VE, intenta insertarlo
 *     de nuevo y revienta contra el unique. Medido en `netpizza_testing`:
 *
 *         fail_closed=false -> «Ya existe un admin ... No se creó nada.»
 *         fail_closed=true  -> SQLSTATE[23505] admins_email_unique
 *
 * 🔴 Contra sqlite real, con el `TenantScope` de verdad y el comando corrido por
 * `run()`: un test que parsea el source afirmaba `->exists()` y pasaba verde con
 * el duplicado vivo.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

afterEach(function () {
    $this->tearDownHttpApp();
});

/**
 * Los overrides de `roles()`/`directAbilities()` que el scaffolder emite en
 * TODO modelo de scope: la pivot usa `user_id`, no `{modelo}_id`.
 */
trait PivotsComoLosEmiteElScaffolder
{
    public function roles(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
                ->using(MkRoleUserPivot::class)
                ->wherePivot('user_type', static::class)
                ->withTimestamps()
        );
    }

    public function directAbilities(): BelongsToMany
    {
        return MkBelongsToMany::from(
            $this->belongsToMany(Ability::class, 'ability_user', 'user_id', 'ability_id')
                ->using(MkAbilityUserPivot::class)
                ->wherePivot('user_type', static::class)
                ->withTimestamps()
        );
    }
}

/** El scope que NO es admin, con el scope de tenant del paquete puesto. */
final class OperadorDePlataforma extends AuthUser
{
    use PivotsComoLosEmiteElScaffolder;

    protected $table = 'operadores';

    protected static function booted(): void
    {
        self::addGlobalScope('tenant', new TenantScope);
    }

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('operador');
    }
}

/** El admin de siempre (BC), provider `admins` de `BootsHttpApp`. */
final class AdminDeSiempre extends AuthUser
{
    use PivotsComoLosEmiteElScaffolder;

    protected $table = 'admins';

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('admin');
    }
}

/**
 * Tabla de un scope de auth SIN columna de tenant, con el unique del login,
 * como la migración que genera el scaffolder sin `--multi-tenant`.
 *
 * 🔴 Antes esta tabla traía `client_id` aunque ninguno de estos tests mirara el
 * tenant. Desde que el comando EXIGE `--tenant` cuando la columna existe, tener
 * la columna acá hacía que todos estos tests midieran el camino multi-tenant
 * sin quererlo. La forma con tenant vive en {@see scopeTableConClientId()}.
 */
function scopeTableName(string $tabla): void
{
    Schema::create($tabla, function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password');
        $t->string('auth_scope')->nullable();
        $t->rememberToken();
        $t->timestamps();
    });
}

/**
 * La misma tabla, pero con la columna de tenant que emite `--multi-tenant`
 * (`client_id`, que es el default de `HasTenantMembership::getTenantColumn()`).
 *
 * `$nullable = false` reproduce la forma del consumidor que endureció la
 * columna (NetPizza, etapa 2 de la consola de plataforma).
 */
function scopeTableConClientId(string $tabla, bool $nullable = true): void
{
    Schema::create($tabla, function ($t) use ($nullable) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('email')->unique();
        $t->string('password');
        $t->string('auth_scope')->nullable();
        $column = $t->string('client_id');
        if ($nullable) {
            $column->nullable();
        }
        $t->rememberToken();
        $t->timestamps();
    });
}

function packageRbacTables(): void
{
    foreach (glob(dirname(__DIR__, 2).'/src/Auth/Database/Migrations/*.php') ?: [] as $migracion) {
        (require $migracion)->up();
    }
}

/**
 * @param  array<string, mixed>  $args
 * @return array{0: int, 1: string}
 */
function createSuperAdmin(array $args): array
{
    // El comando se construye DESPUÉS de configurar auth: `configure()` lee
    // los modelos de los guards para agregar `--{loginField}`.
    $command = new AuthCreateSuperAdminCommand;
    $command->setLaravel(app());

    $input = new ArrayInput($args + ['--name' => 'Primero', '--password' => 'secreto123']);
    $input->setInteractive(false);
    $output = new BufferedOutput;

    try {
        $exit = $command->run($input, $output);
    } catch (Throwable $e) {
        return [99, $output->fetch().get_class($e).': '.$e->getMessage()];
    }

    return [$exit, $output->fetch()];
}

function configureOperatorScope(): void
{
    config([
        'auth.guards.operador' => ['driver' => 'sanctum', 'provider' => 'operadores'],
        'auth.providers.operadores' => ['driver' => 'eloquent', 'model' => OperadorDePlataforma::class],
    ]);
}

test('--scope=operador crea el usuario en la tabla del scope, con su auth_scope y su rol super-admin', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableName('operadores');
    packageRbacTables();

    [$exit, $output] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com']);

    expect($exit)->toBe(0, $output);
    $row = DB::table('operadores')->where('email', 'ops@example.com')->first();
    expect($row)->not->toBeNull();
    expect($row->auth_scope)->toBe('operador');
    expect(DB::table('roles')->where('name', 'super-admin')->value('guard'))->toBe('operador');
    expect($output)->toContain('/api/operador/auth/login');
});

test('idempotente con el scope de tenant FAIL-CLOSED: la segunda corrida ve al usuario y no duplica', function () {
    $this->bootHttpApp(AdminDeSiempre::class, ['tenant' => ['fail_closed' => true]]);
    configureOperatorScope();
    scopeTableName('operadores');
    packageRbacTables();

    [$exit1, $out1] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com']);
    expect($exit1)->toBe(0, $out1);

    // Contraprueba de que el scope ESTÁ puesto y oculta la fila: sin esto, un
    // "no duplicó" también saldría verde con un scope que no filtra nada.
    expect(OperadorDePlataforma::query()->count())->toBe(0);
    expect(DB::table('operadores')->count())->toBe(1);

    [$exit2, $out2] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com']);

    expect($exit2)->toBe(0, $out2);
    expect($out2)->toContain('No se creó nada');
    expect(DB::table('operadores')->count())->toBe(1);
});

test('--no-roles: crea sólo el usuario, sin tocar roles ni abilities (scope sin RBAC)', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableName('operadores');
    packageRbacTables();

    [$exit, $output] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com', '--no-roles' => true]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('operadores')->count())->toBe(1);
    expect(DB::table('roles')->count())->toBe(0);
    expect(DB::table('role_user')->count())->toBe(0);
    expect(DB::table('ability_user')->count())->toBe(0);
});

test('sin tablas de RBAC no revienta: crea el usuario y avisa que saltó roles', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableName('operadores');

    [$exit, $output] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com']);

    expect($exit)->toBe(0, $output);
    expect(DB::table('operadores')->count())->toBe(1);
    expect($output)->toContain('roles');
});

test('BC: sin --scope usa el scope admin (guard admin → provider admins)', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    scopeTableName('admins');
    packageRbacTables();

    [$exit, $output] = createSuperAdmin(['--email' => 'root@example.com']);

    expect($exit)->toBe(0, $output);
    expect(DB::table('admins')->where('email', 'root@example.com')->value('auth_scope'))->toBe('admin');
    expect(DB::table('roles')->where('name', 'super-admin')->value('guard'))->toBe('admin');

    // Y sigue idempotente.
    [$exit2, $out2] = createSuperAdmin(['--email' => 'root@example.com']);
    expect($exit2)->toBe(0, $out2);
    expect(DB::table('admins')->count())->toBe(1);
});

test('--scope de un scope que no existe falla limpio, apuntando a mk:make:auth-user', function () {
    $this->bootHttpApp(AdminDeSiempre::class);

    [$exit, $output] = createSuperAdmin(['--scope' => 'fantasma', '--email' => 'x@example.com']);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('mk:make:auth-user Fantasma');
});

/** Scope con login por CI: `configure()` tiene que ofrecer `--ci` aunque no sea el admin. */
final class MeseroPorCi extends AuthUser
{
    use PivotsComoLosEmiteElScaffolder;

    protected $table = 'meseros';

    protected string $loginField = 'ci';

    protected $fillable = ['name', 'ci', 'password', 'auth_scope'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setAuthScope('mesero');
    }
}

/**
 * ── El tenant del primer usuario ────────────────────────────────────────────
 *
 * El comando creaba el usuario SIN tocar la columna de tenant. En un scope
 * generado con `--multi-tenant` la columna sale `nullable`, así que la fila
 * quedaba con tenant nulo y NADIE se enteraba: es el caso que el
 * `TenantMembershipGate` deja pasar con CUALQUIER `X-Tenant-ID`
 * (`$userTenantId !== null && ...` — con null no compara nada y devuelve
 * `null`, o sea "seguí"). Y en cuanto el consumidor endurece la columna a
 * `NOT NULL`, el mismo comando muere con un `23502` crudo que no dice qué
 * falta.
 */
final class TenantDePrueba extends Model
{
    protected $table = 'tenants';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['id', 'slug', 'name'];

    public $timestamps = false;
}

function tenantsTable(): void
{
    Schema::create('tenants', function ($t) {
        $t->string('id')->primary();
        $t->string('slug')->unique();
        $t->string('name');
    });

    TenantDePrueba::create(['id' => 'tnt-norte', 'slug' => 'norte', 'name' => 'Sucursal Norte']);
    TenantDePrueba::create(['id' => 'tnt-sur', 'slug' => 'sur', 'name' => 'Sucursal Sur']);
}

/** @param  array<string,mixed>  $mkDirector */
function bootConTenantModel(array $mkDirector = []): void
{
    config(['mk_director.tenant.model' => TenantDePrueba::class]);
    foreach ($mkDirector as $clave => $valor) {
        config(["mk_director.tenant.{$clave}" => $valor]);
    }
}

test('scope CON columna de tenant: sin --tenant se niega, nombra la opción y no escribe nada', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin(['--scope' => 'operador', '--email' => 'ops@example.com']);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('--tenant');
    expect(DB::table('operadores')->count())->toBe(0);
});

test('--tenant por id ancla la fila al tenant', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'tnt-norte', '--no-roles' => true,
    ]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('operadores')->where('email', 'ops@example.com')->value('client_id'))->toBe('tnt-norte');
});

test('--tenant por slug resuelve al id por el mismo camino que TenantResolver', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'sur', '--no-roles' => true,
    ]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('operadores')->where('email', 'ops@example.com')->value('client_id'))->toBe('tnt-sur');
});

test('--tenant que no existe se niega antes de escribir', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'fantasma', '--no-roles' => true,
    ]);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('fantasma');
    expect(DB::table('operadores')->count())->toBe(0);
});

test('columna de tenant NOT NULL: --without-tenant se niega en vez de tirar el 23502 crudo', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores', nullable: false);
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--without-tenant' => true, '--no-roles' => true,
    ]);

    expect($exit)->toBe(1, $output);
    expect($output)->toContain('--tenant');
    expect(DB::table('operadores')->count())->toBe(0);
});

test('--without-tenant con columna nullable crea el usuario sin tenant Y avisa del riesgo', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--without-tenant' => true, '--no-roles' => true,
    ]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('operadores')->where('email', 'ops@example.com')->value('client_id'))->toBeNull();
    expect($output)->toContain('X-Tenant-ID');
});

test('idempotente: la segunda corrida con el MISMO tenant no duplica', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    $args = ['--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'tnt-norte', '--no-roles' => true];

    [$exit1, $out1] = createSuperAdmin($args);
    expect($exit1)->toBe(0, $out1);

    [$exit2, $out2] = createSuperAdmin($args);

    expect($exit2)->toBe(0, $out2);
    expect($out2)->toContain('No se creó nada');
    expect(DB::table('operadores')->count())->toBe(1);
});

test('un usuario existente NO se muda de tenant: la corrida con otro tenant se niega y la fila no cambia', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableConClientId('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit1, $out1] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'tnt-norte', '--no-roles' => true,
    ]);
    expect($exit1)->toBe(0, $out1);

    [$exit2, $out2] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'tnt-sur', '--no-roles' => true,
    ]);

    expect($exit2)->toBe(1, $out2);
    expect($out2)->toContain('tnt-norte');
    expect(DB::table('operadores')->count())->toBe(1);
    expect(DB::table('operadores')->value('client_id'))->toBe('tnt-norte');
});

test('scope SIN columna de tenant: --tenant se niega en vez de descartarse en silencio', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    configureOperatorScope();
    scopeTableName('operadores');
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'operador', '--email' => 'ops@example.com', '--tenant' => 'tnt-norte', '--no-roles' => true,
    ]);

    expect($exit)->toBe(1, $output);
    expect(DB::table('operadores')->count())->toBe(0);
});

test('--scope con login field propio: acepta --{loginField} (antes sólo se leía del modelo Admin)', function () {
    $this->bootHttpApp(AdminDeSiempre::class);
    config([
        'auth.guards.mesero' => ['driver' => 'sanctum', 'provider' => 'meseros'],
        'auth.providers.meseros' => ['driver' => 'eloquent', 'model' => MeseroPorCi::class],
    ]);
    Schema::create('meseros', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('ci')->unique();
        $t->string('password');
        $t->string('auth_scope')->nullable();
        $t->timestamps();
    });

    [$exit, $output] = createSuperAdmin(['--scope' => 'mesero', '--ci' => '1234567', '--no-roles' => true]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('meseros')->where('ci', '1234567')->value('auth_scope'))->toBe('mesero');
});

test('el tenant se escribe aunque el $fillable del modelo no lo declare', function () {
    // `MeseroPorCi::$fillable` es `['name','ci','password','auth_scope']`: sin
    // la columna de tenant. Con `create()` el mass assignment la descarta SIN
    // ERROR y la fila vuelve a quedar con tenant nulo — el defecto original,
    // por otra puerta.
    $this->bootHttpApp(AdminDeSiempre::class);
    config([
        'auth.guards.mesero' => ['driver' => 'sanctum', 'provider' => 'meseros'],
        'auth.providers.meseros' => ['driver' => 'eloquent', 'model' => MeseroPorCi::class],
    ]);
    Schema::create('meseros', function ($t) {
        $t->uuid('id')->primary();
        $t->string('name');
        $t->string('ci')->unique();
        $t->string('password');
        $t->string('auth_scope')->nullable();
        $t->string('client_id')->nullable();
        $t->timestamps();
    });
    tenantsTable();
    bootConTenantModel();

    [$exit, $output] = createSuperAdmin([
        '--scope' => 'mesero', '--ci' => '1234567', '--tenant' => 'tnt-sur', '--no-roles' => true,
    ]);

    expect($exit)->toBe(0, $output);
    expect(DB::table('meseros')->where('ci', '1234567')->value('client_id'))->toBe('tnt-sur');
});
