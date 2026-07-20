<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Utils;

use Mk\Director\Auth\Enums\ScopeStatus;
use Mk\Director\Tests\TestCase;
use Mk\Director\Utils\MkEnumCoercion;

uses(TestCase::class);

enum CoercionStringEnum: string
{
    case Foo = 'foo';
    case One = '1';
}

/**
 * Regresión del bug reportado desde RETO el 2026-07-20.
 *
 * Síntoma: crear y editar un admin andaban, pero ADJUNTAR UNA FOTO tiraba
 * "Valor inválido para el campo (Enum AdminStatus): '1'. Valores válidos:
 * 1, 2, 3, 4" — un mensaje que se lee como contradicción, porque el valor
 * enviado está en la lista. Lo que no coincidía era el TIPO.
 *
 * Causa: `multipart/form-data` no tiene tipos, así que todo llega como string.
 * La misma pantalla manda JSON (enteros de verdad) cuando no hay archivo, y
 * multipart cuando sí — por eso el bug aparecía sólo al subir la imagen.
 *
 * El agujero era latente desde antes: mientras los enums fueron string-backed,
 * un `'active'` de multipart matcheaba igual. El revert a int-backed lo
 * destapó, y sólo en el camino con archivo adjunto.
 */
test('EL BUG: un entero que llega como string de multipart se adapta', function () {
    expect(MkEnumCoercion::coerce('1', ScopeStatus::class))->toBe(1);
    expect(ScopeStatus::from(MkEnumCoercion::coerce('3', ScopeStatus::class)))
        ->toBe(ScopeStatus::Blocked);
});

test('tolera el espacio en blanco que mete un form', function () {
    expect(MkEnumCoercion::coerce(' 2 ', ScopeStatus::class))->toBe(2);
});

test('un entero de verdad pasa intacto', function () {
    expect(MkEnumCoercion::coerce(4, ScopeStatus::class))->toBe(4);
});

test('NO adivina: lo que no es exactamente un entero pasa sin tocar', function () {
    // La diferencia con un `(int)` pelado, que es lo que hace peligroso al
    // cast implícito: `(int) '1abc'` da 1 EN SILENCIO, convirtiendo el typo de
    // un usuario en un valor válido pero equivocado. Acá tiene que seguir de
    // largo para que el `from()` falle con su mensaje.
    expect(MkEnumCoercion::coerce('1abc', ScopeStatus::class))->toBe('1abc');
    expect(MkEnumCoercion::coerce('1.5', ScopeStatus::class))->toBe('1.5');
    expect(MkEnumCoercion::coerce('abc', ScopeStatus::class))->toBe('abc');
    expect(MkEnumCoercion::coerce('', ScopeStatus::class))->toBe('');
});

test('un valor fuera de rango sigue fallando (no se inventa un case)', function () {
    // Coercionar el TIPO no es validar el VALOR. El 99 se convierte a int y
    // igual explota, que es lo correcto.
    expect(MkEnumCoercion::coerce('99', ScopeStatus::class))->toBe(99);
    expect(fn () => ScopeStatus::from(MkEnumCoercion::coerce('99', ScopeStatus::class)))
        ->toThrow(\ValueError::class);
});

test('un enum ya construido se devuelve tal cual', function () {
    expect(MkEnumCoercion::coerce(ScopeStatus::Pending, ScopeStatus::class))
        ->toBe(ScopeStatus::Pending);
});

test('con un enum STRING-backed la coerción va al revés', function () {
    // Simétrico: un int que llega a un enum string-backed también hay que
    // adaptarlo. Los consumers viejos del paquete siguen teniendo enums string.
    expect(MkEnumCoercion::coerce(1, CoercionStringEnum::class))->toBe('1');
    expect(CoercionStringEnum::from(MkEnumCoercion::coerce(1, CoercionStringEnum::class)))
        ->toBe(CoercionStringEnum::One);

    // Y un string se queda como está.
    expect(MkEnumCoercion::coerce('foo', CoercionStringEnum::class))->toBe('foo');
});

test('una clase que no es enum no rompe la coerción', function () {
    expect(MkEnumCoercion::coerce('1', \stdClass::class))->toBe('1');
});
