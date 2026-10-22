<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Console\Commands\SpecDataCheckCommand;
use Mk\Director\Spec\DataCheck;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `mk-studio:data-check` counts what MK Studio's «Revisar contra la app» shows of the DATA: a booted app with a real
 * (sqlite) database, because every number has to be what the database answers.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

beforeEach(function () {
    $this->bootHttpApp(stdClass::class, ['tenant' => ['enabled' => false]]);

    Schema::create('zones', fn ($t) => $t->id());
    Schema::create('lots', function ($t) {
        $t->id();
        $t->unsignedBigInteger('zone_id')->nullable();
        $t->string('code', 36)->nullable();
        $t->decimal('discount', 8, 2)->nullable();
        $t->unsignedTinyInteger('status')->default(1);
    });
    Schema::create('migrations', function ($t) {
        $t->increments('id');
        $t->string('migration');
        $t->integer('batch');
    });
    DB::table('zones')->insert([['id' => 1], ['id' => 2]]);
    // 25 lots point at zones that do not exist (100 … 124), 3 at real ones, 2 at none.
    DB::table('lots')->insert([
        ...array_map(fn ($n) => ['zone_id' => 100 + $n, 'discount' => null, 'status' => 1], range(0, 24)),
        ['zone_id' => 1, 'discount' => 5, 'status' => 4],
        ['zone_id' => 2, 'discount' => 7.5, 'status' => 4],
        ['zone_id' => 2, 'discount' => null, 'status' => 1],
        ['zone_id' => null, 'discount' => 1, 'status' => 1],
        ['zone_id' => null, 'discount' => null, 'status' => 2],
    ]);
    DB::table('migrations')->insert([
        ['migration' => '2026_10_02_000000_create_lots_table', 'batch' => 2],
        ['migration' => '2026_10_01_000000_create_zones_table', 'batch' => 1],
    ]);
});

afterEach(fn () => $this->tearDownHttpApp());

test('counts_rows_nulls_and_orphans_of_a_relation_with_up_to_20_orphan_values', function () {
    [$check] = (new DataCheck)->run(['relations' => [
        ['table' => 'lots', 'column' => 'zone_id', 'target' => 'zones', 'targetKey' => 'id'],
    ]])['relations'];

    expect($check)->toMatchArray([
        'table' => 'lots', 'column' => 'zone_id', 'target' => 'zones', 'targetKey' => 'id',
        'targetExists' => true, 'rows' => 30, 'nulls' => 2, 'orphans' => 25,
    ])
        ->and($check['sample'])->toBe(range(100, 119))
        ->and($check)->not->toHaveKey('error');
});

test('reports_a_missing_target_table_and_different_key_types', function () {
    ['relations' => [$missing, $types]] = (new DataCheck)->run(['relations' => [
        ['table' => 'lots', 'column' => 'zone_id', 'target' => 'regions', 'targetKey' => 'id'],
        ['table' => 'lots', 'column' => 'code', 'target' => 'zones', 'targetKey' => 'id'],
    ]]);

    // Without the target every value points at nothing.
    expect($missing)->toMatchArray(['targetExists' => false, 'targetKeyType' => null, 'sameType' => false, 'rows' => 30, 'nulls' => 2, 'orphans' => 28])
        ->and($types)->toMatchArray(['targetExists' => true, 'columnType' => 'varchar', 'targetKeyType' => 'integer', 'sameType' => false]);
});

test('keys_of_different_types_are_never_joined_only_their_rows_counted', function () {
    // PostgreSQL refuses `uuid = character varying` with an error: the join must not run at all.
    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    [$check] = (new DataCheck)->run(['relations' => [
        ['table' => 'lots', 'column' => 'code', 'target' => 'zones', 'targetKey' => 'id'],
    ]])['relations'];

    expect($check)->toMatchArray(['sameType' => false, 'rows' => 30, 'nulls' => 30, 'orphans' => 0, 'sample' => []])
        ->and($check)->not->toHaveKey('error')
        ->and(implode("\n", $sql))->not->toContain('join');
});

test('a_failed_query_answers_its_sqlstate_and_one_line_never_the_connection_or_the_sql', function () {
    try {
        DB::select('select * from no_such_table where secret = 1');
    } catch (QueryException $e) {
    }
    expect($e->getMessage())->toContain('(Connection: ')->toContain('SQL: select') // what used to reach the screen
        ->and(DataCheck::reason($e))->toBe('SQLSTATE[HY000] General error: 1 no such table: no_such_table');

    // PostgreSQL's, as the studio got it on RETO: the hint and the SQL come on the lines after the first.
    $pg = new QueryException('pgsql', 'select count(*) from "school_uploads" as "s" left join "mk_uploads"', [], new PDOException(
        "SQLSTATE[42883]: Undefined function: 7 ERROR:  operator does not exist: uuid = character varying\nLINE 1: select count(*) from \"school_uploads\"\nHINT:  No operator matches."
    ));
    $pg->errorInfo = ['42883', 7, 'ERROR'];

    // And through the command's own queries: a virtual column that sqlite computes on read fails on bad JSON.
    // (added after the row: sqlite computes it on write too)
    DB::statement('create table docs (body text)');
    DB::table('docs')->insert(['body' => 'not json']);
    DB::statement("alter table docs add column kind text generated always as (json_extract(body, '$.kind')) virtual");
    [$failed] = (new DataCheck)->run(['columns' => [['table' => 'docs', 'column' => 'kind']]])['columns'];
    expect($failed['error'])->toStartWith('SQLSTATE[HY000] ')->not->toContain('Connection')->not->toContain('select');
    expect(DataCheck::reason($pg))->toBe('SQLSTATE[42883] Undefined function: 7 ERROR:  operator does not exist: uuid = character varying');
});

