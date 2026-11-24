<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mk\Director\Contracts\MkModuleServiceInterface;
use Mk\Director\Controllers\SmartController;
use Mk\Director\Tests\Concerns\BootsHttpApp;
use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Optimistic locking (`features.optimistic_locking`): two people editing the same
 * record must not overwrite each other silently.
 *
 * Without it, the second save wins and the first person's change disappears with a
 * 200 for both. With the flag on, `update()` compares the `If-Match` header against
 * the row's `updated_at`: a stale version answers 412 and writes nothing, a missing
 * header answers 428.
 *
 * The flag is opt-in (off by default) because turning it on makes every `update`
 * without `If-Match` fail with 428: consumers that call `api.put` directly would
 * break on a `composer update`.
 */
uses(MkLaravelTestCase::class, BootsHttpApp::class);

class LockedNote extends Model
{
    protected $table = 'locked_notes';

    protected $fillable = ['title'];
}

class LockedNoteWithoutTimestamps extends Model
{
    protected $table = 'locked_notes';

    protected $fillable = ['title'];

    public $timestamps = false;
}

/**
 * Plays "someone else saved meanwhile": between the early check and the write, it
 * changes the row and moves its `updated_at`, the way a concurrent request would.
 */
class SomeoneElseSavesMeanwhile implements MkModuleServiceInterface
{
    public function beforeUpdate(Request $request, string|int $id, array $input): array
    {
        Carbon::setTestNow(Carbon::now()->addSeconds(5));
        LockedNote::query()->whereKey($id)->update(['title' => 'Other person', 'updated_at' => Carbon::now()]);

        return $input;
    }

    public function beforeCreate(Request $request, array $input): array
    {
        return $input;
    }

    public function afterCreate(Request $request, Model $model, array $input): mixed
    {
        return null;
    }

    public function afterUpdate(Request $request, Model $model, array $input, string|int $id): mixed
    {
        return null;
    }

    public function beforeDelete(Request $request, Model $model, string|int $id): bool
    {
        return true;
    }

    public function afterDelete(Request $request, Model $model, string|int $id): mixed
    {
        return null;
    }

    public function beforeList(Request $request, $query)
    {
        return $query;
    }

    public function afterList(Request $request, $data, int $total)
    {
        return $data;
    }

    public function setExtraData(Request $request, $data): array
    {
        return [];
    }

    public function beforeSearch(Request $request, $query)
    {
        return $query;
    }

    public function beforeShow(Request $request, Model $model): Model
    {
        return $model;
    }
}

class LockedNoteFlagOn extends SmartController
{
    protected array $mkConfig = [
        'model' => LockedNote::class,
        'searchable' => ['title'],
        'features' => ['optimistic_locking' => true, 'auto_cache' => false],
    ];
}

class LockedNoteFlagOff extends SmartController
{
    protected array $mkConfig = [
        'model' => LockedNote::class,
        'searchable' => ['title'],
        'features' => ['optimistic_locking' => false, 'auto_cache' => false],
    ];
}

class LockedNoteNoOpinion extends SmartController
{
    protected array $mkConfig = [
        'model' => LockedNote::class,
        'searchable' => ['title'],
        'features' => ['auto_cache' => false],
    ];
}

class LockedNoteRace extends SmartController
{
    protected array $mkConfig = [
        'model' => LockedNote::class,
        'searchable' => ['title'],
        'service' => SomeoneElseSavesMeanwhile::class,
        'features' => ['optimistic_locking' => true, 'auto_cache' => false],
    ];
}

class LockedNoteWithoutTimestampsFlagOn extends SmartController
{
    protected array $mkConfig = [
        'model' => LockedNoteWithoutTimestamps::class,
        'searchable' => ['title'],
        'features' => ['optimistic_locking' => true, 'auto_cache' => false],
    ];
}

function bootLockedNotes(object $test, array $mkDirectorConfig = []): void
{
    $test->bootHttpApp(LockedNote::class, ['tenant' => ['enabled' => false], ...$mkDirectorConfig]);

    Schema::create('locked_notes', function ($t) {
        $t->increments('id');
        $t->string('title');
        $t->timestamps();
    });

    Route::middleware(['api'])->group(function () {
        foreach ([
            'on' => LockedNoteFlagOn::class,
            'off' => LockedNoteFlagOff::class,
            'default' => LockedNoteNoOpinion::class,
            'race' => LockedNoteRace::class,
            'no-timestamps' => LockedNoteWithoutTimestampsFlagOn::class,
        ] as $prefix => $controller) {
            Route::get("api/notes-{$prefix}/{id}", [$controller, 'show']);
            Route::patch("api/notes-{$prefix}/{id}", [$controller, 'update']);
        }
    });

    Carbon::setTestNow('2026-11-23 10:00:00');
    LockedNote::query()->create(['title' => 'Original']);
    // The edit happens later than the creation, like in real life.
    Carbon::setTestNow('2026-11-23 10:05:00');
}

