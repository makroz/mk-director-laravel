<?php

declare(strict_types=1);

namespace Mk\Director\Storage;

/**
 * Un perfil de subida de `mk_director.storage.profiles`: qué tipos entran, hasta
 * cuántos bytes, en qué rol de disk y bajo qué prefijo de key.
 *
 * Lo arma {@see MkStorage::profile()}. Un perfil con `maxMegapixels` es de
 * imágenes; sin él (null), de cualquier otra cosa.
 */
final readonly class MkUploadProfile
{
    /**
     * @param  array<string, list<string>>  $mimes  MIME => extensiones que se aceptan para ese MIME
     */
    public function __construct(
        public string $name,
        public string $role,
        public array $mimes,
        public int $maxBytes,
        public ?int $maxMegapixels,
        public string $prefix,
    ) {}

    /**
     * ¿El perfil toma este MIME con esta extensión? Las dos tienen que estar en
     * la lista Y corresponderse: un `.pdf` declarado `image/png` no entra.
     */
    public function accepts(string $mime, string $extension): bool
    {
        return in_array(strtolower($extension), $this->mimes[strtolower($mime)] ?? [], true);
    }

    /** La extensión con que el SERVIDOR guarda ese MIME: la primera de su lista. */
    public function extensionFor(string $mime): ?string
    {
        return $this->mimes[strtolower($mime)][0] ?? null;
    }

    public function isImage(): bool
    {
        return $this->maxMegapixels !== null;
    }
}
