<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ability model — `resource.action` style, e.g. `users.view`.
 */
class Ability extends Model
{
    protected $table = 'abilities';

    protected $fillable = [
        'name',
        'description',
    ];
}
