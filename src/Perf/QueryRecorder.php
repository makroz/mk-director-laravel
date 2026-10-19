<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * The queries a callback runs on a scratch database (`*_mk_studio_perf`), by database name rather than connection
 * name: while a run is on, the app's own connection name points at the scratch too, and both count.
 */
final class QueryRecorder
{
    private bool $listening = false;

    private bool $on = false;

    /** @var list<array{sql: string, ms: float}> */
    private array $log = [];

    /** @return list<array{sql: string, ms: float}> the queries `$run` ran, in order */
    public function record(callable $run): array
    {
        if (! $this->listening) {
            $this->listening = true;
            DB::listen(function (QueryExecuted $q): void {
                if ($this->on && preg_match(ScratchDatabase::NAME, (string) $q->connection->getDatabaseName())) {
                    $this->log[] = ['sql' => self::normalize($q->sql), 'ms' => (float) $q->time];
                }
            });
        }
        $this->log = [];
        $this->on = true;
        try {
            $run();
        } finally {
            $this->on = false;
        }

        return $this->log;
    }

    /** One shape per query: whitespace collapsed and `in (?, ?, ?)` as `in (?)` (bindings are already `?`). */
    public static function normalize(string $sql): string
    {
        return (string) preg_replace(['/\s+/', '/\(\s*\?(?:\s*,\s*\?)+\s*\)/'], [' ', '(?)'], trim($sql));
    }
}
