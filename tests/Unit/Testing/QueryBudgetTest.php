<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Mk\Director\Testing\QueryBudget;
use Mk\Director\Tests\Concerns\UsesDatabase;
use Mk\Director\Tests\MkLaravelTestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * `QueryBudget::assertFlat()`: a screen that runs a query per row fails, a flat one passes. Each guard of the helper
 * (the comparison, the warm-up, the fixture control, the declared growth, the ceiling) has the test that turns red
 * without it.
 */
uses(MkLaravelTestCase::class, UsesDatabase::class);

final class QbPost extends Model
{
    protected $table = 'qb_posts';

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(QbComment::class, 'qb_post_id');
    }
}

final class QbComment extends Model
{
    protected $table = 'qb_comments';

    protected $guarded = [];

    public $timestamps = false;
}

beforeEach(function () {
    $this->setUpDatabase();
    $this->schema()->create('qb_posts', fn ($t) => [$t->id(), $t->string('title')]);
    $this->schema()->create('qb_comments', fn ($t) => [$t->id(), $t->foreignId('qb_post_id'), $t->string('body')]);
});

afterEach(fn () => $this->tearDownDatabase());

/** Adds `$n` posts with two comments each. */
function qbSeed(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        $post = QbPost::create(['title' => "Post {$i}"]);
        QbComment::insert([['qb_post_id' => $post->id, 'body' => 'a'], ['qb_post_id' => $post->id, 'body' => 'b']]);
    }
}

/** The list with its comment count in one query. */
$flat = fn () => QbPost::withCount('comments')->get();

/** The same list counting each post's comments with a query of its own: an N+1. */
$perRow = fn () => QbPost::all()->each(fn (QbPost $p) => $p->comments()->count());

test('a_flat_screen_passes_and_returns_the_counts', function () use ($flat) {
    expect(QueryBudget::assertFlat($flat, 'qbSeed', [8, 20]))->toBe([1 => 1, 8 => 1, 20 => 1])
        ->and(QbPost::count())->toBe(20)
        // The query log is left as it was: off.
        ->and(DB::connection()->logging())->toBeFalse();
});

test('a_query_per_row_fails_naming_the_shape_that_grew', function () use ($perRow) {
    $failure = null;
    try {
        QueryBudget::assertFlat($perRow, 'qbSeed', 8);
    } catch (AssertionFailedError $e) {
        $failure = $e->getMessage();
    }

    expect($failure)->toContain('1 row(s) → 2 queries, 8 row(s) → 9 queries')
        ->toContain('1 → 8× select count(*) as "aggregate" from "qb_comments"');
});

test('a_declared_growth_is_allowed_and_one_more_query_is_not', function () {
    // One extra query once there is more than one row: a second polymorphic type showing up among the rows.
    $screen = function () {
        $posts = QbPost::all();
        if ($posts->count() > 1) {
            DB::select('select 1');
        }

        return $posts;
    };

    expect(QueryBudget::assertFlat($screen, 'qbSeed', 8, growth: 1))->toBe([1 => 1, 8 => 2]);

    QbPost::query()->delete();
    expect(fn () => QueryBudget::assertFlat($screen, 'qbSeed', 8))->toThrow(AssertionFailedError::class);
});

test('a_cache_filled_on_the_first_call_does_not_hide_an_n_plus_one', function () use ($perRow) {
    // Ten queries the first call alone runs (a permissions cache): counted at 1 row, they would cover the 7 extra
    // queries of the N+1 at 8 rows (12 against 9). The warm-up call is not counted.
    $warm = false;
    $screen = function () use (&$warm, $perRow) {
        if (! $warm) {
            $warm = true;
            for ($i = 0; $i < 10; $i++) {
                DB::select('select 1');
            }
        }

        return $perRow();
    };

    expect(fn () => QueryBudget::assertFlat($screen, 'qbSeed', 8))->toThrow(AssertionFailedError::class, 'grows');
});

test('a_fixture_that_never_reaches_the_screen_fails', function () {
    // The screen lists another status than the one seeded: it costs the same at every size, with or without an N+1.
    $screen = fn () => QbPost::where('title', 'nobody')->get()->each(fn (QbPost $p) => $p->comments()->count());

    expect(fn () => QueryBudget::assertFlat($screen, 'qbSeed', 8))
        ->toThrow(AssertionFailedError::class, 'the fixture does not reach the screen');
});

test('the_ceiling_fails_a_flat_screen_over_it', function () use ($flat) {
    expect(fn () => QueryBudget::assertFlat($flat, 'qbSeed', 8, ceiling: 0))
        ->toThrow(AssertionFailedError::class, 'over the ceiling of 0');
});

test('a_size_of_one_or_less_is_refused', function () use ($flat) {
    expect(fn () => QueryBudget::assertFlat($flat, 'qbSeed', [1, 8]))->toThrow(InvalidArgumentException::class);
});