test('counts_non_null_values_of_a_column_and_rows_with_a_value', function () {
    $result = (new DataCheck)->run([
        'columns' => [['table' => 'lots', 'column' => 'discount']],
        'values' => [['table' => 'lots', 'column' => 'status', 'value' => 4], ['table' => 'lots', 'column' => 'status', 'value' => '2']],
    ]);

    expect($result['columns'])->toBe([['table' => 'lots', 'column' => 'discount', 'nonNull' => 3]])
        ->and($result['values'])->toBe([
            ['table' => 'lots', 'column' => 'status', 'value' => 4, 'rows' => 2],
            ['table' => 'lots', 'column' => 'status', 'value' => '2', 'rows' => 1],
        ])
        ->and($result)->not->toHaveKey('migrations');
});

test('lists_the_migrations_table', function () {
    expect((new DataCheck)->run(['migrations' => true])['migrations'])->toBe([
        '2026_10_01_000000_create_zones_table',
        '2026_10_02_000000_create_lots_table',
    ]);
});

test('refuses_a_table_or_column_the_schema_does_not_have', function () {
    // An identifier that would be SQL if it reached a query: it never does, the schema has no such name.
    $evil = 'lots; drop table zones; --';
    DB::listen(fn ($q) => $this->queries[] = $q->sql);
    $this->queries = [];

    $result = (new DataCheck)->run([
        'relations' => [['table' => $evil, 'column' => 'zone_id', 'target' => 'zones', 'targetKey' => 'id'], ['table' => 'lots', 'column' => 'nope', 'target' => 'zones', 'targetKey' => 'id']],
        'columns' => [['table' => 'lots', 'column' => $evil], ['table' => ['lots'], 'column' => 'discount']],
        'values' => [['table' => 'nope', 'column' => 'status', 'value' => 1], ['table' => 'lots', 'column' => 'status', 'value' => ['x']]],
    ]);

    expect(array_column($result['relations'], 'error'))->toBe(["unknown table {$evil}", 'unknown column lots.nope'])
        ->and(array_column($result['columns'], 'error'))->toBe(["unknown column lots.{$evil}", 'unknown table '])
        ->and(array_column($result['values'], 'error'))->toBe(['unknown table nope', 'expected a string or number value'])
        ->and(implode("\n", $this->queries))->not->toContain('drop table')->not->toContain('nope')
        ->and(Schema::hasTable('zones'))->toBeTrue();
});

test('never_writes', function () {
    $snapshot = fn () => [DB::table('lots')->orderBy('id')->get()->all(), DB::table('zones')->get()->all(), DB::table('migrations')->get()->all()];
    $before = $snapshot();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (! preg_match('/^\s*(select|pragma)\b/i', $q->sql)) {
            $writes[] = $q->sql;
        }
    });

    (new DataCheck)->run([
        'relations' => [['table' => 'lots', 'column' => 'zone_id', 'target' => 'zones', 'targetKey' => 'id']],
        'columns' => [['table' => 'lots', 'column' => 'discount']],
        'values' => [['table' => 'lots', 'column' => 'status', 'value' => 4]],
        'migrations' => true,
    ]);

    expect($writes)->toBe([])->and($snapshot())->toEqual($before);
});

test('command_is_mk_studio_data_check_and_prints_the_answer_as_one_json_line', function () {
    // MK Studio runs it by this name with the request as its one argument (`server/drift.ts`).
    $command = new SpecDataCheckCommand;
    $command->setLaravel(app());
    $out = new BufferedOutput;

    expect($command->getName())->toBe('mk-studio:data-check')
        ->and($command->run(new ArrayInput(['request' => '{"columns":[{"table":"lots","column":"discount"}],"migrations":true}']), $out))->toBe(0);
    $printed = $out->fetch();
    expect(substr_count(trim($printed), "\n"))->toBe(0)
        ->and(json_decode($printed, true))->toBe([
            'relations' => [],
            'columns' => [['table' => 'lots', 'column' => 'discount', 'nonNull' => 3]],
            'values' => [],
            'migrations' => ['2026_10_01_000000_create_zones_table', '2026_10_02_000000_create_lots_table'],
        ]);

    expect($command->run(new ArrayInput(['request' => 'not json']), new BufferedOutput))->toBe(2)
        ->and($command->run(new ArrayInput(['request' => '{"columns":"lots"}']), new BufferedOutput))->toBe(2);
});
