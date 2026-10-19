<?php

declare(strict_types=1);

namespace Mk\Director\Storage\DirectUpload;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Rechazo de {@see MkDirectUpload}. Laravel llama a `render()` y responde con el
 * mismo sobre que `BaseController::sendError()`: el front lee el motivo en
 * `__extraData.code` sin un catch por endpoint.
 *
 * 🔴 Una subida AJENA es 404, igual que una que no existe, nunca 403: un 403
 * diría «ese id existe y es de otro».
 */
final class MkUploadException extends RuntimeException
{
    public const ERR_UPLOAD_TYPE = 'ERR_UPLOAD_TYPE';

    public const ERR_UPLOAD_TOO_LARGE = 'ERR_UPLOAD_TOO_LARGE';

    public const ERR_UPLOAD_STATE = 'ERR_UPLOAD_STATE';

    public const ERR_UPLOAD_PARTS = 'ERR_UPLOAD_PARTS';

    public const ERR_UPLOAD_NOT_FOUND = 'ERR_UPLOAD_NOT_FOUND';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function type(string $profile, string $mime): self
    {
        return new self("El perfil '{$profile}' no acepta archivos de tipo '{$mime}' con esa extensión.", self::ERR_UPLOAD_TYPE, 422);
    }

    public static function tooLarge(string $profile, int $maxBytes): self
    {
        return new self("El archivo supera el máximo del perfil '{$profile}' ({$maxBytes} bytes).", self::ERR_UPLOAD_TOO_LARGE, 422);
    }

    public static function state(): self
    {
        return new self('La subida ya no se puede completar: venció, ya se completó o se canceló.', self::ERR_UPLOAD_STATE, 422);
    }

    public static function parts(int $expected, int $given): self
    {
        return new self("La subida tiene {$expected} partes y llegaron {$given} ETags.", self::ERR_UPLOAD_PARTS, 422);
    }

    public static function notFound(): self
    {
        return new self('Subida no encontrada.', self::ERR_UPLOAD_NOT_FOUND, 404);
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            '__extraData' => ['code' => $this->errorCode],
            'debugMsg' => [],
        ], $this->status);
    }
}
