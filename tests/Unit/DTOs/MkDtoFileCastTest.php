<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\DTOs;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Mk\Director\DTOs\MkDTO;
use Mk\Director\Tests\TestCase;

uses(TestCase::class);

class FileProbeDto extends MkDTO
{
    public ?string $name = null;

    public ?string $avatar = null;
}

/**
 * Regresión: un archivo subido no puede convertirse a string en silencio.
 *
 * EL DAÑO QUE EVITA
 * -----------------
 * `gettype()` de un `UploadedFile` es `'object'`, así que caía al `default` del
 * match y `(string) $file` devuelve la RUTA DEL TEMPORAL de PHP
 * (`/private/var/tmp/phpXXXX`). PHP borra ese temporal al terminar el request,
 * así que la fila quedaba apuntando a un archivo inexistente — para siempre.
 *
 * No es hipotético: dos filas de admins de RETO quedaron con ese valor el
 * 2026-07-13 (antes del fix de wiring del FileStoragePlugin). Una semana
 * después se veían como avatares rotos en la lista, y hubo que hacer
 * arqueología sobre la data para entender de dónde salía la ruta.
 *
 * Es la misma familia que la coerción de enums (ver MkEnumCoercion): un cast
 * implícito que convierte lo que no debería convertirse y produce un dato
 * válido en forma pero equivocado en contenido.
 */
test('EL BUG: un UploadedFile NO se persiste como la ruta de su temporal', function () {
    $file = UploadedFile::fake()->image('avatar.png');

    expect(fn () => FileProbeDto::fromArray(['name' => 'probe', 'avatar' => $file]))
        ->toThrow(InvalidArgumentException::class);
});

test('el error explica QUÉ hacer, no solo que algo falló', function () {
    // Un mensaje que solo dice "tipo inválido" manda al dev a leer el paquete.
    // Éste tiene que nombrar la causa y la solución.
    $file = UploadedFile::fake()->image('avatar.png');

    try {
        FileProbeDto::fromArray(['avatar' => $file]);
        expect(false)->toBeTrue('debió lanzar');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())
            ->toContain('FileStoragePlugin')
            ->toContain('temporal');
    }
});

test('un path ya almacenado (lo que emite el plugin) pasa normal', function () {
    // Cuando FileStoragePlugin corre, lo que llega al DTO ya es un string.
    $dto = FileProbeDto::fromArray([
        'name' => 'probe',
        'avatar' => 'uploads/admin/abc123.png',
    ]);

    expect($dto->avatar)->toBe('uploads/admin/abc123.png');
});

test('los demás casts a string siguen funcionando', function () {
    expect(FileProbeDto::fromArray(['name' => 'texto'])->name)->toBe('texto');
    expect(FileProbeDto::fromArray(['name' => 42])->name)->toBe('42');
    expect(FileProbeDto::fromArray(['name' => true])->name)->toBe('1');
});
