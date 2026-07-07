<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Enums\FixedStatus;

/**
 * Ability model — `resource.action` style, e.g. `users.view`.
 */
class Ability extends Model
{
    protected $table = 'abilities';

    protected $fillable = [
        'name',
        'description',
        'is_fixed',
    ];

    protected $casts = [
        'is_fixed' => FixedStatus::class,
    ];
}
