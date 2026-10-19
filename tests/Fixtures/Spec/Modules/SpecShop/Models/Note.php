<?php

declare(strict_types=1);

namespace App\Modules\SpecShop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A note about anything in the morph map: its `subject` is polymorphic (`subject_type` + `subject_id`). */
class Note extends Model
{
    protected $table = 'spec_notes';

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
