<?php

declare(strict_types=1);

namespace Mk\Director\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Dónde se guardan los archivos y cómo se arma su link.
 *
 * El paquete no conoce proveedores: conoce dos ROLES, `public` y `private`, y
 * la app decide qué disk de Laravel cumple cada uno
 * (`mk_director.storage.public_disk` / `private_disk`). Un disk `s3` cubre
 * Cloudflare R2, S3 y MinIO sin código; otro proveedor es otro disk.
 *
 * 🔴 ES EL ÚNICO LUGAR QUE ARMA EL LINK DE UN ARCHIVO GUARDADO. Un `->url()`
 * suelto sobre un disk privado devuelve un link que no abre (un bucket privado
 * no sirve nada sin firma) y el error recién se ve como una imagen rota. Acá el
 * disk privado recibe un link firmado que vence.
 */
final class MkStorage
{
    public const ROLES = ['public', 'private'];

    /** El nombre del disk que cumple el rol; sin configurar: `public` y `local`, lo de siempre. */
    public function diskName(string $role): string
    {
        $disk = match ($role) {
            'public' => config('mk_director.storage.public_disk') ?: 'public',
            'private' => config('mk_director.storage.private_disk') ?: 'local',
            default => throw new InvalidArgumentException(
                "Rol de storage desconocido '{$role}': sólo existen 'public' y 'private'."
            ),
        };

        return (string) $disk;
    }

    public function disk(string $role): Filesystem
    {
        return Storage::disk($this->diskName($role));
    }

    /** Privado = el disk del rol privado, o uno cuya config dice `visibility: private`. */
    public function isPrivate(string $disk): bool
    {
        return $disk === $this->diskName('private')
            || config("filesystems.disks.{$disk}.visibility") === 'private';
    }

    /**
     * El link de un archivo guardado. Un disk privado da uno firmado que vence
     * a los `$ttlSeconds` (default `mk_director.storage.signed_url_ttl`).
     *
     * Un path que ya es una URL absoluta se devuelve tal cual: hay filas viejas
     * que guardaron el link completo en vez del path.
     */
    public function url(string $disk, ?string $path, ?int $ttlSeconds = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if ($this->isPrivate($disk)) {
            $ttl = $ttlSeconds ?? (int) (config('mk_director.storage.signed_url_ttl') ?: 7200);

            return Storage::disk($disk)->temporaryUrl($path, now()->addSeconds($ttl));
        }

        return Storage::disk($disk)->url($path);
    }
}
