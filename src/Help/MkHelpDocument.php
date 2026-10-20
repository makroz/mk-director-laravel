<?php

declare(strict_types=1);

namespace Mk\Director\Help;

/** Un archivo del manual ya leído y validado por {@see MkHelpParser}. */
final readonly class MkHelpDocument
{
    /**
     * @param  list<string>  $screens
     * @param  list<string>  $related
     */
    public function __construct(
        public string $slug,
        public string $audience,
        public string $title,
        public int $order,
        public ?string $ability,
        public array $screens,
        public array $related,
        public string $body,
        public ?string $parentSlug,
        public string $hash,
    ) {}
}
