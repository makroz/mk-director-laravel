<?php

declare(strict_types=1);

namespace Mk\Director\Storage\DirectUpload;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mk\Director\Enums\MkUploadStatus;
use Mk\Director\Models\MkUpload;
use Mk\Director\Storage\MkFileInspector;
use Mk\Director\Storage\MkStorage;
use Mk\Director\Traits\HasMkMedia;
use Throwable;

/**
 * Subida directa: el navegador sube el archivo en partes a links firmados del
 * storage y la API sólo abre, cierra y registra. Los bytes nunca pasan por PHP.
 *
 *   start()    → valida contra el perfil, arma la key, abre el multipart, firma
 *                un link por parte y deja la fila Started.
 *   complete() → une las partes con sus ETags y RE-REVISA lo guardado (bytes
 *                reales, firma, megapíxeles): Completed, o borrado y 422.
 *   claim()    → para el consumer: la subida Completed de ESE dueño, bloqueada
 *                (la usa {@see HasMkMedia::attachUpload()}).
 *   abort()    → descarta las partes o el objeto: Aborted.
 *
 * 🔴 TODA BÚSQUEDA POR ID VA FILTRADA POR DUEÑO. El id llega del cliente; sin
 * el filtro, cualquiera con sesión completa, cancela o se adjunta la subida de
 * otro. Un id ajeno responde 404, igual que uno que no existe.
 *
 * 🔴 LA KEY LA ARMA EL SERVIDOR: `{prefijo}/{Y}/{m}/{uuid}.{ext}`, con la
 * extensión que el PERFIL le asigna a ese MIME. Del nombre del cliente sólo se
 * mira la extensión, para rechazar; nunca se copia nada a la key, así que un
 * `../../x/../passwd.jpg` no puede escribir fuera del prefijo.
 */
final class MkDirectUpload
{
    /** Bytes para la firma: WebP y la marca de `ftyp` terminan en el byte 12. */
    private const SIGNATURE_BYTES = 16;

    /** La cabecera de una imagen suele estar en los primeros 64 KB... */
    private const IMAGE_HEADER_BYTES = 65536;

    /** ...salvo un JPEG con un EXIF grande, que empuja el marcador SOF más allá. */
    private const IMAGE_HEADER_MAX_BYTES = 1048576;

    public function __construct(
        private readonly MkStorage $storage,
        private readonly MkFileInspector $inspector,
    ) {}

