<?php

declare(strict_types=1);

namespace App\Modules\PerfGraph\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** `perf_notes`: its team is a parent outside the module, its author an owner or an item. */
class Note extends Model
{
    protected $table = 'perf_notes';

    protected $guarded = [];

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
