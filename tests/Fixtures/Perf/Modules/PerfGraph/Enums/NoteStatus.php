<?php

declare(strict_types=1);

namespace App\Modules\PerfGraph\Enums;

/** A note's state: a column with a default, which the volume still spreads over every case. */
enum NoteStatus: int
{
    case Draft = 1;
    case Published = 2;
    case Archived = 3;
    case Hidden = 4;
}
