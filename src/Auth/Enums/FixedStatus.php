<?php

declare(strict_types=1);

namespace Mk\Director\Auth\Enums;

/**
 * FixedStatus — marca si una fila de `roles`/`abilities` es de sistema
 * (Fixed) y por lo tanto no debe editarse/eliminarse desde el CRUD, o si
 * es una fila normal editable (Editable).
 *
 * Backing NUMÉRICO (int) por convención de la org (mismo patrón que
 * {{ModuleName}}Status / DebtStatus / ApprovedStatus). Se guarda como
 * TINYINT en la columna `is_fixed` (default 0 = Editable).
 *
 * `super-admin` (role) y `*` (ability wildcard) se siembran con Fixed.
 */
enum FixedStatus: int
{
    case Editable = 0;
    case Fixed = 1;

    public static function default(): self
    {
        return self::Editable;
    }

    /**
     * @return array<int, int>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): int => $s->value, self::cases());
    }

    public function isFixed(): bool
    {
        return $this === self::Fixed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fijo',
            self::Editable => 'Editable',
        };
    }
}
