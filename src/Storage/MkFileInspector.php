<?php

declare(strict_types=1);

namespace Mk\Director\Storage;

/**
 * Qué es DE VERDAD un archivo, mirando sus bytes: el tipo por la firma de los
 * primeros bytes y, si es imagen, ancho y alto por su cabecera.
 *
 * 🔴 EL MIME QUE DECLARA EL CLIENTE NO VALE: lo elige quien sube. Un `.exe`
 * renombrado a `foto.png` y declarado `image/png` pasa cualquier chequeo de
 * nombre o de header; sus primeros bytes (`MZ`) no.
 *
 * Trabaja sobre un string y nunca sobre el archivo entero: en una subida
 * directa los bytes viven en el storage y se piden con un GET con Range
 * ({@see DirectUpload\MkDirectUploadDriver::head()}).
 */
final class MkFileInspector
{
    /**
     * El tipo real por los primeros bytes (con 16 alcanza), o null si no es
     * ninguno de los que el paquete sabe guardar.
     *
     * Video: una caja `ftyp` en el byte 4 es MP4, salvo la marca `qt  ` que es
     * QuickTime; un QuickTime viejo arranca con `moov`/`mdat`/`wide`/`free` en
     * vez de `ftyp`. WebM es la firma EBML `1A 45 DF A3`.
     *
     * Audio: un tag `ID3` o un frame MPEG (11 bits de sincronía) es MP3, salvo
     * la capa `00`, que es un frame ADTS (AAC suelto); `RIFF…WAVE` es WAV,
     * `OggS` es Ogg, y un `ftyp` con marca `M4A `/`M4B `/`M4P ` es M4A.
     * ⚠️ Muchos teléfonos graban el M4A con una marca genérica (`isom`,
     * `mp42`): esos salen `video/mp4`, porque los bytes no dicen más. Un perfil
     * de audio que los acepte lista `video/mp4` con la extensión `m4a`.
     */
    public function sniff(string $head): ?string
    {
        $box = substr($head, 4, 4);
        $sync = strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0;

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'image/gif',
            str_starts_with($head, '%PDF-') => 'application/pdf',
            $box === 'ftyp' && in_array(substr($head, 8, 4), ['M4A ', 'M4B ', 'M4P '], true) => 'audio/mp4',
            $box === 'ftyp' && substr($head, 8, 4) === 'qt  ' => 'video/quicktime',
            $box === 'ftyp' => 'video/mp4',
            in_array($box, ['moov', 'mdat', 'wide', 'free'], true) => 'video/quicktime',
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'video/webm',
            str_starts_with($head, 'ID3') => 'audio/mpeg',
            $sync && (ord($head[1]) & 0x06) === 0 => 'audio/aac',
            $sync => 'audio/mpeg',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE' => 'audio/wav',
            str_starts_with($head, 'OggS') => 'audio/ogg',
            default => null,
        };
    }

    /**
     * Ancho y alto desde la CABECERA de una imagen (no hace falta el archivo
     * entero), o null si esos bytes no alcanzan o no son una imagen legible.
     *
     * Un JPEG con un bloque EXIF grande puede dejar el marcador SOF (donde
     * están las medidas) más allá de los primeros 64 KB: quien llama decide si
     * pide más bytes.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $headerBytes): ?array
    {
        if ($headerBytes === '') {
            return null;
        }

        // `@`: con bytes cortados o basura, getimagesizefromstring emite un
        // warning además de devolver false; acá false es la respuesta.
        $size = @getimagesizefromstring($headerBytes);

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        return ['width' => (int) $size[0], 'height' => (int) $size[1]];
    }
}
