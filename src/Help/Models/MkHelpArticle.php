<?php

declare(strict_types=1);

namespace Mk\Director\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Carbon;
use Mk\Director\Help\MkHelpSync;

/**
 * Un artículo del manual, indexado desde su archivo por {@see MkHelpSync}.
 * La fuente es el archivo: nada de esta tabla llega de un request.
 *
 * Tabla: `mk_director.help.articles_table` (default `mk_help_articles`).
 *
 * @property string $id
 * @property string $slug
 * @property string $audience
 * @property string|null $ability INFORMATIVO: nunca esconde el artículo
 * @property string $title
 * @property string $body con las imágenes ya apuntando a su URL pública
 * @property list<array{url: string, width: int, height: int}>|null $images
 * @property list<string> $screens
 * @property list<string> $related
 * @property string|null $parent_slug
 * @property int $order
 * @property string $content_hash
 * @property Carbon $updated_at
 */
class MkHelpArticle extends EloquentModel
{
    use HasUuids;

    protected $fillable = [
        'slug',
        'audience',
        'ability',
        'title',
        'body',
        'images',
        'screens',
        'related',
        'parent_slug',
        'order',
        'content_hash',
    ];

    /** `search` es la columna generada de Postgres: no sale en ninguna respuesta. */
    protected $hidden = ['search'];

    protected $casts = [
        'images' => 'array',
        'screens' => 'array',
        'related' => 'array',
        'order' => 'integer',
    ];

    public function getTable(): string
    {
        return $this->table ?? config('mk_director.help.articles_table', 'mk_help_articles');
    }
}
