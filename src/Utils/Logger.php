<?php

declare(strict_types=1);

namespace Mk\Director\Utils;

use Illuminate\Support\Facades\Log;

class Logger
{
    public static function log($message, $data = [], $level = 'info', $dbLog = true)
    {
        Log::$level($message, $data);
        
        // In the future, this can sync with a DB log table specifically for MK-Director
    }
}
