<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Mk\Director\Managers\ListManager;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\TestCase;

/**
 * `?sort=<column>` MUST END WITH THE PRIMARY KEY.
 *
 * Pre-fix, `?sort=status` produced `ORDER BY status DESC` and nothing else.
 * That is not a total order: rows tied on `status` come back in whatever
 * order the engine picks for each execution, and with LIMIT/OFFSET a row can
 * appear on page 1 AND page 2 while another never appears. PostgreSQL (the
 * engine of every current consumer) does reshuffle ties between executions;
 * SQLite happens not to, which is why this test pins the ORDER BY itself
 * instead of paginating — a pagination test here would stay green with the
 * bug alive.
 *
 * Measured in the consumers on 2026-10-13: mozzo `StockMovementController`
 * inserts 25-648 movements with the same `occurred_at`, and reto has 502 of
 * 509 members sharing `created_at` with another row.
 */
uses(TestCase::class, UsesDatabase::class);

// Only to give Eloquent a connection: the test reads the builder's ORDER BY
// and never queries.
beforeEach(function () {
    $this->setUpDatabase();
});

afterEach(function () {
    $this->tearDownDatabase();
});

final class SortTieBreakerItem extends Model
{
    protected $table = 'sort_tie_breaker_items';

    protected $fillable = ['status', 'name'];
}

final class SortTieBreakerCodeKeyed extends Model
{
    protected $table = 'sort_tie_breaker_codes';

    protected $primaryKey = 'code';

    protected $fillable = ['status'];
}

function sortOrders(array $query, Model $model): array
{
    $builder = ListManager::applySorting(Request::create('/x', 'GET', $query), $model->newQuery(), $model);

    return array_map(
        fn (array $order): string => $order['column'].' '.$order['direction'],
        $builder->getQuery()->orders,
    );
}

test('a sort by a non-unique column ends with the primary key', function (): void {
    expect(sortOrders(['sort' => 'status'], new SortTieBreakerItem))
        ->toBe(['status desc', 'id desc']);
});

test('the tie-breaker follows the direction of the last sort column', function (): void {
    expect(sortOrders(['sort' => 'status', 'dir' => 'asc'], new SortTieBreakerItem))
        ->toBe(['status asc', 'id asc']);

    expect(sortOrders(['sort' => 'name,-status', 'dir' => 'asc'], new SortTieBreakerItem))
        ->toBe(['name asc', 'status desc', 'id desc']);
});

test('the primary key is not added twice when the client already sorts by it', function (): void {
    expect(sortOrders(['sort' => 'status,id', 'dir' => 'asc'], new SortTieBreakerItem))
        ->toBe(['status asc', 'id asc']);
});

test('a custom primary key name is the tie-breaker', function (): void {
    expect(sortOrders(['sort' => 'status'], new SortTieBreakerCodeKeyed))
        ->toBe(['status desc', 'code desc']);
});

test('without a valid sort the order is still the primary key alone', function (): void {
    expect(sortOrders([], new SortTieBreakerItem))->toBe(['id desc']);
    expect(sortOrders(['sort' => 'not_a_column'], new SortTieBreakerItem))->toBe(['id desc']);
});
