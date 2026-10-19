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
use Mk\Director\Storage\MkStorage;
use Mk\Director\Traits\HasMkMedia;

/**
 * Subida directa: el navegador sube el archivo en partes a links firmados del
 * storage y la API sólo abre, cierra y registra. Los bytes nunca pasan por PHP.
 *
 *   start()    → valida contra el perfil, arma la key, abre el multipart, firma
 *                un link por parte y deja la fila Started.
 *   complete() → une las partes con sus ETags: Completed.
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
    public function __construct(private readonly MkStorage $storage) {}

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
        $multipartId = $driver->start($disk, $key, $mime);
        $partUrls = $driver->presignParts($disk, $key, $multipartId, $this->partsFor($bytes), $ttl);

        $upload = MkUpload::query()->create([
            'id' => $id,
            'disk' => $disk,
            'key' => $key,
            'multipart_id' => $multipartId,
            'profile' => $profile,
            'mime' => $mime,
            'bytes' => $bytes,
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
     * Une las partes, con los ETags en el orden de las partes.
     *
     * @param  list<string>  $etags
     *
     * @throws MkUploadException 404 si no es del dueño; 422 si venció, no está Started o faltan/sobran ETags
     */
    public function complete(Model $owner, string $uploadId, array $etags): MkUpload
    {
        $upload = $this->find($owner, $uploadId, [MkUploadStatus::Started])->first() ?? throw MkUploadException::notFound();

        if ($upload->expires_at->isPast()) {
            throw MkUploadException::state();
        }

        $expected = $this->partsFor((int) $upload->bytes);
        if (count($etags) !== $expected) {
            throw MkUploadException::parts($expected, count($etags));
        }

        $this->driver($upload->disk)->complete($upload->disk, $upload->key, $upload->multipart_id, array_values($etags));

        $upload->update(['status' => MkUploadStatus::Completed]);

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

        if ($upload->status === MkUploadStatus::Started) {
            $this->driver($upload->disk)->abort($upload->disk, $upload->key, $upload->multipart_id);
        } else {
            Storage::disk($upload->disk)->delete($upload->key);
        }

        $upload->update(['status' => MkUploadStatus::Aborted]);
    }

    /**
     * La subida Completed de ESTE dueño (y de ese perfil, si se pide), con
     * `lockForUpdate`. Llamala dentro de una transacción: es lo que impide que
     * dos requests simultáneos adjunten la misma subida dos veces.
     *
     * @throws MkUploadException 404 si no existe, es de otro, no está Completed o es de otro perfil
     */
    public function claim(Model $owner, string $uploadId, ?string $profile = null): MkUpload
    {
        $query = $this->find($owner, $uploadId, [MkUploadStatus::Completed]);

        if ($profile !== null) {
            $query->where('profile', $profile);
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
     * ponytail: las partes se recalculan con el `part_size` ACTUAL; cambiarlo con
     * subidas Started en vuelo (hasta `upload_ttl`) las hace fallar con
     * ERR_UPLOAD_PARTS. Si eso llega a importar, guardar las partes en la fila.
     */
    private function partsFor(int $bytes): int
    {
        return max(1, (int) ceil($bytes / $this->partSize()));
    }
}
