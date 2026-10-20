<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

/**
 * Bytes de archivos armados a mano, con las medidas que diga el test y sin
 * generar la imagen entera: una cabecera de 6000×5000 pesa unos bytes, no los
 * 120 MB que GD necesitaría para dibujarla.
 */
final class FileBytes
{
    /** Firma + chunk IHDR: lo que lee `getimagesizefromstring`. */
    public static function png(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

        return "\x89PNG\r\n\x1A\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    /**
     * SOI, `$exifBytes` de segmentos APP1 (como el EXIF de un teléfono) y el
     * SOF0 con las medidas. Con EXIF grande el SOF queda más allá de los 64 KB.
     */
    public static function jpeg(int $width, int $height, int $exifBytes = 0): string
    {
        $bytes = "\xFF\xD8";

        while ($exifBytes > 0) {
            $chunk = min($exifBytes, 65533); // un segmento mide hasta 65535 con su largo
            $bytes .= "\xFF\xE1".pack('n', $chunk + 2).str_repeat("\x00", $chunk);
            $exifBytes -= $chunk;
        }

        return $bytes."\xFF\xC0".pack('n', 17)."\x08".pack('nn', $height, $width)
            ."\x03\x01\x22\x00\x02\x11\x01\x03\x11\x01\xFF\xD9";
    }

    /** Un ejecutable de Windows: arranca con `MZ`. */
    public static function exe(): string
    {
        return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 48);
    }
}
