<?php

declare(strict_types=1);

namespace App\Modules\PerfShop\Models;

use Illuminate\Database\Eloquent\Model;

/** `perf_items`: the module's one table. Its `owner_id` points at `perf_owners`, a table outside the module. */
class Item extends Model
{
    protected $table = 'perf_items';

    protected $guarded = [];
}
