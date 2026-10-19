<?php

declare(strict_types=1);

namespace Mk\Director\Storage\DirectUpload;

/**
 * Lo que un proveedor de storage tiene que saber hacer para que el navegador
 * suba directo, sin pasar los bytes por la API. Hoy lo cumple
 * {@see S3DirectUploadDriver} (S3, Cloudflare R2, MinIO); otro proveedor es
 * otra implementación, elegida por el `driver` del disk en {@see MkDirectUpload}.
 *
 * `$disk` es el NOMBRE del disk de `filesystems.php`; `$key`, el path del
 * objeto relativo a ese disk (sin el `root`).
 */
interface MkDirectUploadDriver
{
    /** Abre la subida en partes y devuelve su id (en R2, 343 caracteres). */
    public function start(string $disk, string $key, string $mime): string;

    /**
     * Un link firmado por parte, en orden (parte N = índice N-1).
     *
     * @return list<string>
     */
    public function presignParts(string $disk, string $key, string $multipartId, int $parts, int $ttlSeconds): array;

    /** @param  list<string>  $etags  el ETag de cada parte, en orden */
    public function complete(string $disk, string $key, string $multipartId, array $etags): void;

    /** Descarta las partes subidas. */
    public function abort(string $disk, string $key, string $multipartId): void;

    /** El tamaño REAL del objeto guardado: el que declaró el navegador no vale. */
    public function size(string $disk, string $key): int;

    /** Los primeros `$length` bytes del objeto (un GET con Range), para mirar su firma. */
    public function head(string $disk, string $key, int $length): string;
}
