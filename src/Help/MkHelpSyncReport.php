<?php

declare(strict_types=1);

namespace Mk\Director\Help;

/** Lo que hizo (o haría, en `--dry-run`) una corrida de {@see MkHelpSync}. */
final readonly class MkHelpSyncReport
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $unknownScreens  `slug: pantalla` que no está en `screens.yaml`
     * @param  list<string>  $uncoveredScreens  las pantallas de `screens.yaml` que ningún artículo lista
     */
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public int $unchanged = 0,
        public int $imagesUploaded = 0,
        public array $errors = [],
        public array $unknownScreens = [],
        public array $uncoveredScreens = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [] || $this->unknownScreens !== [];
    }
}
