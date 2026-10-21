<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Models;

use App\Modules\PerfSlope\Http\Resources\EntryResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** `perf_items` through a hand Resource that loads each row's owner lazily: an N+1 the project's own code runs. */
class Entry extends Model
{
    protected $table = 'perf_items';

    protected $guarded = [];

    public $apiResource = EntryResource::class;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\PerfOwner::class, 'owner_id');
    }
}
