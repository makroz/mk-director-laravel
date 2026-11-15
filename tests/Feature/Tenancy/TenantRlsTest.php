<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Mk\Director\Tenancy\Rls;
use Mk\Director\Tenancy\TenantContext;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\Concerns\PushTestUser;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * S7 — optional Postgres Row Level Security.
 *
 * The cases that need a real database read ONE env var, `MK_TEST_PGSQL_RLS_URL`:
 * a full DSN (`postgresql://user:pass@host:5432/db`) of a NON-superuser role
 * that owns the database. Superusers and BYPASSRLS roles skip every policy, so
 * running as one proves nothing: the test fails loudly instead of skipping.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

const RLS_TENANT_A = '11111111-1111-4111-8111-111111111111';
const RLS_TENANT_B = '22222222-2222-4222-8222-222222222222';

function bootRlsApp(bool $rls): void
{
    test()->bootHttpApp(PushTestUser::class, ['tenant' => ['enabled' => false, 'rls' => $rls]]);
}

/** Boots the app on the real Postgres connection and (re)creates the fixture table. */
function bootRlsPostgres(string $idType = 'uuid'): void
{
    $dsn = getenv('MK_TEST_PGSQL_RLS_URL');
    if ($dsn === false || $dsn === '') {
        test()->markTestSkipped('Set MK_TEST_PGSQL_RLS_URL to a non-superuser role DSN to run the Postgres RLS tests.');
    }

    bootRlsApp(true);
    config(['database.connections.rls_pg' => ['driver' => 'pgsql', 'url' => $dsn]]);
    DB::setDefaultConnection('rls_pg');

    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');
    if ($role->rolsuper || $role->rolbypassrls) {
        throw new RuntimeException('MK_TEST_PGSQL_RLS_URL must be a NON-superuser role without BYPASSRLS: RLS never applies to those.');
    }

    DB::statement('drop table if exists rls_items');
    DB::statement("create table rls_items (id serial primary key, tenant_id {$idType} not null, label text not null)");
    Rls::enable('rls_items');
}

/** @return array<int,string> */
function rlsLabels(): array
{
    return DB::table('rls_items')->orderBy('id')->pluck('label')->all();
}

function rlsInsert(string $tenant, string $label): void
{
    app(TenantContext::class)->run($tenant, fn () => DB::table('rls_items')->insert(['tenant_id' => $tenant, 'label' => $label]));
}

test('with rls off or on a non-pgsql driver the listener issues no query', function (bool $rls) {
    bootRlsApp($rls);
    $tenant = app(TenantContext::class);

    DB::enableQueryLog();
    $tenant->set('a');
    $tenant->flush();

    expect(DB::getQueryLog())->toBe([]);
})->with([[false], [true]]);

test('Rls::enable refuses a non-pgsql driver', function () {
    bootRlsApp(true);

    expect(fn () => Rls::enable('anything'))->toThrow(RuntimeException::class, 'pgsql');
    expect(fn () => Rls::disable('anything'))->toThrow(RuntimeException::class, 'pgsql');
});

test('two tenants each see only their own rows through raw DB::table', function () {
    bootRlsPostgres();
    rlsInsert(RLS_TENANT_A, 'a1');
    rlsInsert(RLS_TENANT_A, 'a2');
    rlsInsert(RLS_TENANT_B, 'b1');

    $tenant = app(TenantContext::class);
    $tenant->set(RLS_TENANT_A);
    expect(rlsLabels())->toBe(['a1', 'a2']);

    $tenant->set(RLS_TENANT_B);
    expect(rlsLabels())->toBe(['b1']);
});

test('without a tenant there are zero rows and inserts are rejected', function () {
    bootRlsPostgres();
    rlsInsert(RLS_TENANT_A, 'a1');

    expect(rlsLabels())->toBe([]);
    expect(fn () => DB::table('rls_items')->insert(['tenant_id' => RLS_TENANT_A, 'label' => 'x']))
        ->toThrow(QueryException::class, 'row-level security');
});

test('an insert carrying another tenant id is rejected', function () {
    bootRlsPostgres();
    app(TenantContext::class)->set(RLS_TENANT_A);

    expect(fn () => DB::table('rls_items')->insert(['tenant_id' => RLS_TENANT_B, 'label' => 'x']))
        ->toThrow(QueryException::class, 'row-level security');
    expect(DB::table('rls_items')->insert(['tenant_id' => RLS_TENANT_A, 'label' => 'ok']))->toBeTrue();
});

