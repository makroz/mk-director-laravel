<?php

declare(strict_types=1);

namespace Mk\Director\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mk\Director\Enums\MkMediaKind;
use Mk\Director\Models\MkMedia;
use RuntimeException;

/**
 * HasMkMedia — adjunta N piezas de media a cualquier modelo del consumer.
 *
 * Spec: Comunicaciones Fase 1, PR 1.
 *
 * 🔴 POSTGRES — EL PK DEL MODELO DUEÑO DEBE SER `string`, NO `uuid` NATIVO
 * -----------------------------------------------------------------------
 * `mk_media.mediable_id` es `string` a propósito: soporta consumers con PKs
 * uuid Y bigint. En Postgres, de tipado estricto, `withCount`/`whereHas`/`has`
 * comparan COLUMNA CON COLUMNA (`tu_tabla.id = mk_media.mediable_id`). Si el PK
 * del modelo dueño es `uuid` NATIVO, eso es `uuid = varchar` y pgsql se niega
 * ("operator does not exist"). Tipá el PK como `$table->string('id', 36)`
 * (`HasUuids` genera el uuid igual). MySQL/SQLite no distinguen tipos y esconden
 * el problema — lo caza `mk:security-lint`.
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
     * Adjunta un archivo SUBIDO: lo guarda en el disk y crea la fila.
     *
     * Es el complemento de {@see attachMedia()}, que recibe atributos ya
     * resueltos. Sin este método, cada consumer tiene que escribir el mismo
     * pipeline —guardar, deducir el `kind`, leer mime, tamaño y dimensiones—
     * y cada copia se equivoca distinto. El piloto de RETO lo escribió una vez
     * y quedó claro que no tenía nada de RETO adentro.
     *
     * 🔴 EL `kind` SE DEDUCE DEL MIME REAL, NO DE LA EXTENSIÓN.
     * `getMimeType()` de Symfony lo infiere del CONTENIDO del archivo, no del
     * nombre. Confiar en la extensión significa que un `.jpg` renombrado entra
     * como imagen y después no se puede mostrar — y, peor, que la validación
     * de tamaño que el consumer aplicó "a las imágenes" no fue la que
     * correspondía.
     *
     * 🔴 `duration` QUEDA EN NULL PARA VIDEO, A PROPÓSITO.
     * Leer la duración exige ffprobe o una librería de parsing de contenedores,
     * y el paquete no va a arrastrar esa dependencia para un dato accesorio.
     * La columna existe para que el consumer que lo necesite la llene; devolver
     * un cero fingido sería peor que un null honesto.
     *
     * @param  string|null  $disk  null = el disk default de la app.
     *
     * @throws InvalidArgumentException si el mime no es imagen ni video. Un
     *                                  embed NO se sube: no tiene archivo propio.
     */
    public function attachUploadedFile(
        UploadedFile $file,
        string $collection = 'default',
        ?string $disk = null,
        ?int $position = null,
    ): MkMedia {
        $disk ??= config('filesystems.default');

        // 🔴 UN DISK NULL NO SE GUARDA NUNCA. La columna `disk` es lo que usa
        // `MkMedia::getUrlAttribute()` para resolver la URL: una fila con disk
        // null apunta a un archivo que existe pero que nadie puede pedir, y el
        // síntoma aparece recién en pantalla, como una imagen rota. Mejor
        // explotar acá, con el motivo escrito.
        if (! is_string($disk) || $disk === '') {
            throw new InvalidArgumentException(
                'No hay disk donde guardar: pasá uno explícito o configurá '
                .'`filesystems.default`. Guardar la fila con `disk` null produce media '
                .'cuya URL no resuelve, y el error recién se ve como una imagen rota.'
            );
        }

        $mime = $file->getMimeType() ?? 'application/octet-stream';

        $kind = match (true) {
            str_starts_with($mime, 'image/') => MkMediaKind::Image,
            str_starts_with($mime, 'video/') => MkMediaKind::Video,
            default => throw new InvalidArgumentException(
                "No se puede adjuntar un archivo de tipo '{$mime}': `mk_media` sólo guarda "
                .'imágenes y videos. Un enlace externo se adjunta como embed, sin archivo.'
            ),
        };

        $path = $file->store($collection, ['disk' => $disk]);

        if ($path === false) {
            throw new RuntimeException(
                "No se pudo guardar el archivo en el disk '{$disk}'. La fila de `mk_media` NO "
                .'se creó: una fila que apunta a un archivo inexistente es peor que no tenerla.'
            );
        }

        $attributes = [
            'kind' => $kind,
            'disk' => $disk,
            'path' => $path,
            'mime_type' => $mime,
            'size' => $file->getSize(),
            'collection' => $collection,
        ];

        if ($position !== null) {
            $attributes['position'] = $position;
        }

        // Las dimensiones se leen del archivo YA GUARDADO y no del temporal:
        // después de `store()` el temporal puede haberse movido, y en ese caso
        // `getimagesize()` sobre la ruta vieja falla en silencio devolviendo
        // false — dejando ancho y alto en null sin que nadie se entere.
        if ($kind === MkMediaKind::Image) {
            $attributes += $this->readImageDimensions($disk, $path);
        }

        return $this->attachMedia($attributes);
    }

    /**
     * Borra piezas de media puntuales de ESTE modelo, por id — fila y archivo.
     *
     * El caso de uso es la edición de una galería: el cliente marca una foto y
     * al guardar manda su id acá para que desaparezca. Complementa a
     * {@see attachUploadedFile()} (agregar) con la operación inversa (quitar).
     *
     * 🔴 SCOPEADO A `$this->media()` — ES LA DEFENSA CONTRA IDOR.
     * El id llega del cliente. Si se borrara con `MkMedia::whereIn('id', $ids)`
     * a secas, cualquiera podría mandar el id de la foto de OTRO post y borrarla:
     * un borrado horizontal entre dueños. Al filtrar por la relación
     * —que ya trae el `mediable_type` + `mediable_id` de este modelo— un id ajeno
     * simplemente no matchea y la llamada es un no-op silencioso. La seguridad no
     * depende de que el consumer se acuerde de validar la pertenencia: es
     * imposible por construcción.
     *
     * Devuelve cuántas piezas se borraron de verdad, para que el consumer pueda
     * distinguir "borré 3" de "el cliente mandó ids que no existían o no eran míos".
     *
     * @param  array<int, int|string>  $ids
     * @return int piezas efectivamente borradas
     */
    public function detachMedia(array $ids): int
    {
        // 🔴 CAST A INT — NO ES COSMÉTICO, ES POSTGRES. `mk_media.id` es bigint,
        // pero estos ids llegan del cliente y por multipart TODO viaja como
        // string ('5', no 5). En Postgres, de tipado estricto, `whereIn('id',
        // ['5'])` es `bigint = varchar` y se niega ("operator does not exist").
        // MySQL/SQLite coercionan y esconden el problema hasta producción. Se
        // castea acá, en el paquete, porque el paquete es dueño de que esta PK
        // sea siempre numérica; un id no numérico se vuelve 0 y no matchea nada.
        $ids = array_map(static fn ($id): int => (int) $id, $ids);

        if ($ids === []) {
            return 0;
        }

        $pieces = $this->media()->whereIn('id', $ids)->get();

        $pieces->each(fn (MkMedia $media) => $this->deleteMkMediaPiece($media));

        return $pieces->count();
    }

    /**
     * Borra una pieza: primero su archivo (si tiene uno propio), después la fila.
     *
     * Único lugar donde vive la secuencia archivo→fila. Lo comparten el borrado
     * del dueño ({@see bootHasMkMedia()}) y el borrado puntual
     * ({@see detachMedia()}): una segunda copia se desincronizaría —un embed no
     * tiene archivo, un path null tampoco— y dejaría archivos huérfanos o
     * intentaría borrar lo que no existe.
     */
    protected function deleteMkMediaPiece(MkMedia $media): void
    {
        if ($media->kind instanceof MkMediaKind
            && $media->kind->hasStoredFile()
            && $media->path !== null) {
            Storage::disk($media->disk ?: config('filesystems.default'))
                ->delete($media->path);
        }

        $media->delete();
    }

    /**
     * Ancho y alto de una imagen guardada, o array vacío si no se pudieron leer.
     *
     * Devolver vacío en vez de null-por-clave es deliberado: así las columnas
     * ni se tocan y conservan su default, en lugar de escribir null explícito
     * y sugerir que se midió y dio nada.
     *
     * @return array<string, int>
     */
    protected function readImageDimensions(string $disk, string $path): array
    {
        try {
            $contents = Storage::disk($disk)->get($path);

            if ($contents === null) {
                return [];
            }

            $size = @getimagesizefromstring($contents);
        } catch (\Throwable) {
            // Un SVG, un disk remoto que no responde o un archivo corrupto no
            // pueden tumbar la subida: las dimensiones son un dato accesorio y
            // la fila vale igual sin ellas.
            return [];
        }

        if ($size === false) {
            return [];
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
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

            $model->media()->get()->each(
                fn (MkMedia $media) => $model->deleteMkMediaPiece($media)
            );
        });
    }
}
