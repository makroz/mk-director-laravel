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

    public const ERR_UPLOAD_DIMENSIONS = 'ERR_UPLOAD_DIMENSIONS';

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
        // El mensaje lo lee la persona que eligió el archivo: sin nombres internos
        // (el perfil y el mime quedan en el log de quien depure, no en pantalla).
        return new self('Ese tipo de archivo no se puede subir acá.', self::ERR_UPLOAD_TYPE, 422);
    }

    public static function tooLarge(string $profile, int $maxBytes): self
    {
        $mb = rtrim(rtrim(number_format($maxBytes / 1_048_576, 1, ',', ''), '0'), ',');

        return new self("El archivo supera el máximo de {$mb} MB.", self::ERR_UPLOAD_TOO_LARGE, 422);
    }

    /** Lo GUARDADO no es lo que se declaró: su firma no es ninguno de los tipos del perfil. */
    public static function storedType(string $profile): self
    {
        return new self('El contenido del archivo no es del tipo que dice ser: no se puede subir.', self::ERR_UPLOAD_TYPE, 422);
    }

    public static function unreadableImage(): self
    {
        return new self('No se pudo leer la imagen: no se encontraron su ancho y su alto.', self::ERR_UPLOAD_TYPE, 422);
    }

    public static function dimensions(string $profile, int $maxMegapixels): self
    {
        return new self("La imagen es demasiado grande: supera los {$maxMegapixels} megapíxeles.", self::ERR_UPLOAD_DIMENSIONS, 422);
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
