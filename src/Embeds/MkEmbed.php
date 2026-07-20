<?php

declare(strict_types=1);

namespace Mk\Director\Embeds;

use Mk\Director\Enums\MkEmbedProvider;
use Mk\Director\Enums\MkMediaKind;

/**
 * MkEmbed — el resultado de reconocer una URL de un proveedor conocido.
 *
 * Spec: Comunicaciones Fase 1, PR 4.
 *
 * Es INMUTABLE y no toca la red: se construye a partir de la URL y, si hubo
 * oEmbed, se produce una copia enriquecida con `withOembed()`. Eso mantiene
 * separadas las dos etapas — reconocer (barato, determinista, testeable sin
 * mocks) y enriquecer (red, falible, cacheable).
 *
 * `toMediaAttributes()` es el puente con `mk_media`: devuelve exactamente las
 * columnas de una fila de kind Embed. El legacy no tenía nada de esto y
 * metía la URL cruda en una columna `url` que además usaba para otras tres
 * cosas según el `type`.
 */
final readonly class MkEmbed
{
    public function __construct(
        public MkEmbedProvider $provider,
        public string $providerId,
        public string $sourceUrl,
        public ?string $thumbnailUrl = null,
        public ?string $title = null,
        public ?string $authorName = null,
    ) {}

    /**
     * Copia enriquecida con la respuesta de un oEmbed.
     *
     * Los campos del payload son OPCIONALES en el estándar oEmbed y cada
     * proveedor manda los suyos, así que todo entra con `??` y nada se
     * asume. Un `thumbnail_url` ausente no invalida el embed: deja el que ya
     * había (el de convención de YouTube) o lo deja en null y la UI muestra
     * el link.
     *
     * @param  array<string, mixed>  $payload
     */
    public function withOembed(array $payload): self
    {
        return new self(
            provider: $this->provider,
            providerId: $this->providerId,
            sourceUrl: $this->sourceUrl,
            thumbnailUrl: self::stringOrNull($payload['thumbnail_url'] ?? null) ?? $this->thumbnailUrl,
            title: self::stringOrNull($payload['title'] ?? null) ?? $this->title,
            authorName: self::stringOrNull($payload['author_name'] ?? null) ?? $this->authorName,
        );
    }

    /**
     * Las columnas de una fila `mk_media` de kind Embed.
     *
     * `disk` y `path` quedan fuera a propósito: un embed NO tiene archivo
     * propio en ningún disk nuestro, y ésa es justamente la distinción que
     * {@see MkMediaKind::hasStoredFile()} hace explícita.
     *
     * @return array<string, mixed>
     */
    public function toMediaAttributes(): array
    {
        return [
            'kind' => MkMediaKind::Embed,
            'provider' => $this->provider,
            'provider_id' => $this->providerId,
            'source_url' => $this->sourceUrl,
            'thumbnail_url' => $this->thumbnailUrl,
            'meta' => array_filter([
                'title' => $this->title,
                'author_name' => $this->authorName,
            ], static fn ($value): bool => $value !== null) ?: null,
        ];
    }

    /**
     * Normaliza un valor del payload a string no vacío, o null.
     *
     * Los proveedores mandan `""` y `null` indistintamente para "no tengo
     * esto". Sin esta normalización, un `""` pisaría un thumbnail válido que
     * ya teníamos por convención.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
