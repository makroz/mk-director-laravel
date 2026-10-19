<?php

declare(strict_types=1);

namespace Mk\Director\Enums;

/**
 * MkUploadStatus — en qué punto está una subida directa (`mk_uploads`).
 *
 * Int-backed desde 1, regla de la agencia (ver {@see MkMediaKind}).
 *
 *  - Started   → hay multipart abierto y links firmados; el navegador sube las partes.
 *  - Completed → las partes se unieron: el objeto existe, todavía sin dueño en `mk_media`.
 *  - Attached  → un `attachUpload()` lo convirtió en media. No se reclama dos veces.
 *  - Aborted   → cancelada: el multipart o el objeto se borraron.
 */
enum MkUploadStatus: int
{
    case Started = 1;
    case Completed = 2;
    case Attached = 3;
    case Aborted = 4;

    /** @return array<int, int> */
    public static function values(): array
    {
        return array_map(static fn (self $case): int => $case->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Started => 'Subiendo',
            self::Completed => 'Subida',
            self::Attached => 'Adjuntada',
            self::Aborted => 'Cancelada',
        };
    }
}
