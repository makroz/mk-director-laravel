<?php

declare(strict_types=1);

use Mk\Director\Storage\MkFileInspector;
use Mk\Director\Tests\Concerns\FileBytes;

/**
 * `MkFileInspector`: el tipo REAL por la firma y las medidas por la cabecera.
 * Es lo que decide qué entra en una subida directa, donde el MIME lo declara el
 * cliente.
 */
test('sniffs each type the package stores from its first bytes', function (string $head, string $mime) {
    expect((new MkFileInspector)->sniff($head))->toBe($mime);
})->with([
    'jpeg' => [FileBytes::jpeg(10, 10), 'image/jpeg'],
    'png' => [FileBytes::png(10, 10), 'image/png'],
    'webp' => ["RIFF\x24\x00\x00\x00WEBPVP8 ", 'image/webp'],
    'gif87a' => ['GIF87a'."\x0A\x00\x0A\x00", 'image/gif'],
    'gif89a' => ['GIF89a'."\x0A\x00\x0A\x00", 'image/gif'],
    'pdf' => ["%PDF-1.7\n%\xE2\xE3", 'application/pdf'],
    'mp4 (isom)' => ["\x00\x00\x00\x20ftypisom\x00\x00\x02\x00", 'video/mp4'],
    'mp4 (mp42)' => ["\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00", 'video/mp4'],
    'quicktime (ftyp qt)' => ["\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00", 'video/quicktime'],
    'quicktime viejo (moov primero)' => ["\x00\x00\x00\x6Cmoov\x00\x00\x00\x00", 'video/quicktime'],
    'webm' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81", 'video/webm'],
    'mp3 (tag ID3)' => ["ID3\x04\x00\x00\x00\x00\x00\x22TSSE", 'audio/mpeg'],
    'mp3 (frame sin tag)' => ["\xFF\xFB\x90\x64\x00\x00\x00\x00", 'audio/mpeg'],
    'aac (frame ADTS)' => ["\xFF\xF1\x50\x80\x02\x1F\xFC\x21", 'audio/aac'],
    'm4a (marca M4A)' => ["\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00", 'audio/mp4'],
    'wav' => ["RIFF\x24\x00\x00\x00WAVEfmt ", 'audio/wav'],
    'ogg' => ["OggS\x00\x02\x00\x00\x00\x00\x00\x00", 'audio/ogg'],
]);

test('a renamed executable or unknown bytes are not any type', function (string $head) {
    expect((new MkFileInspector)->sniff($head))->toBeNull();
})->with([
    'exe' => [FileBytes::exe()],
    'zip' => ["PK\x03\x04\x14\x00\x00\x00"],
    'html' => ['<!doctype html><script>'],
    'RIFF que no es WebP ni WAV (avi)' => ["RIFF\x24\x00\x00\x00AVI LIST"],
    'vacío' => [''],
]);

test('reads the dimensions from the header bytes only', function () {
    $inspector = new MkFileInspector;

    // Sólo la cabecera: ni un píxel. 6000×5000 sin dibujar 30 megapíxeles.
    expect($inspector->dimensions(FileBytes::png(6000, 5000)))->toBe(['width' => 6000, 'height' => 5000])
        ->and($inspector->dimensions(FileBytes::jpeg(1200, 800)))->toBe(['width' => 1200, 'height' => 800]);
});

test('dimensions are null when the bytes do not reach the header or are not an image', function () {
    $inspector = new MkFileInspector;
    $bigExif = FileBytes::jpeg(4000, 3000, exifBytes: 70000);

    expect($inspector->dimensions(substr($bigExif, 0, 65536)))->toBeNull()
        ->and($inspector->dimensions($bigExif))->toBe(['width' => 4000, 'height' => 3000])
        ->and($inspector->dimensions(FileBytes::exe()))->toBeNull()
        ->and($inspector->dimensions(''))->toBeNull();
});
