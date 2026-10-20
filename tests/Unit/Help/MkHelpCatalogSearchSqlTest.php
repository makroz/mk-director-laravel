<?php

declare(strict_types=1);

use Mk\Director\Help\MkHelpCatalog;
use Mk\Director\Help\Models\MkHelpArticle;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

/**
 * La búsqueda en PostgreSQL usa el índice `search` (tsvector `spanish`), no
 * `LIKE`. La búsqueda real, con stemming y tildes, se prueba en el consumer
 * contra Postgres; acá sólo se afirma qué SQL arma.
 *
 * Contra una conexión pgsql SIN servidor: `toSql()` nunca abre el PDO (un
 * `pretend()` sí lo abre, para citar los bindings en el log).
 */
uses(TestCase::class, UsesDatabase::class);

beforeEach(function () {
    $this->setUpDatabase();
    $this->capsule->addConnection([
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'never_connected',
        'username' => 'nobody',
        'password' => '',
    ], 'pgsql_compile_only');
    $this->capsule->getDatabaseManager()->setDefaultConnection('pgsql_compile_only');
});

afterEach(fn () => $this->tearDownDatabase());

test('on PostgreSQL the search matches the tsvector with spanish websearch_to_tsquery, by ts_rank', function () {
    $base = MkHelpArticle::query()->whereIn('audience', ['admin', 'business'])->limit(5);
    $query = (new ReflectionMethod(MkHelpCatalog::class, 'tsQuery'))->invoke(new MkHelpCatalog, $base, 'retiros');

    expect($query->getConnection()->getDriverName())->toBe('pgsql');

    $sql = strtolower($query->toSql());

    expect($sql)->toContain("search @@ websearch_to_tsquery('spanish', ?)")
        ->and($sql)->toContain("order by ts_rank(search, websearch_to_tsquery('spanish', ?)) desc, \"title\" asc")
        ->and($sql)->toContain("ts_headline('spanish', coalesce(body_text, body), websearch_to_tsquery('spanish', ?), ?)")
        ->and($sql)->toContain('"audience" in (?, ?)')
        ->and($sql)->toContain('limit 5')
        ->and($sql)->not->toMatch('/\slike\s/')
        // El orden de los bindings sigue al del SQL: select, where, order.
        ->and($query->getBindings())->toBe([
            'retiros',
            "StartSel=\x02,StopSel=\x03,MaxWords=25,MinWords=10,ShortWord=2,MaxFragments=1",
            'admin', 'business', 'retiros', 'retiros',
        ]);
});
