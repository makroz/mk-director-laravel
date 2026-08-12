<?php

declare(strict_types=1);

namespace Mk\Director\Export\Support;

use BackedEnum;
use UnitEnum;
use ValueError;

/**
 * Resuelve un valor de enum al texto que ve el usuario.
 *
 * Reemplaza el `STATUS_LABELS = [1 => 'Activo', 2 => 'Inactivo']` duplicado en
 * cada reporte — que es el mismo mapa escrito N veces, desincronizándose de a
 * poco cada vez que alguien agrega un estado.
 *
 * Prioridad al resolver el texto de un case: `label()` → `value` → `name`.
 * `label()` primero porque es el único que el proyecto controla; los otros dos
 * son el identificador técnico y se ven como tales.
 */
final class EnumResolver
{
    /**
     * Resuelve usando una clase de enum concreta. El valor puede ser la
     * instancia del enum o el int/string guardado en la base.
     */
    public static function resolve(mixed $value, string $enumClass): string
    {
        if ($value === null) {
            return TextFormat::PLACEHOLDER;
        }

        if ($value instanceof $enumClass) {
            return self::textoDe($value);
        }

        if (is_int($value) || is_string($value)) {
            try {
                return self::textoDe($enumClass::from($value));
            } catch (ValueError) {
                // ⚠️ Un valor que no matchea ningún case sale CRUDO, no vacío
                // ni con excepción. Es un dato viejo o una migración a medias,
                // y verlo en el reporte es lo que permite detectarlo.
                return (string) $value;
            }
        }

        return (string) $value;
    }

    /**
     * Resuelve un enum que ya viene casteado por Eloquent, sin saber su clase.
     */
    public static function resolveGeneric(mixed $value): string
    {
        if ($value === null) {
            return TextFormat::PLACEHOLDER;
        }

        if ($value instanceof BackedEnum || $value instanceof UnitEnum) {
            return self::textoDe($value);
        }

        return (string) $value;
    }

    private static function textoDe(BackedEnum|UnitEnum $case): string
    {
        if (method_exists($case, 'label')) {
            return (string) $case->label();
        }

        if ($case instanceof BackedEnum) {
            return (string) $case->value;
        }

        return $case->name;
    }
}
