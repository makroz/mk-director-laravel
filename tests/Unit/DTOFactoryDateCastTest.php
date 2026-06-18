<?php
declare(strict_types=1);


namespace Mk\Director\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

// --- Stubs ---

class DateModelStub extends Model
{
    protected $table = 'events';
    protected $fillable = ['name', 'starts_at', 'ends_at'];
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'timestamp',
    ];
}

// --- Tests ---

test('DTOFactory::makeFromArray casts datetime string to Carbon without crashing', function () {
    $data = [
        'name'      => 'Workshop',
        'starts_at' => '2026-01-15 09:00:00',
    ];

    // Before fix: throws "Class Carbon\DateTime not found"
    $result = \Mk\Director\DTOs\DTOFactory::makeFromArray($data, DateModelStub::class);

    expect($result)->toHaveKey('name', 'Workshop');
    expect($result['starts_at'])->toBeInstanceOf(Carbon::class);
});

test('DTOFactory::makeFromArray casts timestamp field to Carbon', function () {
    $data = [
        'name'    => 'Event',
        'ends_at' => '2026-12-31 23:59:59',
    ];

    $result = \Mk\Director\DTOs\DTOFactory::makeFromArray($data, DateModelStub::class);

    expect($result['ends_at'])->toBeInstanceOf(Carbon::class);
});

test('DTOFactory::makeFromArray returns null for empty datetime', function () {
    $data = [
        'name'      => 'Event',
        'starts_at' => '',
    ];

    $result = \Mk\Director\DTOs\DTOFactory::makeFromArray($data, DateModelStub::class);

    expect($result['starts_at'])->toBeNull();
});
