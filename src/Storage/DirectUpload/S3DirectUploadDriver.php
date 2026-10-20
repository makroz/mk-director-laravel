<?php

declare(strict_types=1);

namespace Mk\Director\Storage\DirectUpload;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Subida directa sobre un disk `s3`: S3, Cloudflare R2 y MinIO.
 *
 * Usa el cliente del propio disk (`getClient()`), así que credenciales,
 * endpoint y bucket son los de `filesystems.php`, sin una config aparte.
 *
 * 🔴 FIRMAR NO HABLA CON EL STORAGE. `createPresignedRequest` es un HMAC local:
 * el navegador recibe los links y sube cada parte directo al bucket.
 *
 * El `root` del disk se respeta (`path()`), igual que `Storage::url()`: sin eso
 * el objeto quedaría fuera de la carpeta donde después se lo busca.
 */
final class S3DirectUploadDriver implements MkDirectUploadDriver
{
    public function start(string $disk, string $key, string $mime): string
    {
        // El content type es el que validó el servidor, no el del navegador.
        return (string) $this->client($disk)->createMultipartUpload($this->object($disk, $key) + [
            'ContentType' => $mime,
        ])['UploadId'];
    }

    public function presignParts(string $disk, string $key, string $multipartId, int $parts, int $ttlSeconds): array
    {
        $client = $this->client($disk);
        $urls = [];

        for ($number = 1; $number <= $parts; $number++) {
            $command = $client->getCommand('UploadPart', $this->object($disk, $key) + [
                'UploadId' => $multipartId,
                'PartNumber' => $number,
            ]);
            $urls[] = (string) $client->createPresignedRequest($command, "+{$ttlSeconds} seconds")->getUri();
        }

        return $urls;
    }

    public function complete(string $disk, string $key, string $multipartId, array $etags): void
    {
        $parts = [];
        foreach (array_values($etags) as $i => $etag) {
            $parts[] = ['PartNumber' => $i + 1, 'ETag' => $etag];
        }

        $this->client($disk)->completeMultipartUpload($this->object($disk, $key) + [
            'UploadId' => $multipartId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    /**
     * Un multipart que ya no existe (`NoSuchUpload`: lo abortó la regla de 7
     * días del bucket, o un `complete()` ya lo unió) cuenta como abortado: es
     * el estado que se pedía. Sin esto la poda se trabaría para siempre en esa fila.
     */
    public function abort(string $disk, string $key, string $multipartId): void
    {
        try {
            $this->client($disk)->abortMultipartUpload($this->object($disk, $key) + ['UploadId' => $multipartId]);
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchUpload') {
                throw $e;
            }
        }
    }

    public function size(string $disk, string $key): int
    {
        return (int) $this->client($disk)->headObject($this->object($disk, $key))['ContentLength'];
    }

    public function head(string $disk, string $key, int $length): string
    {
        return (string) $this->client($disk)->getObject($this->object($disk, $key) + [
            'Range' => 'bytes=0-'.($length - 1),
        ])['Body'];
    }

    /** @return array{Bucket: string, Key: string} */
    private function object(string $disk, string $key): array
    {
        $adapter = $this->adapter($disk);

        return ['Bucket' => (string) $adapter->getConfig()['bucket'], 'Key' => $adapter->path($key)];
    }

    private function client(string $disk): S3Client
    {
        return $this->adapter($disk)->getClient();
    }

    private function adapter(string $disk): AwsS3V3Adapter
    {
        /** @var AwsS3V3Adapter */
        return Storage::disk($disk);
    }
}
