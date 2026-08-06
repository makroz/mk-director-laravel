<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tenancy\TenantScope;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * EL FAIL-CLOSED TIENE QUE CERRAR, NO EXPLOTAR.
 *
 * 🔴 EL BUG. Con `tenant.fail_closed = true` y el contexto vacío, el scope
 * inyectaba un predicado imposible:
 *
 *     $builder->where($column, '=', -1);   // FAIL_CLOSED_SENTINEL
 *
 * `-1` es imposible contra un `id` autoincremental, y de ahí salió la idea. Pero
 * la mitad de los esquemas multi-tenant usan `tenant_id uuid`, y en Postgres
 * comparar una columna `uuid` contra un entero no devuelve cero filas: TIRA.
 *
 *     SQLSTATE[22P02]: invalid input syntax for type uuid: "-1"
 *
 * Un mecanismo de seguridad que revienta en vez de cerrar no es fail-closed: es
 * un 500 donde tenía que haber una lista vacía. Y peor, es un 500 que aparece
 * SÓLO cuando el contexto falta — o sea, justo en el escenario que el guard
 * existe para cubrir.
 *
 * ⚠️ HASTA DÓNDE LLEGA ESTE TEST, Y HASTA DÓNDE NO.
 *
 * El `22P02` lo tira el SERVIDOR de Postgres, y acá no hay uno (la suite corre
 * sobre sqlite en memoria; sqlite es de tipado laxo y compara `-1` contra un
 * texto sin quejarse, así que sobre sqlite el bug es INVISIBLE). No voy a
 * simular el error: un test que finge el motor mide mi simulación, no Postgres.
 *
 * Lo que sí se puede afirmar de verdad, y es lo que causa el 22P02:
 *  (1) que el predicado NO meta un valor de otro tipo en la comparación contra
 *      la columna de tenant — se mira el binding real de la query; y
 *  (2) que la query siga devolviendo CERO filas con filas de verdad en la tabla
 *      — sobre una conexión sqlite real, para que "arreglarlo" borrando el
 *      guard no pase en verde.
 *
 * (1) sola no alcanza (un guard borrado no bindea nada) y (2) sola tampoco (el
 * `-1` ya devuelve cero filas en sqlite). Juntas, sí.
 */
uses(MkLaravelTestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();

    $this->schema()->create('facturas_uuid', function ($t) {
        $t->uuid('id')->primary();
        // La columna de tenant es un UUID: el esquema donde el centinela
        // entero explota.
        $t->uuid('tenant_id');
        $t->string('numero');
    });

    foreach (['11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222'] as $i => $tenant) {
        $this->capsule->getConnection('testing')->table('facturas_uuid')->insert([
            'id' => sprintf('aaaaaaaa-aaaa-aaaa-aaaa-%012d', $i),
            'tenant_id' => $tenant,
            'numero' => 'F-'.$i,
        ]);
    }

    config(['mk_director.tenant.enabled' => true, 'mk_director.tenant.fail_closed' => true]);

    app()->instance(TenantContext::class, new TenantContext);
});

afterEach(function () {
    $this->tearDownDatabase();
});

function facturaUuidModel(): Model
{
    return new class extends Model
    {
        protected $table = 'facturas_uuid';

        protected $keyType = 'string';

        public $incrementing = false;

        public $timestamps = false;

        public function getTenantKey(): string
        {
            return 'tenant_id';
        }
    };
}

/** Query con el scope fail-closed ya aplicado (contexto vacío). */
function queryFailClosed(): Builder
{
    $model = facturaUuidModel();
    $builder = $model->newQuery();

    (new TenantScope)->apply($builder, $model);

    return $builder;
}

test('🔴 el predicado fail-closed NO mete un entero en la comparación contra la columna de tenant', function () {
    $builder = queryFailClosed();
    $bindings = $builder->getQuery()->getBindings();

    // Esto es exactamente lo que Postgres rechaza: el `-1` viajando como valor
    // de `tenant_id uuid`. Antes del fix los bindings eran `[-1]`.
    //
    // ⚠️ Se afirma sobre el ARRAY, no recorriéndolo con un foreach: si el array
    // queda vacío, el cuerpo del foreach no corre y el test pasa sin haber
    // afirmado NADA (PHPUnit lo marca "risky", que es fácil de pasar por alto).
    expect($bindings)->not->toContain(TenantScope::FAIL_CLOSED_SENTINEL);
    expect($bindings)->not->toContain(-1);
    expect($bindings)->not->toContain('-1');

    // Y el guard TIENE que seguir estando: sin esto, borrarlo pondría verde la
    // aserción de arriba.
    expect($builder->toSql())->toContain('1 = 0');
});

test('🔴 y el SQL no compara la columna de tenant contra ningún valor', function () {
    $sql = queryFailClosed()->toSql();

    // Un centinela seguro no puede depender del TIPO de la columna, así que no
    // puede comparar contra ella. La contradicción tiene que ser independiente
    // del esquema.
    expect($sql)->not->toContain('"tenant_id" =');
    expect($sql)->not->toContain('tenant_id` =');
});

test('EL CASO INVERSO: sigue devolviendo CERO filas — cerrar es la mitad que importa', function () {
    // Sin esta aserción, borrar el guard entero pondría los dos tests de arriba
    // en verde y dejaría la fuga abierta.
    expect(queryFailClosed()->count())->toBe(0);

    // Y hay filas de verdad para no contar: si la tabla estuviera vacía, el
    // cero no probaría nada.
    expect(facturaUuidModel()->newQuery()->count())->toBe(2);
});

test('el fail-closed sigue siendo OPT-IN: apagado, la query devuelve todo (BC)', function () {
    config(['mk_director.tenant.fail_closed' => false]);

    expect(queryFailClosed()->count())->toBe(2);
});

test('con contexto de tenant, el filtro normal sigue funcionando (no se rompió el camino feliz)', function () {
    app(TenantContext::class)->set('22222222-2222-2222-2222-222222222222');

    expect(queryFailClosed()->count())->toBe(1);
});
