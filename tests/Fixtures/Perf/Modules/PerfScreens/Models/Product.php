<?php

declare(strict_types=1);

namespace App\Modules\PerfScreens\Models;

use Illuminate\Database\Eloquent\Model;

/** `perf_items` again, seen by a module that filters, searches and sorts it (the screens test). */
class Product extends Model
{
    protected $table = 'perf_items';

    protected $fillable = ['owner_id', 'name', 'status'];
}
