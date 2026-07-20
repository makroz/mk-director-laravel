<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\DTOs;

use Illuminate\Database\Eloquent\Model;
use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\DTOs\DTOFactory;
use Mk\Director\DTOs\MkDTO;
use Mk\Director\Tests\TestCase;

uses(TestCase::class);

class MultipartProbeDto extends MkDTO
{
    public ?string $name = null;

    public ?ScopeStatus $status = null;
}

/**
 * Regresión del bug reportado desde RETO el 2026-07-20: adjuntar una foto
 * rompía el alta/edición de admins y members.
 *
 * POR QUÉ ESTE ARCHIVO EXISTE APARTE de MkEnumCoercionTest
 * -------------------------------------------------------
 * Aquel prueba la utilidad AISLADA. Éste prueba que los DTOs efectivamente
 * LA USEN, que es una afirmación distinta — y que hacía falta: al preparar
 * este fix se descartó por accidente el cableado en ambos DTOs y la suite
 * entera siguió en verde, porque ningún test cubría la unión de las dos
 * piezas. Un test que valida el componente pero no su conexión deja pasar
 * exactamente el bug que se estaba arreglando.
 */
test('DTOFactory acepta el status que llega como string desde multipart', function () {
    // `'1'` con comillas es LITERALMENTE lo que manda un form-data.
    $out = DTOFactory::makeFromArray(
        ['status' => '1'],
        ProbeModelForEnumCoercion::class,
        null,
        ['status' => ScopeStatus::class]
    );

    expect($out['status'])->toBe(ScopeStatus::Active->value);
});

test('DTOFactory sigue rechazando un value que no existe en el enum', function () {
    // Coercionar el TIPO no puede volverse aceptar cualquier VALOR.
    expect(fn () => DTOFactory::makeFromArray(
        ['status' => '99'],
        ProbeModelForEnumCoercion::class,
        null,
        ['status' => ScopeStatus::class]
    ))->toThrow(\InvalidArgumentException::class);
});

test('MkDTO hidrata el enum desde el string de multipart', function () {
    $dto = MultipartProbeDto::fromArray(['name' => 'probe', 'status' => '3']);

    expect($dto->status)->toBe(ScopeStatus::Blocked);
});

test('MkDTO sigue rechazando un value inexistente', function () {
    expect(fn () => MultipartProbeDto::fromArray(['status' => '99']))
        ->toThrow(\InvalidArgumentException::class);
});

/**
 * Modelo mínimo: `DTOFactory::makeAuto()` sólo necesita `getFillable()` y
 * `getCasts()`. No se instancia Eloquent completo para no arrastrar una
 * conexión a este test.
 */
class ProbeModelForEnumCoercion extends Model
{
    protected $fillable = ['status'];
}
