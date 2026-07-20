<?php

declare(strict_types=1);

namespace Mk\Director\Utils;

use BackedEnum;
use ReflectionEnum;

/**
 * MkEnumCoercion — adapta un valor crudo de HTTP al backing type de un enum.
 *
 * POR QUÉ EXISTE
 * --------------
 * En `multipart/form-data` **todo llega como string**. No es un capricho de
 * ningún cliente: el formato no tiene tipos, así que un `status` entero viaja
 * como `'1'`. La MISMA pantalla manda JSON (con enteros de verdad) cuando no
 * hay archivo adjunto y multipart cuando el usuario sube una foto.
 *
 * Resultado: `AdminStatus::from('1')` explota sobre un enum int-backed, y el
 * bug aparece SOLO al subir una imagen. Un caso reportado desde RETO el
 * 2026-07-20 con este síntoma exacto: crear y editar andaban, adjuntar foto
 * tiraba "Valor inválido para el campo (Enum ...): '1'. Valores válidos:
 * 1, 2, 3, 4" — un mensaje que se lee como contradicción porque el valor
 * enviado ESTÁ en la lista; lo que no coincide es el tipo.
 *
 * El agujero era latente desde antes: mientras los enums del paquete fueron
 * string-backed, un `'active'` de multipart matcheaba igual. El revert a
 * int-backed (ver `Mk\Director\Auth\Enums\ScopeStatus`) lo destapó.
 *
 * Adaptar el borde HTTP a los tipos del dominio es responsabilidad del
 * paquete, no de cada consumer.
 *
 * QUÉ NO HACE
 * -----------
 * NO es un "acepta cualquier cosa". Sólo convierte lo que representa
 * EXACTAMENTE un entero (`'1'`, `' 2 '`, `'-3'`). Un `'1.5'`, un `'1abc'` o
 * un `'abc'` pasan intactos para que el `from()` falle con su mensaje de
 * siempre: coercionarlos sería adivinar la intención del usuario.
 */
final class MkEnumCoercion
{
    /**
     * Devuelve el valor listo para `{$enumClass}::from()`.
     *
     * Si no hay nada que adaptar (o no se puede hacerlo sin adivinar),
     * devuelve el valor original sin tocar.
     *
     * @param  class-string<BackedEnum>  $enumClass
     */
    public static function coerce(mixed $value, string $enumClass): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value;
        }

        $backingType = self::backingTypeOf($enumClass);

        if ($backingType === 'int' && is_string($value)) {
            // filter_var es estricto donde `(int)` es permisivo: `(int) '1abc'`
            // da 1 en silencio, que es justo el tipo de coerción que convierte
            // un typo del usuario en un dato válido pero equivocado.
            $asInt = filter_var(trim($value), FILTER_VALIDATE_INT);

            return $asInt === false ? $value : $asInt;
        }

        if ($backingType === 'string' && is_int($value)) {
            return (string) $value;
        }

        return $value;
    }

    /**
     * `'int'`, `'string'`, o null si la clase no es un enum con backing type.
     *
     * @param  class-string  $enumClass
     */
    private static function backingTypeOf(string $enumClass): ?string
    {
        if (! enum_exists($enumClass)) {
            return null;
        }

        $backingType = (new ReflectionEnum($enumClass))->getBackingType();

        return $backingType === null ? null : (string) $backingType;
    }
}
