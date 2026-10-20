<?php

declare(strict_types=1);

namespace App\Modules\PerfGraph\Models;

use Illuminate\Database\Eloquent\Model;

/** `perf_tags`: two CHECKs a generic row breaks (a code format, exactly one owner). */
class Tag extends Model
{
    protected $table = 'perf_tags';

    protected $guarded = [];
}