test('flush brings back zero rows', function () {
    bootRlsPostgres();
    rlsInsert(RLS_TENANT_A, 'a1');
    $tenant = app(TenantContext::class);

    $tenant->set(RLS_TENANT_A);
    expect(rlsLabels())->toBe(['a1']);

    $tenant->flush();
    expect(rlsLabels())->toBe([]);
});

test('TenantContext::run restores the previous tenant visibility', function () {
    bootRlsPostgres();
    rlsInsert(RLS_TENANT_A, 'a1');
    rlsInsert(RLS_TENANT_B, 'b1');
    $tenant = app(TenantContext::class);

    $tenant->set(RLS_TENANT_A);
    $inside = $tenant->run(RLS_TENANT_B, fn () => rlsLabels());

    expect($inside)->toBe(['b1']);
    expect(rlsLabels())->toBe(['a1']);
});

test('the policy compares in the column type, bigint included', function () {
    bootRlsPostgres('bigint');
    $tenant = app(TenantContext::class);
    $tenant->run(7, fn () => DB::table('rls_items')->insert(['tenant_id' => 7, 'label' => 'seven']));
    $tenant->run(8, fn () => DB::table('rls_items')->insert(['tenant_id' => 8, 'label' => 'eight']));

    expect($tenant->run(7, fn () => rlsLabels()))->toBe(['seven']);
    expect(rlsLabels())->toBe([]);
});

test('Rls::enable twice is idempotent and Rls::disable gives the owner everything back', function () {
    bootRlsPostgres();
    rlsInsert(RLS_TENANT_A, 'a1');
    rlsInsert(RLS_TENANT_B, 'b1');

    Rls::enable('rls_items');
    expect(DB::select("select 1 from pg_policies where tablename = 'rls_items'"))->toHaveCount(1);
    app(TenantContext::class)->set(RLS_TENANT_A);
    expect(rlsLabels())->toBe(['a1']);

    Rls::disable('rls_items');
    app(TenantContext::class)->flush();
    expect(rlsLabels())->toBe(['a1', 'b1']);
    expect(DB::select("select 1 from pg_policies where tablename = 'rls_items'"))->toBe([]);
});

// ── Regressions found by the independent verifier ──────────────────

final class RlsSecondConnectionResolver implements \Mk\Director\Tenancy\TenantConnectionResolver
{
    public function connectionFor(string|int $tenantId): ?string
    {
        return $tenantId === RLS_TENANT_B ? 'rls_pg2' : null;
    }
}

test('flush clears the setting on the tenant connection, not only on the default one', function () {
    bootRlsPostgres();
    config([
        'mk_director.tenant.connection_resolver' => RlsSecondConnectionResolver::class,
        'database.connections.rls_pg2' => config('database.connections.rls_pg'),
    ]);
    app()->forgetInstance(\Mk\Director\Tenancy\TenantConnectionResolver::class);
    app()->bind(\Mk\Director\Tenancy\TenantConnectionResolver::class, RlsSecondConnectionResolver::class);
    app()->forgetInstance(\Mk\Director\Tenancy\TenantConnectionSwitcher::class);

    $tenant = app(TenantContext::class);
    rlsInsert(RLS_TENANT_B, 'b1');

    $tenant->set(RLS_TENANT_B);
    $tenant->flush();
    expect(DB::connection('rls_pg2')->table('rls_items')->count())->toBe(0);

    $tenant->set(RLS_TENANT_A);
    $tenant->run(RLS_TENANT_B, fn () => null);
    expect(DB::connection('rls_pg2')->table('rls_items')->count())->toBe(0);
    $tenant->flush();
});

test('a rolled back transaction never leaves the setting out of sync with the context', function () {
    bootRlsPostgres();
    $tenant = app(TenantContext::class);
    rlsInsert(RLS_TENANT_A, 'a1');

    $tenant->set(RLS_TENANT_A);
    DB::beginTransaction();
    $tenant->flush();
    DB::rollBack();
    expect($tenant->current())->toBeNull()
        ->and(rlsLabels())->toBe([]);

    DB::beginTransaction();
    $tenant->set(RLS_TENANT_A);
    DB::rollBack();
    expect(rlsLabels())->toBe(['a1']);
    $tenant->flush();
});
