<?php

declare(strict_types=1);

namespace App\Modules\SpecShop\Enums;

/**
 * Whether a product is sold.
 *
 * Inactive is not deleted.
 */
enum ProductStatus: int
{
    case Active = 1;
    case Inactive = 2;

    public static function default(): self
    {
        return self::Active;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
        };
    }
}