    /**
     * @return array{upload: MkUpload, part_size: int, part_urls: list<string>, expires_at: Carbon}
     *
     * @throws MkUploadException 422 si el perfil no toma ese tipo o ese tamaño (antes de tocar el storage)
     */
    public function start(Model $owner, string $profile, string $filename, int $bytes, string $mime, ?string $keyPrefix = null): array
    {
        $rules = $this->storage->profile($profile);
        $mime = strtolower(trim($mime));

        if (! $rules->accepts($mime, pathinfo($filename, PATHINFO_EXTENSION))) {
            throw MkUploadException::type($profile, $mime);
        }

        if ($bytes > $rules->maxBytes) {
            throw MkUploadException::tooLarge($profile, $rules->maxBytes);
        }

        $disk = $this->storage->diskName($rules->role);
        $driver = $this->driver($disk);

        $id = (string) Str::uuid();
        $prefix = trim($keyPrefix ?? $rules->prefix, '/');
        $key = ($prefix === '' ? '' : $prefix.'/').now()->format('Y/m').'/'.$id.'.'.$rules->extensionFor($mime);

        $ttl = (int) (config('mk_director.storage.upload_ttl') ?: 21600);
        $parts = max(1, (int) ceil($bytes / $this->partSize()));
        $multipartId = $driver->start($disk, $key, $mime);
        $partUrls = $driver->presignParts($disk, $key, $multipartId, $parts, $ttl);

        $upload = MkUpload::query()->create([
            'id' => $id,
            'disk' => $disk,
            'key' => $key,
            'multipart_id' => $multipartId,
            'parts' => $parts,
            'profile' => $profile,
            // El MIME declarado (ya validado) hasta el complete(), que lo
            // cambia por el de la firma. Los bytes declarados no se guardan:
            // `bytes` sólo tiene los que midió el servidor.
            'mime' => $mime,
            'status' => MkUploadStatus::Started,
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return [
            'upload' => $upload,
            'part_size' => $this->partSize(),
            'part_urls' => $partUrls,
            'expires_at' => $upload->expires_at,
        ];
    }

    /**
     * Une las partes (ETags en el orden de las partes) y revisa lo GUARDADO,
     * porque lo que declaró el navegador al abrir la subida no vale nada:
     *
     *   1. bytes reales (HEAD) contra el máximo del perfil → ERR_UPLOAD_TOO_LARGE;
     *   2. la firma de los primeros bytes tiene que ser un MIME del perfil → ERR_UPLOAD_TYPE;
     *   3. en un perfil de imágenes, ancho × alto de la cabecera contra
     *      `maxMegapixels` → ERR_UPLOAD_DIMENSIONS (sin cabecera legible: ERR_UPLOAD_TYPE).
     *
     * La fila queda con los bytes, el MIME y las medidas MEDIDOS. Si algo falla
     * el objeto se borra y la fila queda Aborted: nunca una Completed sin revisar.
     *
     * 🔴 DOS complete() A LA VEZ. Antes de tocar el storage la fila se TOMA con
     * un UPDATE condicional (`WHERE status = Started` → Completing): la base lo
     * hace atómico en cualquier motor, así que de dos requests simultáneos uno
     * sólo cambia la fila y el otro recibe 404 sin llegar al storage. Es el
     * mismo efecto que un `lockForUpdate`, sin dejar una transacción abierta
     * mientras se habla con el storage (unir las partes puede tardar).
     *
     * Si unir las partes falla (un ETag mal), la fila vuelve a Started y el
     * cliente puede reintentar: las partes siguen en el storage. Si falla una
     * revisión, o el storage al medir, el objeto ya unido no se confía: se borra.
     *
     * ⚠️ Un proceso que muere a mitad de camino deja la fila en Completing. La
     * limpia `mk:uploads:prune` cuando venció y nadie la tocó hace una hora
     * ({@see discard()}: aborta el multipart y borra el objeto).
     *
     * @param  list<string>  $etags
     *
     * @throws MkUploadException 404 si no es del dueño o ya la tomó otro complete();
     *                           422 si venció, faltan/sobran ETags o lo guardado no pasa una revisión
     */
    public function complete(Model $owner, string $uploadId, array $etags): MkUpload
    {
        $upload = $this->find($owner, $uploadId, [MkUploadStatus::Started])->first() ?? throw MkUploadException::notFound();

        if ($upload->expires_at->isPast()) {
            throw MkUploadException::state();
        }

        if (count($etags) !== $upload->parts) {
            throw MkUploadException::parts($upload->parts, count($etags));
        }

        $driver = $this->driver($upload->disk);

        $claimed = $this->find($owner, $uploadId, [MkUploadStatus::Started])
            ->update(['status' => MkUploadStatus::Completing->value]);

        if ($claimed !== 1) {
            throw MkUploadException::notFound();
        }

        $upload->forceFill(['status' => MkUploadStatus::Completing])->syncOriginal();

        try {
            $driver->complete($upload->disk, $upload->key, $upload->multipart_id, array_values($etags));
        } catch (Throwable $e) {
            $upload->update(['status' => MkUploadStatus::Started]);

            throw $e;
        }

        try {
            $measured = $this->inspect($driver, $upload);
        } catch (Throwable $e) {
            rescue(fn () => Storage::disk($upload->disk)->delete($upload->key));
            $upload->update(['status' => MkUploadStatus::Aborted]);

            throw $e;
        }

        $upload->update($measured + ['status' => MkUploadStatus::Completed]);

        return $upload;
    }

    /**
     * Cancela: una Started pierde sus partes; una Completed que nadie adjuntó,
     * su objeto. Una Attached ya es media y no se toca (404).
     *
     * @throws MkUploadException 404
     */
    public function abort(Model $owner, string $uploadId): void
    {
        $upload = $this->find($owner, $uploadId, [MkUploadStatus::Started, MkUploadStatus::Completed])->first()
            ?? throw MkUploadException::notFound();

        $this->discard($upload);

        $upload->update(['status' => MkUploadStatus::Aborted]);
    }

    /**
     * Lo que una subida dejó en el storage, fuera: el multipart de una Started,
     * el objeto de una Completed, y las DOS cosas de una Completing (el proceso
     * que la tomó pudo morir antes o después de unir las partes). No cambia la
     * fila ni mira el dueño: eso es de quien llama ({@see abort()} y
     * `mk:uploads:prune`). Lo que ya no existe no es un error.
     */
    public function discard(MkUpload $upload): void
    {
        if ($upload->status !== MkUploadStatus::Completed) {
            $this->driver($upload->disk)->abort($upload->disk, $upload->key, $upload->multipart_id);
        }

        if ($upload->status !== MkUploadStatus::Started) {
            Storage::disk($upload->disk)->delete($upload->key);
        }
    }

    /**
     * La subida Completed de ESTE dueño (y de ese perfil, o de uno de esos
     * perfiles, si se pide), con `lockForUpdate`. Llamala dentro de una
     * transacción: es lo que impide que dos requests simultáneos adjunten la
     * misma subida dos veces.
     *
     * @param  string|list<string>|null  $profile
     *
     * @throws MkUploadException 404 si no existe, es de otro, no está Completed o es de otro perfil
     */
    public function claim(Model $owner, string $uploadId, string|array|null $profile = null): MkUpload
    {
        $query = $this->find($owner, $uploadId, [MkUploadStatus::Completed]);

        if ($profile !== null) {
            $query->whereIn('profile', (array) $profile);
        }

        return $query->lockForUpdate()->first() ?? throw MkUploadException::notFound();
    }

    /**
     * @param  list<MkUploadStatus>  $statuses
     * @return Builder<MkUpload>
     */
    private function find(Model $owner, string $uploadId, array $statuses)
    {
        // Un id que no es uuid no existe. Además Postgres rechaza compararlo
        // contra una columna uuid y el 404 saldría 500.
        if (! Str::isUuid($uploadId)) {
            throw MkUploadException::notFound();
        }

        return MkUpload::query()
            ->ownedBy($owner)
            ->whereKey($uploadId)
            ->whereIn('status', array_map(static fn (MkUploadStatus $s): int => $s->value, $statuses));
    }

    /** El driver de subida directa según el `driver` del disk. */
    private function driver(string $disk): MkDirectUploadDriver
    {
        $driver = config("filesystems.disks.{$disk}.driver");

        if ($driver === 's3') {
            return app(S3DirectUploadDriver::class);
        }

        throw new InvalidArgumentException(
            "El disk '{$disk}' (driver '".(is_string($driver) ? $driver : 'sin configurar')."') no admite subidas "
            .'directas: hace falta un disk `s3` (S3, Cloudflare R2 o MinIO) en el rol del perfil.'
        );
    }

    private function partSize(): int
    {
        return (int) (config('mk_director.storage.part_size') ?: 8 * 1024 * 1024);
    }

    /**
     * Mide el objeto ya unido contra su perfil (ver {@see complete()}).
     *
     * @return array{bytes: int, mime: string, width: int|null, height: int|null}
     *
     * @throws MkUploadException 422 en la primera revisión que no pasa
     */
    private function inspect(MkDirectUploadDriver $driver, MkUpload $upload): array
    {
        $rules = $this->storage->profile($upload->profile);

        $bytes = $driver->size($upload->disk, $upload->key);
        if ($bytes > $rules->maxBytes) {
            throw MkUploadException::tooLarge($rules->name, $rules->maxBytes);
        }

        $mime = $this->inspector->sniff($driver->head($upload->disk, $upload->key, self::SIGNATURE_BYTES));
        if ($mime === null || ! isset($rules->mimes[$mime])) {
            throw MkUploadException::storedType($rules->name);
        }

        if (! $rules->isImage()) {
            return ['bytes' => $bytes, 'mime' => $mime, 'width' => null, 'height' => null];
        }

        $size = $this->inspector->dimensions($driver->head($upload->disk, $upload->key, self::IMAGE_HEADER_BYTES));
        if ($size === null && $bytes > self::IMAGE_HEADER_BYTES) {
            $size = $this->inspector->dimensions($driver->head($upload->disk, $upload->key, self::IMAGE_HEADER_MAX_BYTES));
        }

        if ($size === null) {
            throw MkUploadException::unreadableImage();
        }

        if ($rules->maxMegapixels * 1_000_000 < $size['width'] * $size['height']) {
            throw MkUploadException::dimensions($rules->name, (int) $rules->maxMegapixels);
        }

        return ['bytes' => $bytes, 'mime' => $mime] + $size;
    }
}
