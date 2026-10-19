<?php

declare(strict_types=1);

namespace App\Modules\PerfSlope\Models;

use Illuminate\Database\Eloquent\Model;

/** `perf_items` seen by the slope test: each line shows its owner's name. */
class Line extends Model
{
    protected $table = 'perf_items';

    protected $guarded = [];
}
