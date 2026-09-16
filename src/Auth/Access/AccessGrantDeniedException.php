<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Rechazo de {@see AccessGrantGuard}. Laravel llama a `render()` y responde 403
 * con el mismo sobre que `BaseController::sendError()`, así que el front lee el
 * motivo en `__extraData.code` sin un catch por endpoint.
 */
class AccessGrantDeniedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => $this->getMessage(),
            'data' => null,
            '__extraData' => ['code' => $this->errorCode],
            'debugMsg' => [],
        ], 403);
    }
}
