<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Models\MkMedia;

/**
 * HasMkMedia — adjunta N piezas de media a cualquier modelo del consumer.
 *
 * Spec: Comunicaciones Fase 1, PR 1.
 *
 * Reemplaza el modelo 1-columna-1-path de `FileStoragePlugin` para los casos
 * de galería. Los dos conviven: para un avatar (un archivo, una columna) el
 * plugin sigue siendo lo correcto y más barato; para una publicación con N
 * fotos + un video + un embed de YouTube, hace falta esta tabla.
 *
 * USO
 * ---
 *   class Post extends Model
 *   {
 *       use HasMkMedia;
 *   }
 *
 *   $post->media;                          // toda la media, ordenada
 *   $post->mediaFrom('gallery');           // sólo una colección
 *   $post->attachMedia(['kind' => 'image', 'disk' => 's3', 'path' => '...']);
 *
 * El `boot` borra la media al borrar el dueño. Ver el docblock de
 * `bootHasMkMedia()`: no es cosmético, es lo que evita las filas huérfanas
 * que el legacy dejaba (borraba el contenido y los hijos quedaban colgando).
 */
trait HasMkMedia
{
    /**
     * Toda la media del modelo, ordenada por `position` y con `id` como
     * desempate — sin el segundo criterio, dos filas con la misma `position`
     * salen en un orden que depende del motor de base y el test que las
     * compare se vuelve flaky.
     */
    public function media(): MorphMany
    {
        return $this->morphMany(MkMedia::class, 'mediable')
            ->orderBy('position')
            ->orderBy('id');
    }

    /**
     * La media de UNA colección (e.g. 'gallery', 'cover').
     */
    public function mediaFrom(string $collection): MorphMany
    {
        return $this->media()->where('collection', $collection);
    }

    /**
     * Adjunta una pieza de media. Si no se pasa `position`, va al final de su
     * colección.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function attachMedia(array $attributes): MkMedia
    {
        $collection = $attributes['collection'] ?? 'default';

        if (! array_key_exists('position', $attributes)) {
            $attributes['position'] = (int) $this->media()
                ->where('collection', $collection)
                ->max('position') + 1;
        }

        $attributes['collection'] = $collection;

        /** @var MkMedia $media */
        $media = $this->media()->create($attributes);

        return $media;
    }

    /**
     * Al borrar el dueño, borra su media — filas y archivos.
     *
     * Una relación polimórfica NO puede tener FK, así que no hay
     * `cascadeOnDelete` que nos salve: si no lo hacemos a mano, las filas
     * quedan huérfanas para siempre. Es exactamente lo que pasa hoy en el
     * legacy, agravado porque ahí el delete además es físico.
     *
     * Si el modelo dueño usa SoftDeletes, un soft delete NO dispara esto
     * (Eloquent no emite `deleting` como borrado real en ese caso salvo
     * `forceDelete`), que es el comportamiento correcto: restaurar un post
     * tiene que devolverte sus fotos.
     */
    protected static function bootHasMkMedia(): void
    {
        static::deleting(function ($model): void {
            $usesSoftDeletes = method_exists($model, 'isForceDeleting');

            if ($usesSoftDeletes && ! $model->isForceDeleting()) {
                return;
            }

            $model->media()->get()->each(function (MkMedia $media): void {
                if ($media->kind instanceof MkMediaKind
                    && $media->kind->hasStoredFile()
                    && $media->path !== null) {
                    Storage::disk($media->disk ?: config('filesystems.default'))
                        ->delete($media->path);
                }

                $media->delete();
            });
        });
    }
}
