<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Managers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Mk\Director\Managers\ListManager;
use Mk\Director\Managers\SearchManager;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

/**
 * Search and the `like` filter must be case-insensitive on PostgreSQL.
 *
 * A plain `where($col, 'like', …)` is case-insensitive on MySQL (ci
 * collations) but case-sensitive on PostgreSQL, so on the PostgreSQL
 * consumers searching "juan" never found "Juan". whereLike() compiles to
 * ILIKE there and to LIKE on MySQL/SQLite.
 *
 * The SQL is compiled against an unconnected PostgreSQL connection: no
 * server is needed, and toSql() never opens the PDO.
 */
uses(TestCase::class, UsesDatabase::class);

class CaseSearchOwner extends Model
{
    protected $table = 'owners';

    protected $connection = 'pgsql_compile_only';
}

class CaseSearchItem extends Model
{
    protected $table = 'items';

    protected $connection = 'pgsql_compile_only';

    public function owner(): BelongsTo
    {
        return $this->belongsTo(CaseSearchOwner::class, 'owner_id');
    }
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->capsule->addConnection([
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'never_connected',
        'username' => 'nobody',
        'password' => '',
    ], 'pgsql_compile_only');
});

afterEach(function () {
    $this->tearDownDatabase();
});

function caseSearchCall(string $method, mixed ...$args): mixed
{
    return (new \ReflectionMethod(ListManager::class, $method))->invoke(null, ...$args);
}

function caseSearchSql(Builder $query): string
{
    return strtolower($query->toSql());
}

test('?q= search compiles to ILIKE on PostgreSQL, on columns and relation columns', function () {
    $request = Request::create('/items', 'GET', ['q' => 'Juan']);
    $query = CaseSearchItem::query();

    caseSearchCall('applySearch', $request, $query, ['name', 'email', 'owner.name']);

    $sql = caseSearchSql($query);
    expect(substr_count($sql, ' ilike '))->toBe(3)
        ->and($sql)->not->toMatch('/\slike\s/');
});

test('the like filter operator compiles to ILIKE on PostgreSQL', function () {
    $query = CaseSearchItem::query();

    caseSearchCall('applyFilterOperator', $query, 'name', 'like', 'Juan');

    expect(caseSearchSql($query))->toContain('"name"::text ilike ?')
        ->and($query->getBindings())->toBe(['%Juan%']);
});

test('SearchManager with the default LikeSearchStrategy compiles to ILIKE on PostgreSQL', function () {
    $query = CaseSearchItem::query();

    (new SearchManager)->search($query, 'juan, ana', ['name', 'email']);

    $sql = caseSearchSql($query);
    expect(substr_count($sql, ' ilike '))->toBe(4)
        ->and($sql)->not->toMatch('/\slike\s/');
});

test('the wildcard escape survives the switch to whereLike', function () {
    $query = CaseSearchItem::query();

    caseSearchCall('applyFilterOperator', $query, 'name', 'like', '50%');

    expect($query->getBindings())->toBe(['%50\\%%']);
});
