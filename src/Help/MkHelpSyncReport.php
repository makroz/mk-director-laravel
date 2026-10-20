<?php

declare(strict_types=1);

namespace Mk\Director\Help;

/** Lo que hizo (o haría, en `--dry-run`) una corrida de {@see MkHelpSync}. */
final readonly class MkHelpSyncReport
{
    /** @param  list<string>  $errors */
    public function __construct(
        public int $created,
        public int $updated,
        public int $deleted,
        public int $unchanged,
        public array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
