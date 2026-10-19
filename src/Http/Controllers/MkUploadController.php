<?php

declare(strict_types=1);

namespace Mk\Director\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mk\Director\Http\Requests\CompleteMkUploadRequest;
use Mk\Director\Http\Requests\StartMkUploadRequest;
use Mk\Director\Storage\DirectUpload\MkDirectUpload;

/**
 * Las 3 rutas de la subida directa, del usuario autenticado. Rutas opt-in:
 * `mk_director.storage.routes` (un grupo por scope).
 *
 * Responde con el sobre `{success, message, data}` que espera el cliente del
 * paquete. Los rechazos (`MkUploadException`) se renderizan solos: 422 con
 * `__extraData.code`, o 404 para una subida ajena.
 */
final class MkUploadController
{
    public function __construct(private readonly MkDirectUpload $uploads) {}

    /** `POST {prefix}/uploads` — `{profile, filename, bytes, mime}` → 201 `{upload_id, part_size, part_urls, expires_at}`. */
    public function start(StartMkUploadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $started = $this->uploads->start(
            $this->owner($request),
            (string) $data['profile'],
            (string) $data['filename'],
            (int) $data['bytes'],
            (string) $data['mime'],
        );

        return $this->ok([
            'upload_id' => $started['upload']->id,
            'part_size' => $started['part_size'],
            'part_urls' => $started['part_urls'],
            'expires_at' => $started['expires_at']->toIso8601ZuluString(),
        ], 'Subida iniciada', 201);
    }

    /** `POST {prefix}/uploads/{upload}/complete` — `{etags: [...]}` → `{upload_id, mime, bytes, width, height}`. */
    public function complete(CompleteMkUploadRequest $request, string $upload): JsonResponse
    {
        $done = $this->uploads->complete($this->owner($request), $upload, array_values($request->validated('etags')));

        return $this->ok([
            'upload_id' => $done->id,
            'mime' => $done->mime,
            'bytes' => $done->bytes,
            'width' => $done->width,
            'height' => $done->height,
        ], 'Archivo subido');
    }

    /** `DELETE {prefix}/uploads/{upload}` — cancela → 204. */
    public function abort(Request $request, string $upload): Response
    {
        $this->uploads->abort($this->owner($request), $upload);

        return response()->noContent();
    }

    /**
     * El dueño es SIEMPRE el usuario autenticado. Falla cerrado: un scope
     * configurado sin `mk.auth:{scope}` no puede dejar subidas sin dueño.
     */
    private function owner(Request $request): Model
    {
        $user = $request->user();

        abort_unless($user instanceof Model, 401);

        return $user;
    }

    /** @param  array<string, mixed>  $data */
    private function ok(array $data, string $message, int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
