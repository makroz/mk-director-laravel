<?php

declare(strict_types=1);

namespace Mk\Director\Help;

/** Un archivo del manual ya leído y validado por {@see MkHelpParser}. */
final readonly class MkHelpDocument
{
    /**
     * @param  list<string>  $screens
     * @param  list<string>  $related
     * @param  array<string, string>  $imagePaths  la ruta escrita en el cuerpo → el archivo
     * @param  list<string>  $sources  `<repo>:<ruta o glob>` del código que describe
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
        public array $imagePaths,
        public ?string $parentSlug,
        public string $hash,
        public array $sources = [],
    ) {}
}