/** The version the front sends: the row's `updated_at` exactly as the list serializes it. */
function versionSeenInTheList(): string
{
    return '"'.LockedNote::query()->find(1)->toArray()['updated_at'].'"';
}

afterEach(function () {
    Carbon::setTestNow();
    $this->tearDownHttpApp();
});

// ── Flag on ──────────────────────────────────────────────────────────────────

test('flag on: show answers the row version in the ETag header', function () {
    bootLockedNotes($this);

    $response = $this->httpGet('/api/notes-on/1');

    expect($response->getStatusCode())->toBe(200)
        ->and($response->headers->get('ETag'))->toBe(versionSeenInTheList());
});

test('flag on: an update with the current version is written and answers the new ETag', function () {
    bootLockedNotes($this);
    $current = versionSeenInTheList();

    $response = $this->httpSendPatch('/api/notes-on/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => $current]);

    expect($response->getStatusCode())->toBe(200, (string) $response->getContent())
        ->and(LockedNote::query()->find(1)->title)->toBe('Mine')
        ->and($response->headers->get('ETag'))->toBe(versionSeenInTheList())
        ->and($response->headers->get('ETag'))->not->toBe($current);
});

test('flag on: a stale version answers 412 ERR_PRECONDITION_FAILED and writes nothing', function () {
    bootLockedNotes($this);
    $stale = versionSeenInTheList();
    LockedNote::query()->whereKey(1)->update(['title' => 'Other person', 'updated_at' => Carbon::now()]);

    $response = $this->httpSendPatch('/api/notes-on/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => $stale]);
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(412)
        ->and($body['success'])->toBeFalse()
        ->and($body['__extraData']['code'])->toBe('ERR_PRECONDITION_FAILED')
        ->and(LockedNote::query()->find(1)->title)->toBe('Other person');
});

test('flag on: an update without If-Match answers 428 ERR_PRECONDITION_REQUIRED and writes nothing', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-on/1', ['title' => 'Mine']);
    $body = json_decode((string) $response->getContent(), true);

    expect($response->getStatusCode())->toBe(428)
        ->and($body['success'])->toBeFalse()
        ->and($body['__extraData']['code'])->toBe('ERR_PRECONDITION_REQUIRED')
        ->and(LockedNote::query()->find(1)->title)->toBe('Original');
});

test('flag on: If-Match * always passes', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-on/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => '*']);

    expect($response->getStatusCode())->toBe(200)
        ->and(LockedNote::query()->find(1)->title)->toBe('Mine');
});

test('flag on: a save that lands between the check and the write still answers 412', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-race/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => versionSeenInTheList()]);

    expect($response->getStatusCode())->toBe(412, (string) $response->getContent())
        ->and(LockedNote::query()->find(1)->title)->toBe('Other person');
});

test('flag on: a model without timestamps is a configuration error, not a silent pass', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-no-timestamps/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => '*']);

    expect($response->getStatusCode())->toBe(500)
        ->and(LockedNote::query()->find(1)->title)->toBe('Original');
});

// ── Flag off and defaults: nothing changes ───────────────────────────────────

test('flag off: update without If-Match is written, as before', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-off/1', ['title' => 'Mine']);

    expect($response->getStatusCode())->toBe(200)
        ->and(LockedNote::query()->find(1)->title)->toBe('Mine')
        ->and($response->headers->has('ETag'))->toBeFalse();
});

test('flag off: a stale If-Match is ignored, as before', function () {
    bootLockedNotes($this);

    $response = $this->httpSendPatch('/api/notes-off/1', ['title' => 'Mine'], ['HTTP_IF_MATCH' => '"2000-01-01T00:00:00.000000Z"']);

    expect($response->getStatusCode())->toBe(200)
        ->and(LockedNote::query()->find(1)->title)->toBe('Mine');
});

test('flag off: show answers no ETag', function () {
    bootLockedNotes($this);

    expect($this->httpGet('/api/notes-off/1')->headers->has('ETag'))->toBeFalse();
});

test('default: without global config or controller opinion, the flag is off', function () {
    bootLockedNotes($this);

    expect($this->httpSendPatch('/api/notes-default/1', ['title' => 'Mine'])->getStatusCode())->toBe(200);
});

test('global config on: a controller with no opinion inherits it', function () {
    bootLockedNotes($this, ['features' => ['optimistic_locking' => true]]);

    expect($this->httpSendPatch('/api/notes-default/1', ['title' => 'Mine'])->getStatusCode())->toBe(428);
});

test('global config on: a controller that turns it off wins', function () {
    bootLockedNotes($this, ['features' => ['optimistic_locking' => true]]);

    expect($this->httpSendPatch('/api/notes-off/1', ['title' => 'Mine'])->getStatusCode())->toBe(200);
});
